<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Contracts\FlashStoreInterface;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Product\Application\EstimateVendorShare;
use Tecteb\Marketplace\Modules\Product\Application\ManageProducts;
use Tecteb\Marketplace\Modules\Product\Application\ManageVariations;
use Tecteb\Marketplace\Modules\Product\Application\VariationRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductCsv;
use Tecteb\Marketplace\Modules\Product\Application\ProductImageLibraryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductPublishPolicy;
use Tecteb\Marketplace\Modules\Product\Application\ProductDraftStoreInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ProductImagePolicy;
use Tecteb\Marketplace\Modules\Product\Application\ProductRevisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\SpecTemplateRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductBulkPreviewView;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductCsvView;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductFormView;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductListView;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffArea;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffLevel;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaOutcome;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaView;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;

/**
 * /vendor/products/ — the list, the four-step form, and CSV.
 *
 * The router has already established that the visitor is signed in, has the
 * vendor capability, passed the nonce and acts in SOME shop. This class adds
 * the two things only it can know: which shop the row belongs to, and whether
 * this person may edit products in it.
 *
 * Failed saves put the submitted values in the flash store and redirect, so
 * the form comes back filled in — AC-UI: «تکمیل چهارمرحله‌ای محصول با خطای
 * برگشتی داده را حفظ می‌کند» — without ever re-showing a POST body.
 */
final class ProductArea
{
    public const SLUG = 'products';

    /** @var list<string> the POST actions this page owns */
    public const ACTIONS = [
        'save_product', 'submit_product', 'archive_product', 'restore_product', 'bulk_products',
        'export_products', 'import_products', 'apply_products_csv',
        'save_attribute', 'delete_attribute', 'save_variation', 'delete_variation', 'save_variation_stock',
    ];

    private const FORM_TTL = 600;

    /** Where the file chooser is; a failed upload lands back on it. */
    private const GALLERY_STEP = '1';

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    // ------------------------------------------------------------- rendering

    public function render(VendorAreaView $view): string
    {
        $vendorUserId = $this->storeFor($view->userId);
        if ($vendorUserId === null) {
            return '';                                  // the router already redirected
        }
        $request = $view->request;
        $mayEdit = $this->access()->can($view->userId, $vendorUserId, StaffArea::Product, StaffLevel::Edit);

        $csv = $this->flash()->take($this->csvReportKey($view->userId));
        if (is_array($csv) && isset($csv['report']) && is_array($csv['report'])) {
            /** @var array{rows:list<array{line:int,sku:string,title:string,action:string,code:string,context:array<string,scalar|null>}>,created:int,updated:int,skipped:int} $report */
            $report = $csv['report'];
            return ProductCsvView::render($report, (bool) ($csv['applied'] ?? false), $view->urls, $view->nonceField);
        }

        // Taken, not read: a preview that survived a refresh would describe
        // rows as they were minutes ago, and the vendor would confirm a list
        // that is no longer true. One look, then it is gone.
        $held = $this->flash()->take($this->bulkPreviewKey($view->userId));
        if (is_array($held) && isset($held['preview']) && is_array($held['preview'])) {
            /** @var array{action:string,rows:list<array{product_id:int,ok:bool,code:string,title:string,from:string,to:string}>,ok:int,failed:int} $preview */
            $preview = $held['preview'];
            return ProductBulkPreviewView::render($preview, $view->urls, $view->nonceField);
        }

        $requested = $request->queryText('product');
        if ($requested !== '') {
            return $this->form($view, $vendorUserId, $requested === 'new' ? 0 : (int) $requested, $mayEdit);
        }
        return $this->list($view, $vendorUserId, $mayEdit);
    }

    private function list(VendorAreaView $view, int $vendorUserId, bool $mayEdit): string
    {
        $request = $view->request;
        $status = ProductStatus::tryFrom($request->queryKey('status'));
        $page = max(1, $request->queryInt('paged'));
        $search = $request->queryText('q');
        $products = $this->products()->forVendor(
            $vendorUserId,
            $status,
            ProductListView::PER_PAGE,
            ($page - 1) * ProductListView::PER_PAGE,
            $search
        );
        return ProductListView::render(
            $products,
            $this->products()->countsByStatus($vendorUserId),
            $status?->value ?? '',
            $page,
            $this->products()->countForVendor($vendorUserId, $status, $search),
            $view->urls,
            $view->nonceField,
            $view->notice,
            $mayEdit,
            $this->publishing()->mayPublishDirectly($vendorUserId),
            $search
        );
    }

    private function form(VendorAreaView $view, int $vendorUserId, int $productId, bool $mayEdit): string
    {
        $product = $productId > 0 ? $this->products()->findOwned($productId, $vendorUserId) : null;
        if ($productId > 0 && $product === null) {
            // Another shop's id, or one that never existed: the same answer,
            // so the list cannot be probed for what exists elsewhere.
            return ProductListView::render(
                [],
                $this->products()->countsByStatus($vendorUserId),
                '',
                1,
                0,
                $view->urls,
                $view->nonceField,
                \Tecteb\Marketplace\Modules\Vendor\Presentation\VendorNotice::of('not_found'),
                $mayEdit,
                $this->publishing()->mayPublishDirectly($vendorUserId)
            );
        }

        // A failed save left the vendor's own values here; they win over the
        // stored row, which is what makes the returned form the form they had.
        $carried = $this->flash()->take($this->formKey($view->userId));
        $details = $product?->details ?? new ProductDetails();
        $specs = $product?->specs ?? [];
        $imageIds = $product?->imageIds ?? [];
        $mainImageId = $product?->mainImageId ?? 0;
        if (is_array($carried) && (int) ($carried['product_id'] ?? -1) === $productId) {
            $details = $this->detailsFromArray(is_array($carried['details'] ?? null) ? $carried['details'] : []);
            $specs = is_array($carried['specs'] ?? null) ? array_map('strval', $carried['specs']) : $specs;
            $imageIds = is_array($carried['images'] ?? null) ? array_map('intval', $carried['images']) : $imageIds;
            $mainImageId = (int) ($carried['main_image_id'] ?? $mainImageId);
        }

        // An autosaved draft is OFFERED, never applied: what somebody typed
        // and did not save is a suggestion, and silently loading it over the
        // stored product would hide a real difference between the two. The
        // carried values of a failed save still win, because those are the
        // values of the submission being corrected.
        $draftSavedAt = '';
        if ($productId > 0 && !is_array($carried)) {
            $draft = $this->drafts()->get($view->userId, $productId);
            if ($draft !== null) {
                $draftSavedAt = substr($draft['saved_at'], 11, 5);
            }
        }

        $template = $this->templates()->findByCategory($details->categoryKey);
        $status = $product?->status ?? ProductStatus::Draft;
        $readiness = null;
        $share = null;
        if ($view->request->queryKey('step') === '4' && $product !== null) {
            $readiness = $this->manage()->readiness($product);
            $share = $this->container->get(EstimateVendorShare::class)
                ->forProduct($product, gmdate('Y-m-d'));
        }

        return ProductFormView::render(
            $productId,
            $details,
            $specs,
            $this->galleryFor($imageIds),
            $mainImageId,
            $template,
            $view->request->queryKey('step'),
            $status,
            $this->categories(),
            $view->urls,
            $view->nonceField,
            $view->notice,
            $readiness,
            $share,
            $this->publishing()->mayPublishDirectly($vendorUserId),
            $product !== null && $this->revisions()->pendingFor($product->id) !== null,
            $this->variableData($productId, $details->type),
            $product?->rowVersion ?? '',
            $draftSavedAt,
            $mayEdit ? admin_url('admin-ajax.php') : '',
            ProductAutosave::ACTION,
            $mayEdit ? wp_create_nonce(ProductAutosave::NONCE) : '',
            $this->imagePolicy()->maxBytes(),
            // Passed only when the notice is one of the two that asks the
            // vendor to compare. `$product` is the row as it is NOW — re-read
            // on this request — while `$details` above is what they typed.
            $view->notice !== null
                && in_array($view->notice->code, ['revision_missing', 'stale_revision'], true)
                    ? $product?->details
                    : null
        );
    }

    // --------------------------------------------------------------- writes

    public function handle(string $action, Request $request, int $userId, VendorUrls $urls): ?VendorAreaOutcome
    {
        $vendorUserId = $this->storeFor($userId);
        if ($vendorUserId === null) {
            return new VendorAreaOutcome('not_a_vendor', $urls->dashboard());
        }
        return match ($action) {
            'save_product' => $this->save($request, $userId, $vendorUserId, $urls),
            'submit_product' => $this->finish(
                $this->manage()->submit($userId, $vendorUserId, $request->postInt('product_id')),
                $urls,
                $request->postInt('product_id')
            ),
            'archive_product' => $this->finish(
                $this->manage()->archive($userId, $vendorUserId, $request->postInt('product_id')),
                $urls,
                0
            ),
            'restore_product' => $this->finish(
                $this->manage()->restore($userId, $vendorUserId, $request->postInt('product_id')),
                $urls,
                0
            ),
            'bulk_products' => $this->bulk($request, $userId, $vendorUserId, $urls),
            'preview_bulk_products' => $this->previewBulk($request, $userId, $vendorUserId, $urls),
            'export_products' => $this->export($userId, $vendorUserId, $urls),
            'import_products' => $this->preview($request, $userId, $vendorUserId, $urls),
            'apply_products_csv' => $this->apply($userId, $vendorUserId, $urls),
            'save_attribute' => $this->onVariation($request, $urls, fn (): OperationResult => $this->variationService()->saveAttribute(
                $userId,
                $vendorUserId,
                $request->postInt('product_id'),
                $request->postText('attr_key'),
                $request->postText('attr_label'),
                $this->linesOf($request->postTextarea('attr_options'))
            )),
            'delete_attribute' => $this->onVariation($request, $urls, fn (): OperationResult => $this->variationService()->deleteAttribute(
                $userId,
                $vendorUserId,
                $request->postInt('product_id'),
                $request->postInt('attribute_id')
            )),
            'save_variation' => $this->onVariation($request, $urls, fn (): OperationResult => $this->variationService()->saveVariation(
                $userId,
                $vendorUserId,
                $request->postInt('product_id'),
                $request->postMap('variant'),
                $this->number($request->postText('var_price')),
                $this->optionalNumber($request->postText('var_sale_price')),
                $request->postText('var_sku'),
                $this->number($request->postText('var_stock')),
                0,
                $request->postChecked('var_enabled'),
                $request->file('variation_image')
            )),
            'delete_variation' => $this->onVariation($request, $urls, fn (): OperationResult => $this->variationService()->deleteVariation(
                $userId,
                $vendorUserId,
                $request->postInt('product_id'),
                $request->postInt('variation_id')
            )),
            'save_variation_stock' => $this->onVariation($request, $urls, fn (): OperationResult => $this->variationService()->updateVariationStock(
                $userId,
                $vendorUserId,
                $request->postInt('product_id'),
                $request->postInt('variation_id'),
                $this->number($request->postText('stock'))
            )),
            default => null,
        };
    }

    private function save(Request $request, int $userId, int $vendorUserId, VendorUrls $urls): VendorAreaOutcome
    {
        $productId = $request->postInt('product_id');
        $step = $request->postKey('step');
        $details = $this->detailsFromPost($request);
        $specs = $request->postMap('spec');
        [$imageIds, $mainImageId, $imageError] = $this->imagesFromPost($request, $userId, $vendorUserId);
        $imageIds = $this->reorder($imageIds, $request->postText('move_image'));

        $result = $this->manage()->save(
            $userId,
            $vendorUserId,
            $productId,
            $details,
            $specs,
            $imageIds,
            $mainImageId,
            $request->postText('revision')
        );
        if (!$result->ok) {
            $this->flash()->put($this->formKey($userId), [
                'product_id' => $productId,
                'details' => $this->detailsToArray($details),
                'specs' => $specs,
                'images' => $imageIds,
                'main_image_id' => $mainImageId,
            ], self::FORM_TTL);
            return new VendorAreaOutcome($result->code, $this->stepUrl($urls, $productId, $step), $result->context);
        }
        $savedId = (int) ($result->context['product_id'] ?? $productId);

        // The text saved and the picture did not. Reporting the SAVE as a
        // failure would be a lie and would throw away work that is safely
        // stored; reporting only the save would hide the loss. So the save
        // stands, the notice names the upload, and the vendor is left on the
        // step where the file chooser is — able to pick the file again without
        // retyping anything.
        if ($imageError !== '') {
            return new VendorAreaOutcome(
                $imageError,
                $this->stepUrl($urls, $savedId, self::GALLERY_STEP),
                ['saved' => 1, 'max_mb' => $this->maxImageMb()]
            );
        }
        // The draft has done its job. Cleared HERE and not on a successful
        // autosave: a draft that deleted itself when the form was submitted
        // would throw the work away at exactly the moment a refused save
        // needed it.
        $this->drafts()->forget($userId, $savedId);
        // A reorder is not "done with this step": stay where the gallery is.
        $next = match (true) {
            $request->postText('move_image') !== '' => $step,
            $productId === 0 => '1',
            default => $this->nextStep($step),
        };
        return new VendorAreaOutcome($result->code, $this->stepUrl($urls, $savedId, $next), $result->context);
    }

    /**
     * One action over a selection, with the outcome of every row reported.
     *
     * The counts go in the redirect's context rather than the URL, so a
     * refresh of the landing page does not repeat them as if they had just
     * happened. The refused rows are named: «۳۶ رفت، ۴ نرفت» without saying
     * WHICH four is a message that sends somebody hunting through forty rows.
     */
    private function bulk(Request $request, int $userId, int $vendorUserId, VendorUrls $urls): VendorAreaOutcome
    {
        $ids = array_map('intval', $request->postTextList('selected'));
        $result = $this->manage()->bulk($userId, $vendorUserId, $request->postKey('bulk_action'), $ids);
        if (!$result->ok) {
            return new VendorAreaOutcome($result->code, $urls->products(), $result->context);
        }
        $refused = [];
        foreach ($result->context['rows'] ?? [] as $row) {
            if (!($row['ok'] ?? false)) {
                $refused[(string) $row['product_id']] = (string) $row['code'];
            }
        }
        return new VendorAreaOutcome('bulk_done', $urls->products(), [
            'action' => $result->context['action'] ?? '',
            'ok' => $result->context['ok'] ?? 0,
            'failed' => $result->context['failed'] ?? 0,
            'refused' => $refused,
        ]);
    }

    /**
     * The same selection, judged and not touched.
     *
     * It ends in a redirect like every other POST here, so the preview page is
     * a GET the vendor can reload, leave and come back from without the
     * browser offering to re-send anything.
     */
    private function previewBulk(Request $request, int $userId, int $vendorUserId, VendorUrls $urls): VendorAreaOutcome
    {
        $ids = array_map('intval', $request->postTextList('selected'));
        $result = $this->manage()->previewBulk($userId, $vendorUserId, $request->postKey('bulk_action'), $ids);
        if (!$result->ok) {
            return new VendorAreaOutcome($result->code, $urls->products(), $result->context);
        }
        $this->flash()->put($this->bulkPreviewKey($userId), ['preview' => $result->context], self::FORM_TTL);
        return new VendorAreaOutcome('bulk_previewed', $urls->products(), [
            'action' => $result->context['action'] ?? '',
            'ok' => $result->context['ok'] ?? 0,
            'failed' => $result->context['failed'] ?? 0,
        ]);
    }

    private function finish(OperationResult $result, VendorUrls $urls, int $productId): VendorAreaOutcome
    {
        $target = $productId > 0 && !$result->ok
            ? $this->stepUrl($urls, $productId, '4')
            : $urls->products();
        return new VendorAreaOutcome($result->code, $target, $result->context);
    }

    /**
     * Sends the file and stops. Not a redirect: a download has no next page,
     * and routing it through one would put the CSV in a URL.
     */
    private function export(int $userId, int $vendorUserId, VendorUrls $urls): ?VendorAreaOutcome
    {
        $result = $this->container->get(ProductCsv::class)->export($userId, $vendorUserId);
        if (!$result['ok']) {
            return new VendorAreaOutcome($result['code'], $urls->products());
        }
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="tecteb-products-' . $vendorUserId . '-' . gmdate('Ymd') . '.csv"');
        header('Content-Length: ' . strlen($result['csv']));
        header('X-Content-Type-Options: nosniff');
        echo $result['csv'];
        exit;
    }

    private function preview(Request $request, int $userId, int $vendorUserId, VendorUrls $urls): VendorAreaOutcome
    {
        $file = $request->file('products_csv');
        if ($file->tempPath === '' || !is_readable($file->tempPath)) {
            return new VendorAreaOutcome('csv_empty', $urls->products());
        }
        $csv = (string) file_get_contents($file->tempPath);
        $report = $this->container->get(ProductCsv::class)->import($userId, $vendorUserId, $csv, false);
        if (!$report['ok']) {
            return new VendorAreaOutcome($report['code'], $urls->products());
        }
        // The file itself is held for the one redirect, so pressing «اعمال»
        // re-runs the rows that were previewed rather than asking for the
        // upload again — and it expires on its own if nobody does.
        $this->flash()->put($this->csvSourceKey($userId), ['csv' => $csv], self::FORM_TTL);
        $this->flash()->put($this->csvReportKey($userId), ['report' => $report, 'applied' => false], self::FORM_TTL);
        return new VendorAreaOutcome($report['code'], $urls->products(), [
            'created' => $report['created'],
            'updated' => $report['updated'],
            'skipped' => $report['skipped'],
        ]);
    }

    private function apply(int $userId, int $vendorUserId, VendorUrls $urls): VendorAreaOutcome
    {
        $held = $this->flash()->take($this->csvSourceKey($userId));
        $csv = is_array($held) ? (string) ($held['csv'] ?? '') : '';
        if ($csv === '') {
            return new VendorAreaOutcome('csv_empty', $urls->products());
        }
        $report = $this->container->get(ProductCsv::class)->import($userId, $vendorUserId, $csv, true);
        $this->flash()->put($this->csvReportKey($userId), ['report' => $report, 'applied' => true], self::FORM_TTL);
        return new VendorAreaOutcome($report['code'], $urls->products(), [
            'created' => $report['created'],
            'updated' => $report['updated'],
            'skipped' => $report['skipped'],
        ]);
    }

    /**
     * Every variation write lands back on step 2 of the same product, which
     * is where the panel lives: sending the vendor to the list after they
     * priced one combination of six would be its own small punishment.
     *
     * @param callable():OperationResult $write
     */
    private function onVariation(Request $request, VendorUrls $urls, callable $write): VendorAreaOutcome
    {
        $result = $write();
        return new VendorAreaOutcome(
            $result->code,
            $this->stepUrl($urls, $request->postInt('product_id'), '2'),
            $result->context
        );
    }

    private function variationService(): ManageVariations
    {
        return $this->container->get(ManageVariations::class);
    }

    private function variationRepository(): VariationRepositoryInterface
    {
        return $this->container->get(VariationRepositoryInterface::class);
    }

    /** @return list<string> */
    private function linesOf(string $raw): array
    {
        $lines = preg_split('/\R/', $raw) ?: [];
        return array_values(array_filter(array_map('trim', $lines), static fn (string $l): bool => $l !== ''));
    }

    private function number(string $raw): int
    {
        return (int) round((float) str_replace(
            [',', '٬', ' '],
            '',
            \Tecteb\Marketplace\Core\Support\PersianDigits::toLatin(trim($raw))
        ));
    }

    private function optionalNumber(string $raw): ?int
    {
        return trim($raw) === '' ? null : $this->number($raw);
    }

    // ------------------------------------------------------------- plumbing

    private function detailsFromPost(Request $request): ProductDetails
    {
        $number = fn (string $raw): int => $this->number($raw);
        $optional = fn (string $raw): ?int => $this->optionalNumber($raw);
        $date = static function (string $raw): ?string {
            $raw = trim($raw);
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 ? $raw : null;
        };
        return new ProductDetails(
            $request->postText('title'),
            $request->postKey('type') !== '' ? $request->postKey('type') : 'simple',
            $request->postKey('category'),
            $request->postText('brand'),
            $request->postTextarea('short_description'),
            $number($request->postText('price')),
            $optional($request->postText('sale_price')),
            $date($request->postText('sale_from')),
            $date($request->postText('sale_to')),
            $request->postText('sku'),
            $number($request->postText('stock')),
            max(1, $number($request->postText('min_purchase'))),
            $optional($request->postText('max_purchase')),
            $number($request->postText('weight_grams')),
            $request->postText('dimensions'),
            $request->postText('tax_class')
        );
    }

    /**
     * The gallery as the form left it, plus anything just uploaded, minus what
     * was ticked for removal. Ownership is re-checked in ManageProducts, so a
     * posted id that is not this shop's never reaches the row.
     *
     * @return array{0:list<int>,1:int}
     */
    /**
     * @return array{0:list<int>, 1:int, 2:string} ids, main id, and the code of
     *         an upload that failed — empty when nothing was uploaded or it
     *         worked
     */
    private function imagesFromPost(Request $request, int $userId, int $vendorUserId): array
    {
        $ids = array_map('intval', $request->postTextList('image_ids'));
        $remove = array_map('intval', $request->postTextList('remove_image_ids'));
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0 && !in_array($id, $remove, true)));

        // A failed upload used to vanish. `if ($uploaded->ok)` with no else
        // meant the vendor picked a file, pressed save, watched the save
        // succeed — and found no picture, with nothing anywhere saying why.
        // Silence is the worst of the three possible answers.
        $imageError = '';
        $file = $request->file('product_image');
        if ($file->tempPath !== '' && $file->errorCode !== UPLOAD_ERR_NO_FILE) {
            $uploaded = $this->manage()->uploadImage($userId, $vendorUserId, $file, $request->postText('title'));
            if ($uploaded->ok) {
                $ids[] = (int) $uploaded->context['media_id'];
            } else {
                $imageError = $uploaded->code;
            }
        } elseif ($file->errorCode !== UPLOAD_ERR_OK && $file->errorCode !== UPLOAD_ERR_NO_FILE) {
            // PHP refused it before our code ran — usually `upload_max_filesize`
            // or a half-finished POST — so there is no temp file to hand to
            // `uploadImage()`, and asking it would get «no file», the one thing
            // that did not happen. The policy already tells these error codes
            // apart, so it names this one too: «too large» and «interrupted»
            // call for different second attempts, and a single catch-all code
            // would tell the vendor neither.
            $imageError = $this->imagePolicy()->refuse($file);
        }
        $main = $request->postInt('main_image_id');
        return [$ids, in_array($main, $ids, true) ? $main : ($ids[0] ?? 0), $imageError];
    }

    /**
     * The axes and combinations of a variable product, and the thumbnails
     * their pictures resolve to. Empty for a simple product, so the panel
     * costs nothing where it does not belong.
     *
     * @return array{attributes?:list<\Tecteb\Marketplace\Modules\Product\Domain\ProductAttribute>,variations?:list<\Tecteb\Marketplace\Modules\Product\Domain\ProductVariation>,thumbnails?:array<int,string>}
     */
    private function variableData(int $productId, string $type): array
    {
        if ($type !== \Tecteb\Marketplace\Modules\Product\Domain\ProductType::VARIABLE) {
            return [];
        }
        $variations = $productId > 0 ? $this->variationRepository()->variations($productId) : [];
        $library = $this->container->get(ProductImageLibraryInterface::class);
        $thumbnails = [];
        foreach ($variations as $variation) {
            if ($variation->mediaId > 0 && !isset($thumbnails[$variation->mediaId])) {
                $thumbnails[$variation->mediaId] = $library->thumbnailUrl($variation->mediaId);
            }
        }
        return [
            'attributes' => $productId > 0 ? $this->variationRepository()->attributes($productId) : [],
            'variations' => $variations,
            'thumbnails' => $thumbnails,
        ];
    }

    /**
     * Moves one image a single place, when a reorder button was the thing
     * that submitted the form.
     *
     * The order is then saved by the ordinary save path, so reordering costs
     * no extra request and no extra rule: the gallery is whatever the form
     * posted, in the order it posted it.
     *
     * @param list<int> $imageIds
     * @return list<int>
     */
    private function reorder(array $imageIds, string $instruction): array
    {
        if ($instruction === '' || !str_contains($instruction, ':')) {
            return $imageIds;
        }
        [$direction, $rawId] = explode(':', $instruction, 2);
        $imageId = (int) $rawId;
        $position = array_search($imageId, $imageIds, true);
        if ($position === false) {
            return $imageIds;
        }
        $target = $direction === 'up' ? $position - 1 : $position + 1;
        if ($target < 0 || $target >= count($imageIds)) {
            return $imageIds;
        }
        [$imageIds[$position], $imageIds[$target]] = [$imageIds[$target], $imageIds[$position]];
        return array_values($imageIds);
    }

    /** @param list<int> $imageIds @return list<array{id:int,url:string}> */
    private function galleryFor(array $imageIds): array
    {
        $library = $this->container->get(ProductImageLibraryInterface::class);
        $gallery = [];
        foreach ($imageIds as $id) {
            $gallery[] = ['id' => (int) $id, 'url' => $library->thumbnailUrl((int) $id)];
        }
        return $gallery;
    }

    /** Categories are the manager's templates: the vendor picks from what exists. */
    private function categories(): array
    {
        $categories = [];
        foreach ($this->templates()->all() as $template) {
            $categories[$template->categoryKey] = $template->label;
        }
        return $categories;
    }

    private function stepUrl(VendorUrls $urls, int $productId, string $step): string
    {
        $step = in_array($step, ['1', '2', '3', '4'], true) ? $step : '1';
        return add_query_arg('step', $step, $urls->product($productId));
    }

    private function nextStep(string $step): string
    {
        return match ($step) {
            '1' => '2',
            '2' => '3',
            '3' => '4',
            default => '4',
        };
    }

    /** @param array<string,mixed> $raw */
    private function detailsFromArray(array $raw): ProductDetails
    {
        $str = static fn (string $key): string => isset($raw[$key]) ? (string) $raw[$key] : '';
        $int = static fn (string $key): int => isset($raw[$key]) ? (int) $raw[$key] : 0;
        $nullableInt = static fn (string $key): ?int => ($raw[$key] ?? null) === null ? null : (int) $raw[$key];
        $nullableStr = static fn (string $key): ?string => ($raw[$key] ?? null) === null || $raw[$key] === ''
            ? null
            : (string) $raw[$key];
        return new ProductDetails(
            $str('title'),
            $str('type') !== '' ? $str('type') : 'simple',
            $str('categoryKey'),
            $str('brand'),
            $str('shortDescription'),
            $int('priceMinor'),
            $nullableInt('salePriceMinor'),
            $nullableStr('saleFrom'),
            $nullableStr('saleTo'),
            $str('sku'),
            $int('stock'),
            max(1, $int('minPurchase')),
            $nullableInt('maxPurchase'),
            $int('weightGrams'),
            $str('dimensions'),
            $str('taxClass')
        );
    }

    /** @return array<string,mixed> */
    private function detailsToArray(ProductDetails $d): array
    {
        return [
            'title' => $d->title,
            'type' => $d->type,
            'categoryKey' => $d->categoryKey,
            'brand' => $d->brand,
            'shortDescription' => $d->shortDescription,
            'priceMinor' => $d->priceMinor,
            'salePriceMinor' => $d->salePriceMinor,
            'saleFrom' => $d->saleFrom,
            'saleTo' => $d->saleTo,
            'sku' => $d->sku,
            'stock' => $d->stock,
            'minPurchase' => $d->minPurchase,
            'maxPurchase' => $d->maxPurchase,
            'weightGrams' => $d->weightGrams,
            'dimensions' => $d->dimensions,
            'taxClass' => $d->taxClass,
        ];
    }

    private function storeFor(int $userId): ?int
    {
        return $this->access()->storeFor($userId);
    }

    private function access(): StaffAccess
    {
        return $this->container->get(StaffAccess::class);
    }

    private function manage(): ManageProducts
    {
        return $this->container->get(ManageProducts::class);
    }

    private function products(): ProductRepositoryInterface
    {
        return $this->container->get(ProductRepositoryInterface::class);
    }

    private function templates(): SpecTemplateRepositoryInterface
    {
        return $this->container->get(SpecTemplateRepositoryInterface::class);
    }

    private function revisions(): ProductRevisionRepositoryInterface
    {
        return $this->container->get(ProductRevisionRepositoryInterface::class);
    }

    private function publishing(): ProductPublishPolicy
    {
        return $this->container->get(ProductPublishPolicy::class);
    }

    private function drafts(): ProductDraftStoreInterface
    {
        return $this->container->get(ProductDraftStoreInterface::class);
    }

    private function imagePolicy(): ProductImagePolicy
    {
        return $this->container->get(ProductImagePolicy::class);
    }

    /**
     * The cap the vendor is actually subject to, in whole megabytes, rounded
     * DOWN: «۲ مگابایت» when the host allows 2.5 refuses fewer files than
     * «۳» would, and a limit that is stated larger than it is gets the vendor
     * blamed for the host's setting.
     */
    private function maxImageMb(): int
    {
        return max(1, (int) floor($this->imagePolicy()->maxBytes() / 1048576));
    }

    private function flash(): FlashStoreInterface
    {
        return $this->container->get(FlashStoreInterface::class);
    }

    private function formKey(int $userId): string
    {
        return 'product_form_' . $userId;
    }

    private function csvSourceKey(int $userId): string
    {
        return 'product_csv_src_' . $userId;
    }

    private function csvReportKey(int $userId): string
    {
        return 'product_csv_report_' . $userId;
    }

    private function bulkPreviewKey(int $userId): string
    {
        return 'product_bulk_preview_' . $userId;
    }
}
