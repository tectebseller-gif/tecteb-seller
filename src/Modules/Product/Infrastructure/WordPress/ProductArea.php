<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Contracts\DependencyProbeInterface;
use Tecteb\Marketplace\Contracts\FlashStoreInterface;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Marketplace\Application\ManageWholesale;
use Tecteb\Marketplace\Modules\Marketplace\Presentation\MarketplaceMessages;
use Tecteb\Marketplace\Modules\Product\Application\EstimateVendorShare;
use Tecteb\Marketplace\Modules\Product\Application\ManageProducts;
use Tecteb\Marketplace\Modules\Product\Application\EditStorefrontVariations;
use Tecteb\Marketplace\Modules\Product\Application\ManageVariations;
use Tecteb\Marketplace\Modules\Product\Application\VariationRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductCategoryDirectoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductCsv;
use Tecteb\Marketplace\Modules\Product\Application\ProductImageLibraryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductPublishPolicy;
use Tecteb\Marketplace\Modules\Product\Application\ProductDraftStoreInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductDecisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\CategorySet;
use Tecteb\Marketplace\Modules\Product\Domain\ProductImagePolicy;
use Tecteb\Marketplace\Modules\Product\Application\BrandDirectoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\BrandProposalRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ManageBrands;
use Tecteb\Marketplace\Modules\Product\Application\ProductRevisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\SpecTemplateRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\LongDescription;
use Tecteb\Marketplace\Modules\Product\Domain\PriceAndStockOrigin;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductRevision;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductCategoryPickerView;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductBulkPreviewView;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductCsvView;
use Tecteb\Marketplace\Modules\Product\Domain\BrandName;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductBrandFieldView;
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
    /**
     * Every action this area answers to.
     *
     * The router checks this list BEFORE dispatching, so a verb that exists in
     * `handle()` and not here is not «unhandled» — it is *forbidden*, and the
     * vendor is bounced to the application page with `tmc_notice=forbidden`.
     * That is exactly what «پیش‌نمایش نتیجه» did from `alpha.14` to
     * `alpha.25`: the handler was written, wired and tested at the service
     * level, and the one line that lets a request reach it was missing.
     * `ProductActionsAreRegisteredTest` now compares the two by reading the
     * source, so the next verb cannot be half-added.
     */
    public const ACTIONS = [
        'save_product', 'submit_product', 'archive_product', 'restore_product',
        'bulk_products', 'preview_bulk_products',
        'export_products', 'import_products', 'apply_products_csv',
        'save_attribute', 'delete_attribute', 'save_variation', 'delete_variation', 'save_variation_stock',
        'build_variations',
        // §3: the two figures a vendor may change on a REAL imported
        // variation. Separate verbs from the five above because they go
        // through a separate service to a separate store — WooCommerce's
        // own child — and the two services refuse in opposite directions.
        'save_shop_variation_prices', 'save_shop_variation_stock',
        // §6: the ladder, moved off the support page onto this product's own
        // form. Same verb name and same service as before, so an audit row or
        // a bookmark from the old place still means the same thing — only the
        // page that renders the form changed.
        'set_tiers',
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
        // Taken before the preview, so a run that follows a preview shows what
        // HAPPENED rather than what was forecast.
        $ran = $this->flash()->take($this->bulkResultKey($view->userId));
        if (is_array($ran) && isset($ran['result']) && is_array($ran['result'])) {
            /** @var array{action:string,rows:list<array{product_id:int,ok:bool,code:string,title:string,from:string,to:string}>,ok:int,failed:int} $report */
            $report = $ran['result'];
            // The result view carries its own notice with the right tone, so
            // the list beneath it is asked not to repeat it.
            return ProductBulkPreviewView::renderResult($report, $view->urls)
                . $this->list($view, $vendorUserId, $mayEdit, false);
        }

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

    private function list(VendorAreaView $view, int $vendorUserId, bool $mayEdit, bool $withNotice = true): string
    {
        $request = $view->request;
        $status = ProductStatus::tryFrom($request->queryKey('status'));
        $page = max(1, $request->queryInt(VendorUrls::PAGE_PARAM));
        $search = $request->queryText('q');

        // COUNTED FIRST, THEN CLAMPED, THEN READ.
        //
        // A page number past the end used to be read literally: the offset
        // ran off the end of the list, the screen said «در این وضعیت محصولی
        // ندارید» and the pager — which is only drawn when there are rows —
        // was not there to come back with. On a 184-product shop that is a
        // dead end reachable with one keystroke in the address bar, and the
        // empty state's advice («وضعیت دیگری را ببینید») is wrong, because
        // the products exist and are one page back.
        //
        // The last page is what such a request gets, which is what the
        // manager's catalogue has done since `alpha.33`. The count is not an
        // extra query: this screen already asked for it, one line below.
        $total = $this->products()->countForVendor($vendorUserId, $status, $search);
        $lastPage = max(1, (int) ceil($total / ProductListView::PER_PAGE));
        if ($page > $lastPage) {
            $page = $lastPage;
        }

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
            $total,
            $view->urls,
            $view->nonceField,
            $withNotice ? $view->notice : null,
            $mayEdit,
            $this->publishing()->mayPublishDirectly($vendorUserId),
            $search,
            $this->listThumbnails($products),
            $this->listVariableRanges($products)
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

        // THE VENDOR'S OWN PENDING PROPOSAL IS WHAT THE FORM SHOWS.
        //
        // The owner's report: a vendor typed a brand on a PUBLISHED product,
        // «ذخیره و ادامه» advanced, and coming back the brand box was empty.
        // Measured on `alpha.44`, the save had answered `revision_requested`
        // and the proposal held the brand — the input was never lost. The form
        // simply rendered `$product->details`, the PUBLISHED row, and the one
        // use this page made of the proposal was a boolean «one is waiting».
        // So the page said a proposal existed and showed none of it, which is
        // the worst of the three readings to leave a vendor with: their work
        // is safe and every screen tells them it is gone.
        //
        // Only where the vendor cannot edit in place. A draft and a
        // changes-requested product are written straight through, so the row
        // IS their last save and a stale proposal must not speak over it.
        // That boundary is pinned by `control-draft-brand`.
        //
        // NOTHING ABOUT APPROVAL MOVES HERE. This is which values a form
        // renders; the proposal is still the manager's to approve, the
        // published row is untouched until they do, and no gate is widened.
        $pending = $product === null ? null : $this->revisions()->pendingFor($product->id);
        $showingProposal = false;
        $proposedAt = '';
        if ($pending !== null && $product !== null && !$product->status->isEditableByVendor()) {
            $payload = $pending->payload;
            $details = $this->detailsFromArray(is_array($payload['details'] ?? null) ? $payload['details'] : []);
            $specs = is_array($payload['specs'] ?? null) ? array_map('strval', $payload['specs']) : $specs;
            $imageIds = is_array($payload['images'] ?? null) ? array_map('intval', $payload['images']) : $imageIds;
            $mainImageId = (int) ($payload['main_image_id'] ?? $mainImageId);
            $showingProposal = true;
            $proposedAt = $pending->createdAt;
        }

        if (is_array($carried) && (int) ($carried['product_id'] ?? -1) === $productId) {
            // AN ABSENT KEY IS «NOT CARRIED», NOT «BLANK» — and the old line
            // read it as blank. `detailsFromArray([])` is an EMPTY product, so
            // a flash that carried anything less than the whole form rendered
            // a form with no title, no category and no price, and the next
            // save proposed exactly that: measured as
            // `approve_code=incomplete_product` about a product that was fine.
            //
            // It was unreachable until this round, because the only writer
            // always wrote every key. Then the brand's failed-request flash
            // became a second writer with two keys, and the defect was a
            // partial flash away. The same «وجود و مقدار دو پرسش‌اند» rule as
            // `alpha.28`, one layer up: the fallback is what the form would
            // have shown, not nothing.
            if (is_array($carried['details'] ?? null)) {
                $details = $this->detailsFromArray($carried['details']);
            }
            $specs = is_array($carried['specs'] ?? null) ? array_map('strval', $carried['specs']) : $specs;
            $imageIds = is_array($carried['images'] ?? null) ? array_map('intval', $carried['images']) : $imageIds;
            $mainImageId = (int) ($carried['main_image_id'] ?? $mainImageId);
            // A refused save wins over the proposal for the same reason it
            // wins over the row: these are the values of the submission being
            // corrected. And then the form is NOT showing the proposal, so it
            // must not claim to be.
            $showingProposal = false;
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
            $this->categoryPicker($details->categorySet(), $view->request->queryText('cat_q'), $mayEdit),
            $view->urls,
            $view->nonceField,
            $view->notice,
            $readiness,
            $share,
            $this->publishing()->mayPublishDirectly($vendorUserId),
            $pending !== null,
            $this->variableData($productId, $details->type, (int) ($product?->wcProductId ?? 0)),
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
                    : null,
            // Scoped by the vendor, in the QUERY: a product id from another
            // shop finds nothing rather than somebody else's correspondence.
            $product === null
                ? null
                : $this->decisions()->latestForVendor($product->id, $vendorUserId),
            // One token per rendered create form. `wp_generate_password()`
            // with no special characters is WordPress's own random string and
            // is already used for the staff invite hash; nothing here needs it
            // to be unguessable, only unique per rendering, and the unique key
            // it lands on is scoped to this vendor anyway.
            $productId === 0 && $mayEdit ? wp_generate_password(32, false, false) : '',
            // Whether the values above are the vendor's PENDING PROPOSAL, and
            // when they proposed it. The form has to say which version it is
            // showing: «برند شما» and «برندی که روی سایت است» are different
            // answers and a box cannot carry both.
            $showingProposal,
            $proposedAt,
            // The PUBLISHED row, so the proposal can be shown beside what
            // buyers see. Passed only when the form is showing a proposal —
            // a page that always rendered a comparison would be a page that
            // never had a reason to.
            $showingProposal ? $product?->details : null,
            // The stock the pending proposal asks for, and the number the shop
            // holds. Read from the marketplace row rather than from WooCommerce
            // because the row IS the mirror (ADR-008) and is brought up to date
            // on every inventory save; a shop read on every form render would
            // cost a query per page for a number that cannot have moved
            // without `pullStock()` noticing.
            self::proposedStockOf($pending),
            $product?->details->stock,
            $this->brandField(
                $product?->details->brand ?? '',
                $productId,
                $mayEdit,
                is_array($carried) && (int) ($carried['product_id'] ?? -1) === $productId ? $carried : null
            ),
            // The shop's own picture, when the vendor's gallery is empty. Read
            // from the same reader the list uses, so one product cannot show
            // two different pictures on two screens.
            $this->storefrontImageFor((int) ($product?->wcProductId ?? 0), $imageIds, $mainImageId),
            // §6: the ladder, read with the SAME call the support page used.
            // Asked for only on its own step, because `tiersFor()` is a query
            // and four other steps have no reader for it.
            $view->request->queryKey('step') === '5' && $productId > 0
                ? [
                    'tiers' => $this->container->get(ManageWholesale::class)->tiersFor($productId),
                    'may_edit' => $mayEdit,
                ]
                : []
        );
    }

    /**
     * The brand field's data: what the box shows, and the names to suggest.
     *
     * **Three readings for the value, in this order, and the order is the
     * point.** What the vendor typed and could not save beats what they have
     * asked for, which beats what the product holds:
     *
     *  1. the typing carried back from a refused save — «ورودی پس از خطای
     *     اعتبارسنجی حفظ شود»;
     *  2. the name of the open request, so a vendor who asked for a brand
     *     reads the name they asked for rather than the one the product still
     *     has. `alpha.45`'s finding was a form that knew a proposal existed
     *     and showed none of it, which is the worst of the three readings to
     *     leave somebody with: their work is safe and the screen says it is
     *     gone;
     *  3. the brand on the row, which is the ordinary case.
     *
     * **The suggestions are seeded with the LIST, not with a search for the
     * value.** The first version searched for whatever the box showed, which
     * is empty exactly when it matters: the box shows a name that is NOT in
     * the list — a brand being asked for — so the vendor got a field with no
     * suggestions at the one moment they wanted to check whether the brand
     * already existed under another spelling. Measured in the browser, with
     * scripts off: `option` count nought. So the seed is the list's own first
     * `SUGGEST` names, the browser narrows them as you type, and `BrandSuggest`
     * widens past the cap when scripts are on.
     *
     * @param array<string,mixed>|null $carried the refused save's own values
     * @return array<string,mixed>
     */
    private function brandField(string $currentBrand, int $productId, bool $mayEdit, ?array $carried): array
    {
        $open = $productId > 0
            ? $this->container->get(BrandProposalRepositoryInterface::class)->openFor($productId)
            : null;
        $value = $currentBrand;
        // THE STORED TERM ID IS IDENTITY; THE ROW'S NAME IS A LABEL.
        //
        // A manager who renames a brand in WooCommerce's own screen leaves
        // every product row holding the old spelling — which is no longer in
        // the list. Resolved by name alone, the vendor's next save would read
        // their own unchanged brand as an unapproved name and open a REQUEST
        // for a brand that already exists. So the box shows the term's current
        // name, and the save carries the row up to the rename.
        if ($productId > 0 && $currentBrand !== '') {
            $termId = $this->products()->brandTermIdOf($productId);
            $named = $termId === null
                ? null
                : $this->container->get(BrandDirectoryInterface::class)->find($termId);
            if ($named !== null) {
                $value = $named->name;
            }
        }
        if ($open !== null) {
            $value = $open->name;
        }
        if (is_array($carried) && array_key_exists('brand_typed', $carried)) {
            $value = (string) $carried['brand_typed'];
        }
        $picked = $this->container->get(ManageBrands::class)->pick('', ProductBrandFieldView::SUGGEST);
        return [
            'value' => $value,
            'suggestions' => $picked['brands'],
            'missing' => !$picked['available'],
            // ⛔ TWO FACTS, NOT ONE. `missing` says there is no list to check a
            // name against; this says whether WooCommerce is even running. The
            // vendor's next move is different in each case — wait for the site
            // to come back, or ask the manager where the shop's brands live —
            // and until this round only the first sentence existed, so a shop
            // whose brands are a WooCommerce ATTRIBUTE was told WooCommerce was
            // off while it was plainly running. Same probe the category field
            // asks, so the two cannot drift.
            'woo' => $this->container->get(DependencyProbeInterface::class)->woocommerceAvailable(),
            'open_request' => $open,
            // Empty for a reader with no edit rights: the seeded datalist is
            // still filtered by the browser, and an endpoint handed to
            // somebody who may not edit is an endpoint whose limits nobody
            // checks the day it answers something else.
            'suggest_url' => $mayEdit ? admin_url('admin-ajax.php') : '',
            'suggest_nonce' => $mayEdit ? wp_create_nonce(BrandSuggest::NONCE) : '',
            'suggest_action' => BrandSuggest::ACTION,
        ];
    }

    /**
     * The stock a pending proposal asks for, or null when it asks for none.
     *
     * `details.stock` has been in every payload since `alpha.6` and was never
     * applied; only `stock_proposal` is a request (the same reader the
     * approval path uses).
     */
    private static function proposedStockOf(?ProductRevision $pending): ?int
    {
        $proposal = $pending?->payload['stock_proposal'] ?? null;
        if (!is_array($proposal) || !array_key_exists('value', $proposal)) {
            return null;
        }
        return (int) $proposal['value'];
    }

    private function decisions(): ProductDecisionRepositoryInterface
    {
        return $this->container->get(ProductDecisionRepositoryInterface::class);
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
                $this->linesOf($request->postTextarea('attr_options')),
                // An unticked checkbox is not posted, so absence IS «no» here
                // — which is what the vendor means by unticking it. The form
                // renders both boxes with their stored state, so a save that
                // carries neither is a save of two deliberate noes and not a
                // form that forgot to send them.
                $request->postChecked('attr_visible'),
                $request->postChecked('attr_variation')
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
            // §4's generator. One field — the product — because every other
            // input it needs is already a fact of that product: the axes, their
            // options and the rows that exist. A form that also carried the
            // count would be a form whose number could disagree with the one
            // the service enforces.
            'build_variations' => $this->onVariation($request, $urls, fn (): OperationResult => $this->variationService()->buildVariations(
                $userId,
                $vendorUserId,
                $request->postInt('product_id')
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
            'save_shop_variation_prices' => $this->onVariation($request, $urls, fn (): OperationResult => $this->shopVariations()->updatePrices(
                $userId,
                $vendorUserId,
                $request->postInt('product_id'),
                $request->postInt('variation_id'),
                // THE TYPED TEXT, not a parsed number: the shop's own price
                // scale decides what it means, and the service asks the shop.
                $request->postText('var_regular'),
                $request->postText('var_sale'),
                // What the screen SHOWED, in minor units at the scale it
                // printed in. An empty field is «no price of that kind»,
                // which is a different fact from «nought» (`alpha.28`).
                $this->seenMinor($request->postText('seen_regular')),
                $this->seenMinor($request->postText('seen_sale')),
                $this->seenMinor($request->postText('seen_exponent'))
            )),
            'save_shop_variation_stock' => $this->onVariation($request, $urls, fn (): OperationResult => $this->shopVariations()->updateStock(
                $userId,
                $vendorUserId,
                $request->postInt('product_id'),
                $request->postInt('variation_id'),
                $this->number($request->postText('stock')),
                // `null` is «the screen said this child keeps no number of its
                // own», which is a real state and not a missing field.
                $this->seenMinor($request->postText('seen_stock'))
            )),
            // §6: THE SAME SERVICE, FROM THE PRODUCT'S OWN FORM.
            //
            // `ManageWholesale::setTiers()` is byte-for-byte what it was and
            // asks the same three questions it always did — the staff
            // permission, the product's ownership and whether every step is
            // sound. Only the page the form was rendered on moved, so a saved
            // ladder from before this round and one saved after it are the
            // same rows (§6: «سازگاری داده‌های قبلی»).
            //
            // It comes back to step 5 of the same product, because the next
            // thing a vendor does after saving a ladder is look at it — which
            // is what the support page's own `add_query_arg('tier_product')`
            // was for, and here the product is already in the address.
            'set_tiers' => new VendorAreaOutcome(
                ($tiers = $this->container->get(ManageWholesale::class)->setTiers(
                    $userId,
                    $vendorUserId,
                    $request->postInt('tier_product'),
                    $this->postedSteps($request)
                ))->code,
                $this->stepUrl($urls, $request->postInt('tier_product'), '5'),
                $tiers->context
            ),
            default => null,
        };
    }

    /**
     * The ladder's steps as typed, with the empty rows dropped.
     *
     * ⛔ LIFTED FROM `SupportArea::postedSteps()`, AND ITS REASONING WITH IT.
     * An empty row is not a zero: a vendor who wants two steps leaves the
     * third pair blank, and reading that as «from 0 at 0 تومان» would refuse
     * the whole save for a step they never meant. A row with only ONE of the
     * two boxes filled IS kept, so `setTiers()` refuses it and names the
     * missing half rather than silently dropping it.
     *
     * @return array<int,int> min quantity => unit price in minor units
     */
    private function postedSteps(Request $request): array
    {
        $quantities = $request->postTextList('tier_qty');
        $prices = $request->postTextList('tier_price');
        $steps = [];
        foreach ($quantities as $row => $quantity) {
            $price = $prices[$row] ?? '';
            if (trim($quantity) === '' && trim($price) === '') {
                continue;
            }
            $steps[(int) $quantity] = (int) $price;
        }
        return $steps;
    }

    private function save(Request $request, int $userId, int $vendorUserId, VendorUrls $urls): VendorAreaOutcome
    {
        $productId = $request->postInt('product_id');
        $step = $request->postKey('step');
        $createToken = $request->postText('create_token');
        // Read BEFORE the details are built, because «this form says nothing
        // about the long description» has to be answered with the value that
        // is there — see `LongDescription::decide()`.
        // The brand the ROW holds. Read here rather than in the reader,
        // because the reader has no product — and read BEFORE the details are
        // built, because what one typed name means depends on it.
        $currentBrand = $productId > 0
            ? ($this->products()->findOwned($productId, $vendorUserId)?->details->brand ?? '')
            : '';
        $brand = $this->brandDecision($request, $currentBrand);
        $details = $this->detailsFromPost(
            $request,
            $this->manage()->storedLongDescription($userId, $vendorUserId, $productId),
            $brand['name']
        );
        $specs = $request->postMap('spec');
        // ASKED BEFORE THE FILE IS TAKEN OFF THE WIRE. A browser that resends
        // a timed-out create resends its upload too, and `imagesFromPost()`
        // stores whatever it is handed as an attachment — so the replay used
        // to make a second copy of the same picture, on the vendor's disk,
        // referenced by nothing, and then throw it away when `save()` found
        // the token. The service refuses the replay either way; this is about
        // not doing the work first.
        $replay = $productId === 0
            && $createToken !== ''
            && $this->manage()->createdAlready($userId, $vendorUserId, $createToken) > 0;
        [$imageIds, $mainImageId, $imageError, $uploaded] = $replay
            ? [[], 0, '', []]
            : $this->imagesFromPost($request, $userId, $vendorUserId);
        $imageIds = $this->reorder($imageIds, $request->postText('move_image'));

        $result = $this->manage()->save(
            $userId,
            $vendorUserId,
            $productId,
            $details,
            $specs,
            $imageIds,
            $mainImageId,
            $request->postText('revision'),
            $createToken
        );
        if (!$result->ok) {
            // A refusal that nonetheless CREATED the row is a different
            // situation from a refusal that wrote nothing, and sending the
            // vendor back to an empty create form would hide a product that
            // exists and lose the half that saved. So when the result carries
            // an id, the vendor lands on that draft — the continuation path
            // §2 asks to be made explicit — and the flash keeps their values
            // against THAT id so the returned form is still the form they had.
            $madeId = (int) ($result->context['product_id'] ?? 0);
            $landOn = $madeId > 0 ? $madeId : $productId;
            $this->flash()->put($this->formKey($userId), [
                'product_id' => $landOn,
                'details' => $this->detailsToArray($details),
                'specs' => $specs,
                'images' => $imageIds,
                'main_image_id' => $mainImageId,
                // THE TYPED BRAND, not the resolved one. `details.brand` holds
                // the value the field would take, which for an unapproved name
                // is the brand the product already has — so carrying only that
                // would hand the vendor back a box with somebody else's name
                // in it and their own typing gone. «ورودی پس از خطای
                // اعتبارسنجی حفظ شود» is about what they typed.
                'brand_typed' => $brand['request'] !== '' ? $brand['request'] : $brand['name'],
            ], self::FORM_TTL);
            return new VendorAreaOutcome($result->code, $this->stepUrl($urls, $landOn, $step), $result->context);
        }
        $savedId = (int) ($result->context['product_id'] ?? $productId);

        // The simultaneous half of §3. The ask above cannot catch two
        // requests that arrive together — both read «no product yet» before
        // either had inserted — so the loser gets here holding an attachment
        // it created and a result saying NOTHING WAS WRITTEN. Only what this
        // request itself uploaded is offered, and only on the one code that
        // means «your write lost»: a save that stored the picture is not
        // reached by this line, and the gallery the form carried is never in
        // `$uploaded`.
        $discarded = 0;
        if ($result->code === 'product_created_replayed' && $uploaded !== []) {
            $discarded = $this->manage()->discardUnusedUploads($userId, $vendorUserId, $uploaded);
        }

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
        // Nor is a category search: the vendor asked to see a different list,
        // not to move on. Both save first, which is the whole reason the
        // search is a submit button rather than a link — a link would lose the
        // title and the brand they had just typed.
        //
        // AND A SUCCESSFUL CREATE GOES FORWARD. `$productId === 0 => '1'` sent
        // every first save of a new product back to «معرفی» — the step it had
        // just been submitted from — so «ذخیره و ادامه» did the saving and
        // not the continuing, and the only way on was the step strip. It is
        // not a guard for anything: this point is reached only after
        // `$result->ok`, so the draft exists and steps 2–4 are reachable; the
        // refusals that genuinely have to stay on step 1 (a validation
        // problem, a failed upload, a half-written draft) all return above
        // this line.
        $searching = $request->postText('search_category') !== '';
        $next = match (true) {
            $searching => self::GALLERY_STEP,
            $request->postText('move_image') !== '' => $step,
            // A replay is not progress: the vendor is asked to look at the
            // draft that already exists, and the gallery the message names is
            // on the step the form was submitted from.
            $replay => $step,
            default => $this->nextStep($step),
        };
        $url = $this->stepUrl($urls, $savedId, $next);
        if ($searching) {
            $url = add_query_arg('cat_q', rawurlencode($request->postText('category_q')), $url);
            // ⛔ And it lands ON the picker. Without JavaScript the search is
            // an ordinary submit, so the browser reloads at the top of a
            // four-step form and the vendor has to find the box again — which
            // reads as «the page came back and nothing happened», the second
            // half of the report. The typed text and the chosen category were
            // already carried (`cat_q`, and the choice is rendered apart from
            // the results since `alpha.25`); what was lost was the place.
            $url .= '#tv-catpick-results';
        }
        // A BRAND NAME NOBODY HAS APPROVED, recorded on the ordinary save.
        //
        // No second field and no second button — the shape the owner asked
        // for. It runs AFTER the save succeeded, deliberately: a request
        // recorded beside a refused save would be a request for a product
        // that did not take the rest of the form. And after the save means
        // after the CREATE too, so a name typed while making a new product is
        // attached to the product that save just made (`$savedId`), which is
        // «نام پیشنهادی در جریان عادی ایجاد محصول حفظ و پس از ایجاد به همان
        // محصول متصل شود».
        //
        // The product's own brand is not touched by any of this: `brandDecision()`
        // kept the stored value, and «تا تأیید، برند فعلی محصول حفظ شود» is
        // that line and nothing else.
        //
        // The redirect still goes FORWARD. Asking for a brand is not a reason
        // to stay on step 1: the vendor pressed «ذخیره و ادامه», the save
        // happened, and the news about the brand travels as the outcome.
        if ($brand['request'] !== '') {
            $asked = $this->container->get(ManageBrands::class)->propose(
                $userId,
                $vendorUserId,
                $savedId,
                $brand['request']
            );
            // NO FLASH IS NEEDED for the name — WHEN THE REQUEST WAS RECORDED.
            // A recorded request IS the record of what was typed, and
            // `brandField()` reads it back, so the box shows the requested name
            // for as long as the request is open: that survives a refresh, a
            // new session and a different browser in a way a flash never
            // could. The `alpha.45` finding was a form that knew a proposal
            // existed and showed none of it; this shows it.
            //
            // AND WHEN IT WAS NOT, there is no record, so the typing has to be
            // carried. That sentence above was written as if the record were
            // certain, and it is not: `propose()` has five named refusals and
            // this path walked past all of them to a forward redirect with the
            // box empty — measured on the `alpha.51` bytes with the proposals
            // table taken away for one save, `product_saved=yes
            // typed_kept=__absent__`. The vendor's save is safely stored and
            // their word is gone, which is the shape this repository calls
            // worse than a refusal: «ورودی پس از خطای اعتبارسنجی حفظ شود».
            //
            // The redirect still goes FORWARD, because the save did happen.
            // What changes is that the name travels with it, against the id it
            // belongs to, so the box is filled when the vendor returns to
            // step 1 and can press save again without retyping.
            if (!$asked->ok) {
                // THE WHOLE FORM, the same set the refused-save path above
                // carries. A flash with two keys in it was the first version
                // of this, and the reader blanked every field it was not given
                // — so the brand came back and the title did not. Written out
                // rather than relying on the reader's new fallback, because
                // «the form they had» is the promise and a form rebuilt from
                // the row is not quite it.
                $this->flash()->put($this->formKey($userId), [
                    'product_id' => $savedId,
                    'details' => $this->detailsToArray($details),
                    'specs' => $specs,
                    'images' => $imageIds,
                    'main_image_id' => $mainImageId,
                    'brand_typed' => $brand['request'],
                ], self::FORM_TTL);
            }
            // Discarded uploads are carried here too. A message about the
            // brand that quietly dropped «و ۲ تصویر نرفت» would hide
            // something that happened, which is the same fault as reporting
            // a half as a whole.
            $context = $asked->context + ['product_id' => $savedId];
            return new VendorAreaOutcome(
                $asked->code,
                $url,
                $discarded > 0 ? $context + ['discarded_uploads' => $discarded] : $context
            );
        }
        return new VendorAreaOutcome(
            $result->code,
            $url,
            $discarded > 0 ? $result->context + ['discarded_uploads' => $discarded] : $result->context
        );
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
        // The rows are held in the flash store and rendered as a table on the
        // landing page, the same way the preview is. A sentence with a list
        // of ids inside it was the old answer, and the ids never arrived:
        // `VendorNotice` dropped the array on its way through the redirect,
        // so the owner read «۴ مورد انجام نشد:» followed by nothing.
        $this->flash()->put($this->bulkResultKey($userId), ['result' => $result->context], self::FORM_TTL);
        $failed = (int) ($result->context['failed'] ?? 0);
        $done = (int) ($result->context['ok'] ?? 0);
        return new VendorAreaOutcome(
            $failed === 0 ? 'bulk_done' : ($done === 0 ? 'bulk_none' : 'bulk_partial'),
            $urls->products(),
            [
                'action' => $result->context['action'] ?? '',
                'ok' => $done,
                'failed' => $failed,
            ]
        );
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

    /**
     * §3's service: the vendor's figures on a REAL WooCommerce variation.
     *
     * Deliberately NOT `variationService()`. That one writes our own tables
     * and refuses when the shop holds the combinations; this one writes the
     * shop's child and refuses when it does not. One rule decides which,
     * so a product is behind exactly one of them.
     */
    private function shopVariations(): EditStorefrontVariations
    {
        return $this->container->get(EditStorefrontVariations::class);
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

    /**
     * A figure the FORM carried back, read as the integer it was printed as.
     *
     * Not `number()`: these fields are not typed by anybody. The view prints
     * them from what it showed, in minor units, in Latin digits, so the only
     * question is whether the field was there at all — and an absent or
     * non-numeric field is `null`, which the services read as «the screen
     * showed no figure». A vendor who strips these fields out of the form gets
     * a refusal from the compare-and-set, never a blind write.
     */
    private function seenMinor(string $raw): ?int
    {
        $raw = trim($raw);
        return preg_match('/^-?\d+$/', $raw) === 1 ? (int) $raw : null;
    }

    // ------------------------------------------------------------- plumbing

    private function detailsFromPost(
        Request $request,
        ?string $storedLong = null,
        string $brand = ''
    ): ProductDetails {
        $number = fn (string $raw): int => $this->number($raw);
        $optional = fn (string $raw): ?int => $this->optionalNumber($raw);
        $date = static function (string $raw): ?string {
            $raw = trim($raw);
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 ? $raw : null;
        };
        return new ProductDetails(
            $request->postText('title'),
            $request->postKey('type') !== '' ? $request->postKey('type') : 'simple',
            // THE SINGLE KEY, and only when the form named exactly one.
            //
            // Until `alpha.81` this was the whole of the category: one
            // `postKey()` through `sanitize_key()`, which strips commas and
            // so could not have carried a set even if one had been sent
            // (measured: `207,208,209` arrives as `207208209`). The picker
            // posts `category[]` now and `postIdList()` parses the ids one by
            // one; which category's spec template applies when there are
            // several is the owner's open decision, so the key stays `''`
            // rather than naming a winner
            // (`docs/open-decisions-alpha82.md` §1).
            CategorySet::soleKey($request->postIdList('category')),
            // ALREADY DECIDED, by `brandDecision()`, before this was called.
            //
            // One typed name can mean three things — this approved brand, no
            // brand, or a request for a name nobody has approved — and only
            // one of them is a value for this field. Resolving it here would
            // put that decision inside a reader that cannot report the third
            // case to anybody.
            $brand,
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
            $request->postText('tax_class'),
            // FOUR answers, and `LongDescription` is the only place the rule
            // lives — the CSV importer reads the same one. Until `alpha.42`
            // this was three, decided here, from a flag the form set on every
            // render: so a legacy product whose box renders empty posted an
            // empty string, and saving the title asked for the shop's
            // description to be deleted.
            //
            // The base marker is what the form was SHOWING, so «cleared» and
            // «there was nothing here» are no longer one submission. It is
            // read from the POST rather than from the row, because comparing a
            // read against a write is the check two concurrent editors both
            // pass (`alpha.14`).
            //
            // `postHtml()` and not `postTextarea()`: the long description is
            // allowed paragraphs and lists, and that reader applies
            // `wp_kses_post()` — the same filter WooCommerce runs over its own
            // product description, so what the vendor may write here is
            // exactly what the storefront would accept.
            LongDescription::decide(
                $request->hasPost(LongDescription::BASE_FIELD) ? $request->postHtml('description') : null,
                $request->hasPost(LongDescription::BASE_FIELD)
                    ? $request->postKey(LongDescription::BASE_FIELD)
                    : null,
                $request->hasPost(LongDescription::CLEAR_FIELD),
                $storedLong
            ),
            // The step. `max(1, …)` exactly as the minimum does, because an
            // empty box and a zero both mean «no stepping» — the semantics
            // `PurchaseQuantity` writes down. A value of nought reaching the
            // service would be refused as `bad_quantity_step`, which is the
            // right answer for a hand-built request and the wrong one for a
            // vendor who simply left the field alone.
            max(1, $number($request->postText('purchase_step'))),
            // THE RELATIONSHIP, whole, read with the set-safe reader.
            CategorySet::of($request->postIdList('category'))
        );
    }

    /**
     * The gallery as the form left it, plus anything just uploaded, minus what
     * was ticked for removal. Ownership is re-checked in ManageProducts, so a
     * posted id that is not this shop's never reaches the row.
     *
     * @return array{0:list<int>, 1:int, 2:string, 3:list<int>} the gallery, the
     *         featured id, the code of an upload that failed (empty when
     *         nothing was uploaded or it worked), and the attachments THIS
     *         request created — the last one kept separate because it is the
     *         only list a discard may be given
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
        $created = [];
        $file = $request->file('product_image');
        if ($file->tempPath !== '' && $file->errorCode !== UPLOAD_ERR_NO_FILE) {
            $uploaded = $this->manage()->uploadImage($userId, $vendorUserId, $file, $request->postText('title'));
            if ($uploaded->ok) {
                $ids[] = (int) $uploaded->context['media_id'];
                // Kept APART from `$ids`, which also holds the gallery the
                // form carried. Those are pictures that already belong to a
                // product; this is the one the request has just made, and it
                // is the only thing any discard is ever allowed to see.
                $created[] = (int) $uploaded->context['media_id'];
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
        return [$ids, in_array($main, $ids, true) ? $main : ($ids[0] ?? 0), $imageError, $created];
    }

    /**
     * The axes and combinations of a variable product, and the thumbnails
     * their pictures resolve to. Empty for a simple product, so the panel
     * costs nothing where it does not belong.
     *
     * @return array{attributes?:list<\Tecteb\Marketplace\Modules\Product\Domain\ProductAttribute>,variations?:list<\Tecteb\Marketplace\Modules\Product\Domain\ProductVariation>,thumbnails?:array<int,string>}
     */
    private function variableData(int $productId, string $type, int $wcProductId = 0): array
    {
        if ($type !== \Tecteb\Marketplace\Modules\Product\Domain\ProductType::VARIABLE) {
            return [];
        }
        $variations = $productId > 0 ? $this->variationRepository()->variations($productId) : [];
        $attributes = $productId > 0 ? $this->variationRepository()->attributes($productId) : [];
        $library = $this->container->get(ProductImageLibraryInterface::class);
        $thumbnails = [];
        foreach ($variations as $variation) {
            if ($variation->mediaId > 0 && !isset($thumbnails[$variation->mediaId])) {
                $thumbnails[$variation->mediaId] = $library->thumbnailUrl($variation->mediaId);
            }
        }
        $data = [
            'attributes' => $attributes,
            'variations' => $variations,
            'thumbnails' => $thumbnails,
            // ⛔ ASKED ONLY WHEN OUR OWN TABLES HOLD NOTHING, and that is the
            // whole rule for which side is the truth (§3-۲): a variable
            // product the marketplace built has its axes here and the shop is
            // its mirror; a product that came in from Dokan has none, and then
            // WooCommerce is the only place the combinations exist.
            //
            // One read, for one product, on a form — not on the list, where it
            // would be a product load per row (`alpha.91`).
            'storefront' => $attributes === [] && $variations === []
                ? $this->storefrontVariations()->setFor($wcProductId)
                : null,
            // Whether the store can record what an attribute is FOR. The form
            // offers the two role controls only when it can, so a vendor is
            // never shown a tick whose answer would be refused — three
            // answers, because «could not find out» is not «absent»
            // (`alpha.68`).
            'roles_supported' => $this->variationRepository()->rolesSupported(),
            // How many combinations the axes describe, how many exist, and how
            // many a generate would add — §4's «قبل از ایجاد، تعداد ترکیب‌ها
            // نمایش داده شود». Asked of the SERVICE, not recomputed here: the
            // panel and the generator must never be able to disagree about the
            // number or the cap (`alpha.78`).
            'preview' => $productId > 0
                ? $this->variationService()->previewCombinations($productId)
                : [],
        ];
        // ⛔ WHERE THIS PRODUCT'S PRICE AND STOCK LIVE, decided HERE because
        // this is the only place that holds all four facts at once — the type,
        // our own tables, the storefront link and what the shop answered. The
        // form view gets the verdict rather than the ingredients, so it cannot
        // assemble a second rule out of them (`alpha.78`).
        $data['origin'] = PriceAndStockOrigin::decide(
            $type,
            $attributes !== [] || $variations !== [],
            $wcProductId > 0,
            $data['storefront']
        );
        return $data;
    }

    /**
     * The imported variable parent's real price range, for the LIST.
     *
     * One query for the whole page rather than a `wc_get_product()` per row:
     * `rangesFor()` reads `wc_product_meta_lookup`, which WooCommerce keeps up
     * to date itself. Only variable products are asked about — a simple
     * product's own row already carries its price.
     *
     * @param list<\Tecteb\Marketplace\Modules\Product\Domain\Product> $products
     * @return array<int,\Tecteb\Marketplace\Modules\Product\Domain\StorefrontPriceRange>
     */
    private function listVariableRanges(array $products): array
    {
        $wanted = [];
        foreach ($products as $product) {
            if ($product->details->type !== \Tecteb\Marketplace\Modules\Product\Domain\ProductType::VARIABLE) {
                continue;
            }
            $wcId = (int) ($product->wcProductId ?? 0);
            if ($wcId > 0) {
                $wanted[$product->id] = $wcId;
            }
        }
        if ($wanted === []) {
            return [];
        }
        $ranges = $this->storefrontVariations()->rangesFor(array_values(array_unique($wanted)));
        $out = [];
        foreach ($wanted as $productId => $wcId) {
            if (isset($ranges[$wcId])) {
                $out[$productId] = $ranges[$wcId];
            }
        }
        return $out;
    }

    private function storefrontVariations(): \Tecteb\Marketplace\Modules\Product\Application\StorefrontVariationReaderInterface
    {
        return $this->container->get(\Tecteb\Marketplace\Modules\Product\Application\StorefrontVariationReaderInterface::class);
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

    /**
     * Main-image thumbnails for the page of products being shown, keyed by
     * product id.
     *
     * Resolved here rather than in the view, which knows nothing about
     * WordPress and is handed URLs. Only the visible page is asked about —
     * `PER_PAGE` rows — so a shop with six hundred products costs what one
     * with six costs.
     *
     * @param list<\Tecteb\Marketplace\Modules\Product\Domain\Product> $products
     * @return array<int,string>
     */
    private function listThumbnails(array $products): array
    {
        $ids = [];
        foreach ($products as $product) {
            $main = $product->mainImageId > 0 ? $product->mainImageId : ($product->imageIds[0] ?? 0);
            if ((int) $main > 0) {
                $ids[$product->id] = (int) $main;
            }
        }
        $out = [];
        if ($ids !== []) {
            $library = $this->container->get(ProductImageLibraryInterface::class);
            foreach ($ids as $productId => $mediaId) {
                $url = $library->thumbnailUrl($mediaId);
                if ($url !== '') {
                    $out[$productId] = $url;
                }
            }
        }

        // THE SHOP'S OWN PICTURE, for the rows that still have none.
        //
        // An imported product has no `tmc_product_images` row — intake records
        // the fields a marketplace row owns and copies no media, deliberately
        // — so the loop above found nothing to resolve and the view drew its
        // «no picture» placeholder beside a product whose photo is on the shop
        // page right now. That was the owner's report: «برخی تصاویر بندانگشتی
        // خالی‌اند، درحالی‌که تصویر در WooCommerce موجود است».
        //
        // Asked only for the rows that need it, and in one query for all of
        // them. ⛔ Nothing is written: no attachment is copied, re-parented or
        // put into the vendor's gallery — an id this shop does not own would
        // be dropped by the next save anyway, and a copy would be a second
        // attachment for a picture that already has one.
        $missing = [];
        foreach ($products as $product) {
            if (isset($out[$product->id])) {
                continue;
            }
            $wcId = (int) ($product->wcProductId ?? 0);
            if ($wcId > 0) {
                $missing[$product->id] = $wcId;
            }
        }
        if ($missing === []) {
            return $out;
        }
        $fromShop = $this->storefrontImages()->thumbnailUrls(array_values(array_unique($missing)));
        foreach ($missing as $productId => $wcId) {
            if (isset($fromShop[$wcId]) && $fromShop[$wcId] !== '') {
                $out[$productId] = $fromShop[$wcId];
            }
        }
        return $out;
    }

    /**
     * The picture the EDIT FORM shows for a product whose gallery is empty.
     *
     * The same reader the list uses, so the two screens cannot show different
     * pictures for one product — «تصویر باید در فهرست و فرم ویرایش یکسان و
     * درست باشد». Empty when the vendor has a gallery of their own (that is
     * what the form edits), when there is no storefront product, or when the
     * shop has no featured image either.
     */
    private function storefrontImageFor(int $wcProductId, array $imageIds, int $mainImageId): string
    {
        if ($wcProductId <= 0 || $imageIds !== [] || $mainImageId > 0) {
            return '';
        }
        $urls = $this->storefrontImages()->thumbnailUrls([$wcProductId]);
        return (string) ($urls[$wcProductId] ?? '');
    }

    private function storefrontImages(): \Tecteb\Marketplace\Modules\Product\Application\StorefrontImageReaderInterface
    {
        return $this->container->get(\Tecteb\Marketplace\Modules\Product\Application\StorefrontImageReaderInterface::class);
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

    /**
     * The state of the «دسته» picker: WooCommerce's own categories.
     *
     * This used to read the manager's SPEC TEMPLATES and hand the form a
     * «key => label» map. On the owner's staging — 1,070 `product_cat` terms
     * and no template yet — that map was EMPTY, so the dropdown had nothing in
     * it, step 1 could never be completed, and no product ever reached review.
     * The templates were never the catalogue; they are notes ABOUT it.
     *
     * @return array<string,mixed>
     */
    /**
     * @param list<int> $selected the terms this product is ALREADY in
     */
    private function categoryPicker(array $selected, string $query, bool $mayEdit = false): array
    {
        $directory = $this->container->get(ProductCategoryDirectoryInterface::class);
        $total = $directory->total();
        $results = $directory->search($query, ProductCategoryPickerView::SHOW);
        // EVERY category the product is in, each resolved to its full path.
        //
        // «مدیر نباید مجبور به انتخاب مجدد دسته‌های موجود شود»: a product in
        // two categories must arrive on the form with BOTH already ticked, so
        // saving changes nothing unless somebody changes something. One
        // `find()` per id is the same shape the single selection used; an id
        // that no longer resolves is kept in the list and shown as such rather
        // than dropped, because dropping it is how a relationship disappears.
        $chosen = [];
        foreach (CategorySet::of($selected) as $id) {
            $chosen[$id] = $directory->find((string) $id);
        }
        return [
            'chosen' => $chosen,
            // Kept for the one-category case every existing view reads.
            'selected' => $chosen === [] ? null : reset($chosen),
            'results' => $results,
            'query' => $query,
            // Asked for only when there is nothing to show: it walks every
            // term, and a search that worked has no reader for it.
            'explain' => $results === [] && $query !== '' ? $directory->explainNoMatch($query) : [],
            'matched' => $directory->countMatches($query),
            'total' => $total,
            'missing' => $total === 0 && !$this->container->get(DependencyProbeInterface::class)->woocommerceAvailable(),
            // Where the live suggestions come from. Empty for a reader who may
            // not edit, and the picker then renders exactly what it rendered
            // before the script existed.
            'suggest_url' => $mayEdit ? admin_url('admin-ajax.php') : '',
            'suggest_nonce' => $mayEdit ? wp_create_nonce(CategorySuggest::NONCE) : '',
            'suggest_action' => CategorySuggest::ACTION,
        ];
    }

    private function stepUrl(VendorUrls $urls, int $productId, string $step): string
    {
        // ⛔ ONE LIST OF STEPS. This was `['1', '2', '3', '4']`, typed, while
        // `ProductFormView::stepNav()` builds its links by iterating
        // `steps()` — so the moment a step was added the nav linked it and
        // this sent the vendor back to step 1. Derived now, so the two cannot
        // disagree again (`alpha.105`: two readers of one rule).
        $steps = array_map('strval', array_keys(ProductFormView::steps()));
        $step = in_array($step, $steps, true) ? $step : '1';
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

    /**
     * What ONE typed brand name means: a value for the field, or a request.
     *
     * `alpha.48` answered this with a radio list — a term id, `keep` or `0` —
     * and the owner rejected the shape that needed. One box has to carry all
     * of it, so the save decides, and these are the five readings:
     *
     *  - **the field is not in the POST at all** → nothing is being asked, so
     *    the stored brand stands. That is the `alpha.42` rule («an absent box
     *    is not an instruction to delete»), and it is also what makes a stale
     *    `alpha.50` form — whose picker posted `brand_term` and no `brand` —
     *    arrive harmlessly during an upgrade instead of clearing the brand.
     *  - **empty** → no brand. A real choice, written as one.
     *  - **the stored value, however it is spelled** → unchanged. The stored
     *    spelling stands and nothing is re-judged, which is how a legacy
     *    free-text brand goes on being saved without being called approved.
     *  - **an approved brand** → that brand, under THE LIST'S spelling rather
     *    than the typing, so a posted name cannot differ from the approved one
     *    by a character.
     *  - **anything else** → a REQUEST. The field keeps the brand the product
     *    has — «تا تأیید، برند فعلی محصول حفظ شود» — and the name travels in
     *    `request` for the save to record once the product itself is stored.
     *
     * @return array{name:string, request:string}
     */
    private function brandDecision(Request $request, string $currentBrand): array
    {
        if (!$request->hasPost('brand')) {
            return ['name' => $currentBrand, 'request' => ''];
        }
        $typed = BrandName::display($request->postText('brand'));
        if ($typed === '') {
            return ['name' => '', 'request' => ''];
        }
        if (BrandName::same($typed, $currentBrand)) {
            return ['name' => $currentBrand, 'request' => ''];
        }
        $approved = $this->container->get(BrandDirectoryInterface::class)->findByKey(BrandName::key($typed));
        if ($approved !== null) {
            return ['name' => $approved->name, 'request' => ''];
        }
        return ['name' => $currentBrand, 'request' => $typed];
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
            $str('taxClass'),
            // THE SEVENTEENTH ARGUMENT, which this method used to leave off.
            //
            // `detailsToArray()` has always written `description`, so the
            // array that comes back here carries it — and dropping it on the
            // way in turned every round trip through this pair into «this
            // product has never had a long description». Two paths use the
            // pair: the flash of a REFUSED save, and (from this round) the
            // payload of a pending proposal. Measured on `alpha.44`: a save
            // refused with `stale_revision` came back with an empty box and
            // `description_base=none`, so the vendor's text was gone from the
            // form AND a second save would not have put it back.
            //
            // Three states, kept three. The key missing altogether is `null`
            // — that is flash written by an older build, and «nothing was
            // asked» is the answer that changes nothing. A stored `null` is
            // `null`. `''` is the deliberate clear and stays `''`, which is
            // why this cannot use `$nullableStr()`: that one folds `''` into
            // `null` and would turn a clear into a no-op.
            array_key_exists('description', $raw) && $raw['description'] !== null
                ? (string) $raw['description']
                : null,
            // `?: 1` and not `$int()`: a flash or a proposal payload written
            // before this round has no key, and «no step» is 1. Reading it as
            // nought would make a refused save come back with a step the
            // vendor never typed, and `max(1, …)` further in would hide it.
            max(1, isset($raw['purchaseStep']) ? (int) $raw['purchaseStep'] : 1),
            // The relationship, carried through the flash of a refused save
            // and through a proposal's payload. ABSENT is not empty: a flash
            // or payload written before `alpha.82` has no such key, and
            // deriving the set from `categoryKey` would turn an old form into
            // a request to narrow the product to one category.
            CategorySet::of((array) ($raw['categoryIds'] ?? []))
        );
    }

    /** @return array<string,mixed> */
    private function detailsToArray(ProductDetails $d): array
    {
        return [
            'title' => $d->title,
            'type' => $d->type,
            'categoryKey' => $d->categoryKey,
            'categoryIds' => $d->categoryIds,
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
            'purchaseStep' => $d->purchaseStep,
            'weightGrams' => $d->weightGrams,
            'dimensions' => $d->dimensions,
            'taxClass' => $d->taxClass,
            'description' => $d->description,
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

    private function bulkResultKey(int $userId): string
    {
        return 'product_bulk_result_' . $userId;
    }

    private function bulkPreviewKey(int $userId): string
    {
        return 'product_bulk_preview_' . $userId;
    }
}
