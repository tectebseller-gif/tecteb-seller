<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation\Admin;

use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\WooCommerceStorefrontFields;
use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Migration\Application\CatalogueInventory;
use Tecteb\Marketplace\Modules\Migration\Application\ImportExistingProducts;
use Tecteb\Marketplace\Modules\Migration\Application\TransferOwnership;
use Tecteb\Marketplace\Modules\Migration\Domain\CatalogueBucket;
use Tecteb\Marketplace\Modules\Migration\Domain\CatalogueFacets;
use Tecteb\Marketplace\Modules\Migration\Presentation\Admin\MigrationPage;
use Tecteb\Marketplace\Modules\Migration\Presentation\Admin\ProductIntakePage;
use Tecteb\Marketplace\Modules\Product\Application\ProductCategoryDirectoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductImageLibraryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductPublishPolicy;
use Tecteb\Marketplace\Modules\Product\Application\ProductDecisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\BrandDirectoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\BrandProposalRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ManageBrands;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRevisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ReviewProducts;
use Tecteb\Marketplace\Modules\Product\Application\ReviewSeen;
use Tecteb\Marketplace\Modules\Product\Application\SpecTemplateRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontFieldsInterface;
use Tecteb\Marketplace\Modules\Product\Domain\BrandName;
use Tecteb\Marketplace\Modules\Product\Domain\CategorySet;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\ProductRevision;
use Tecteb\Marketplace\Modules\Product\Domain\ProductSort;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;
use Tecteb\Marketplace\Modules\Product\Domain\ProposedBrand;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Domain\SensitiveChange;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\ProjectedFieldOwnership;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\WooCommerceProjector;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress\BrandSuggest;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductBrandFieldView;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductMessages;
use Tecteb\Marketplace\Modules\Vendor\Application\StoreRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorMessages;

/**
 * The manager's products (UX §14.2): one list, and one product at a time.
 *
 * **`alpha.32` made this two views instead of five sections.** The page used to
 * render the submission queue as full cards, the proposed revisions as diff
 * tables, the whole catalogue as a table, the twenty most recent published
 * products as full cards again and an SEO form for each of those twenty — all
 * on one screen, so a product could appear three times and every appearance
 * read its gallery, its specification table and its comparison against
 * WooCommerce. With a real catalogue that is a page nobody can work through.
 *
 * Now the list is a list — twenty summary rows with a `LIMIT` behind them — and
 * everything a decision needs is on the product's own page, reached by
 * «مشاهده و بررسی» and returning to the exact search, filter and page number it
 * was opened from (`ProductCatalogueState`).
 *
 * Nothing about WHAT a decision does changed here. A product waiting to go live
 * for the first time and a CHANGE proposed to one that is already live are
 * still two different questions with two different forms; the second is still
 * shown as a field-by-field diff — current value beside proposed value — and
 * rejecting it still leaves the live product alone, which is the rule the whole
 * revision mechanism exists to keep.
 */
final class ProductReviewPage
{
    public const SLUG = 'tmc-product-review';
    public const CAPABILITY = Capabilities::REVIEW_PRODUCTS;
    private const NONCE = 'tmc_product_review';

    /**
     * How many shops a name search may offer.
     *
     * The bound is the point: a term matching three hundred shops is not a
     * choice, it is a different search — and the page says so rather than
     * truncating in silence.
     */
    private const SHOP_MATCHES = 20;

    /**
     * The whole page, built on `load-{hook}` and echoed by the render callback.
     *
     * Null until built. It is also the «already done» flag: WordPress calls the
     * render callback after the load hook, and building twice would record the
     * view twice and show the page twice.
     */
    private ?string $output = null;

    /**
     * What this render SHOWED, as product id => submission token.
     *
     * Collected while building and written once, after the buffer is whole. The
     * tokens come from the same statement as the rows they belong to; nothing
     * here looks them up again.
     *
     * @var array<int,string>
     */
    private array $seen = [];

    /** A throw from `build()`, re-thrown by `render()` rather than by the hook. */
    private ?\Throwable $failure = null;

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public static function menuLabel(): string
    {
        return __('بررسی محصولات', 'tecteb-marketplace-core');
    }

    /**
     * The page, built BEFORE WordPress prints the admin menu.
     *
     * **Why this runs on `load-{hook}` and not in the render callback.**
     * `wp-admin/admin.php` fires `load-{$page_hook}`, then includes
     * `admin-header.php` — which is where `_wp_menu_output()` prints the red
     * count — and only THEN calls the page's render callback. So in `alpha.36`
     * the bubble was already on the wire by the time this page recorded a view,
     * and the manager's own screen kept the number they had just cleared.
     * Doing the work here, one hook earlier, is what lets `MenuRegistrar`
     * repaint the count on this very request. Nothing is simulated and no
     * JavaScript is involved: the menu is simply printed after the page has
     * decided what it shows.
     *
     * **Buffered, so «rendered successfully» is a fact rather than a hope.** The
     * view is recorded only once the whole page is in the buffer. A throw on the
     * way through leaves the buffer discarded and nothing marked — «مواردی را که
     * هنوز با موفقیت رندر نشده‌اند خوانده‌شده ثبت نکن».
     *
     * **And the count the menu then prints is read from the database, not from
     * arithmetic here.** If `markSeen()` failed, the number is unchanged, so the
     * screen cannot show a success that did not happen.
     */
    public function prepare(): void
    {
        if ($this->output !== null || !current_user_can(self::CAPABILITY)) {
            return;
        }
        $this->seen = [];
        ob_start();
        try {
            $this->build();
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->seen = [];
            // Kept, not thrown here. This hook runs before `admin-header.php`,
            // so throwing would take the whole back office — menu, header and
            // all — down with this one page. `render()` throws it instead, in
            // the same place `alpha.36` would have: loudly, inside the page,
            // with the rest of wp-admin intact.
            $this->failure = $e;
            return;
        }
        $this->output = (string) ob_get_clean();

        if ($this->seen !== []) {
            $this->container->get(ReviewSeen::class)->markSeen(get_current_user_id(), $this->seen);
        }
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('دسترسی لازم را ندارید.', 'tecteb-marketplace-core'), '', ['response' => 403]);
        }
        // Built on `load-{hook}` in wp-admin; built here when something calls
        // the render callback directly, which is what the render tests do.
        $this->prepare();
        if ($this->failure !== null) {
            throw $this->failure;
        }
        echo (string) $this->output;
    }

    private function build(): void
    {
        // Captured once and used both for the decision and for the list state.
        // Two captures of one request are two chances to read it differently —
        // and the state decides where the manager lands afterwards.
        $request = Request::capture();
        $notice = $this->handleAction($request);
        // The intake's own actions, answered separately. Their results are
        // per-product sentences rather than one message code — «جزئیات هر
        // ردیف» — so they do not go through `ProductMessages::notice()`,
        // which maps one code to one sentence.
        $intakeNotice = $this->handleIntake($request);
        if ($intakeNotice === '') {
            // Its own handler, its own nonce. Asked second because the two
            // never arrive together: a form posts one `*_action` or the other.
            $intakeNotice = $this->handleOwnership($request);
        }

        echo Components::shellOpen(self::menuLabel(), self::SLUG, __('نسخه آزمایشی', 'tecteb-marketplace-core'));
        if ($intakeNotice !== '') {
            echo Components::notice(
                str_starts_with($intakeNotice, 'ok:') ? 'success' : 'error',
                substr($intakeNotice, strpos($intakeNotice, ':') + 1)
            );
        }
        if ($notice !== null) {
            echo Components::notice(
                ProductMessages::isErrorNotice($notice['code']) ? 'error' : 'success',
                (string) (ProductMessages::notice($notice['code'], $notice['context'])
                    ?? VendorMessages::notice($notice['code'], $notice['context']))
            );
        }

        // One product, or the list. Never both: until `alpha.32` this page
        // rendered the submission queue as full cards, then the proposed
        // revisions as diff tables, then the catalogue as a table, then the
        // twenty most recent published products as full cards AGAIN, and then
        // an SEO form for each of those twenty. The same product appeared
        // three times; each of those renders read its gallery, its
        // specification table and its field-by-field comparison against
        // WooCommerce; and the row the manager came for was somewhere below
        // all of it.
        $productId = $request->queryInt('product');
        // A WooCommerce product with no marketplace row has no `product` id to
        // be opened by, and from `alpha.56` it is in this list too. Its own
        // page is where the evidence, the other plugins' meta keys and
        // everything an intake could not carry are read — «توضیح فنی و
        // تکراری به نمای جزئیات منتقل شود».
        $wcId = $request->queryInt('wc');
        if ($productId > 0) {
            $this->renderDetail($request, $productId);
        } elseif ($wcId > 0) {
            $this->renderWooCommerceDetail($request, $wcId);
        } else {
            $this->renderCatalogue($request);
            // Stays on the list: a permission belongs to a VENDOR, not to any
            // one product, so it has no product page to live on.
            $this->renderPublishPermissions();
        }

        echo Components::shellClose();
    }

    /**
     * The list's state, read from the URL in exactly one place.
     *
     * Both views need it: the list to query and draw itself, and a product's
     * own page to know where «بازگشت به فهرست» goes. Read in two places it
     * would drift, and the manager would come back from a decision to page one
     * of an unfiltered list — which, on a catalogue of hundreds, means starting
     * over.
     *
     * Every value is validated here rather than trusted onwards: an unknown
     * status is «همه», an unknown sort is the default order, and a page size
     * that is not one of the three offered is twenty. A URL somebody typed by
     * hand is not an error worth reporting, but it is not a query to run
     * either.
     *
     * @param array<string,int> $counts
     */
    private function catalogueState(
        Request $request,
        int $total = 0,
        array $counts = [],
        int $needsReview = 0
    ): ProductCatalogueState {
        return new ProductCatalogueState(
            ProductStatus::tryFrom($request->queryKey('status'))?->value ?? '',
            $request->queryText('q'),
            max(1, $request->queryInt('paged')),
            ProductCatalogueState::perPage($request->queryInt('per_page')),
            ProductSort::fromKey($request->queryKey('orderby')),
            // Both keys read, one written. `review` is this round's name for
            // the scope; `revisions` was its name until `alpha.48` and a link
            // somebody bookmarked must keep working.
            $request->queryKey('review') === ProductCatalogueState::REVIEW_PENDING
                || $request->queryKey('revisions') === ProductCatalogueState::REVIEW_PENDING,
            $total,
            $counts,
            $needsReview,
            admin_url('admin.php?page=' . self::SLUG),
            null,
            // The three facets of the one list, each validated by the class
            // that owns its values. An unknown value is «همه» rather than an
            // error: a URL somebody typed by hand should show the list.
            CatalogueFacets::source($request->queryKey('source')),
            CatalogueFacets::wcStatus($request->queryKey('wc_status')),
            CatalogueFacets::management($request->queryKey('management'))
        );
    }

    /**
     * The one list: every product of every shop, twenty rows at a time.
     *
     * **The limit is in the query.** `forManager()` reads `per_page` rows with
     * a real `LIMIT`/`OFFSET`; nothing here fetches the catalogue and hides the
     * rest, because the cost of a page is not its markup — it is the store
     * name, the WooCommerce status and the thumbnail of every row, and a row
     * hidden by CSS costs exactly as much as a visible one.
     *
     * Everything a row shows is read here and handed over, so the view holds no
     * container and asks no repository. The WooCommerce column is
     * `get_post_status()`, not a mirror column of our own: a status copied into
     * our table is a second truth, and the first person to edit the post in
     * wp-admin makes it wrong without anyone finding out.
     */
    private function renderCatalogue(Request $request): void
    {
        $products = $this->container->get(ProductRepositoryInterface::class);
        $revisions = $this->container->get(ProductRevisionRepositoryInterface::class);
        $vendors = $this->container->get(VendorRepositoryInterface::class);
        $stores = $this->container->get(StoreRepositoryInterface::class);
        $library = $this->container->get(ProductImageLibraryInterface::class);
        $inventory = $this->container->get(CatalogueInventory::class);

        $state = $this->catalogueState($request);
        $status = ProductStatus::tryFrom($state->status);

        // THE ONE READ, and the `available` answer decides which list this is.
        // Without WooCommerce there is no post table to union with, so the
        // page falls back to the marketplace's own rows rather than drawing
        // half a catalogue and calling it the catalogue.
        $unified = $inventory->unified(
            $state->readerFilters() + [
                'marketplace_status' => $state->status,
                'needs_review' => $state->onlyNeedsReview,
            ],
            $state->perPage,
            ($state->page - 1) * $state->perPage,
            $state->sort->value
        );
        if (($unified['available'] ?? false) !== true) {
            $this->renderMarketplaceOnlyCatalogue($request, $state, $status);
            return;
        }

        // Counted BEFORE the rows are read, because the count is what decides
        // which page exists. A decision that empties the last page leaves the
        // browser asking for a page that is gone, and reading the rows first
        // answers that question with an empty screen instead of with the last
        // page there is.
        $state = $state->withCounts(
            (int) $unified['total'],
            $products->countsByStatusForManager($state->search, 0, $state->onlyNeedsReview),
            // THE ONE NUMBER, by the one definition: products that need a
            // decision, each counted once.
            $products->countAwaitingReview(),
            (array) $unified['facets']
        );
        // Re-read once the page has been clamped: asking for page 7 of a
        // six-page list and then drawing page 6's furniture around page 7's
        // (empty) rows is the mismatch `withCounts()` exists to prevent.
        if ($state->outOfRangePage !== null) {
            $unified = $inventory->unified(
                $state->readerFilters() + [
                'marketplace_status' => $state->status,
                'needs_review' => $state->onlyNeedsReview,
            ],
                $state->perPage,
                ($state->page - 1) * $state->perPage,
                $state->sort->value
            );
        }

        // The marketplace side of the rows on this page, in a handful of
        // reads rather than one per row. The ids come from the list, so a
        // product on page two costs nothing here.
        $productIds = [];
        foreach ($unified['rows'] as $row) {
            if ((int) $row['product_id'] > 0) {
                $productIds[] = (int) $row['product_id'];
            }
        }
        $marketplace = $products->findMany($productIds);
        $proposed = array_fill_keys($revisions->pendingProductIds($productIds), true);
        $brandRequested = array_fill_keys(
            $this->container->get(BrandProposalRepositoryInterface::class)->openProductIds($productIds),
            true
        );

        $rows = [];
        $shown = [];
        foreach ($unified['rows'] as $row) {
            $id = (int) $row['product_id'];
            $product = $marketplace[$id] ?? null;
            if ($product !== null) {
                // The token came down WITH the row, out of the same statement
                // (`alpha.37`). Read in a second query it could be newer than
                // the row above it, and a vendor submitting in between would
                // have their submission recorded as seen by a manager who was
                // looking at the previous one.
                $shown[$id] = (string) $row['submission'];
            }
            $vendorId = $product?->vendorUserId ?? (int) $row['vendor_user_id'];
            $rows[] = new ProductCatalogueRow(
                $id,
                (string) $row['title'],
                (string) $row['sku'],
                // The settings row first, then the application: a shop that
                // has been approved has a name in the first and a shop
                // mid-review only has one in the second.
                $vendorId > 0
                    ? ($stores->find($vendorId)?->storeName
                        ?: ($vendors->findApplicationByUser($vendorId)?->details->storeName ?? ''))
                    : '',
                $product?->status,
                $product !== null ? $product->isProjected() : (int) $row['wc_product_id'] > 0,
                (string) $row['wc_status'] === CatalogueFacets::STATUS_ABSENT
                    ? ''
                    : (string) $row['wc_status'],
                (string) $row['changed_at'],
                $this->thumbnailFor($row, $product, $library),
                isset($proposed[$id]),
                isset($brandRequested[$id]),
                $product?->ownsItsStorefrontProduct() ?? true,
                (int) $row['wc_product_id'],
                (string) $row['source'],
                (string) $row['management'],
                (string) $row['bucket_label'],
                (string) $row['bucket'],
                (bool) $row['intake_ready'],
                $this->blockerSentences((array) $row['reasons']),
                (int) $row['variations']
            );
        }

        // §1-هـ: PUBLISHED on the shop, priced nowhere — said out loud, and
        // nothing done about it.
        //
        // The two halves of this state are different jobs: a product the shop
        // has a price for and the marketplace row does not needs the shop's
        // own number brought across (one click, `refreshFromStorefront()`),
        // and one neither side has priced needs a person to decide a price.
        // Both are unapprovable until then — `ProductReadiness` names `price`
        // — and neither is touched here: no status change, no price written,
        // nothing unpublished. From this round the projector also leaves the
        // shop's price alone rather than writing a nought over it, so this
        // notice is the only thing that happens to such a product.
        $this->renderPricelessPublished($inventory);

        echo ProductCatalogueView::render($rows, $state, $this->intakeControls($request));

        // **Only what this page actually drew.** «فقط نسخه‌هایی که در آن صفحه
        // و با آن فیلتر نمایش داده شده‌اند»: the ids come from the rows above,
        // so a product on page two, or outside the current filter, is
        // untouched.
        //
        // Handed to `prepare()` rather than written here: the view is recorded
        // once the WHOLE page is in the buffer, so a throw further down this
        // method marks nothing.
        $this->seen += $shown;
    }

    /** @see renderCatalogue() for why this reports and never repairs. */
    private function renderPricelessPublished(CatalogueInventory $inventory): void
    {
        $rows = $inventory->pricelessPublished(100);
        if ($rows === []) {
            return;
        }
        $ids = [];
        $shopHasOne = 0;
        foreach ($rows as $row) {
            $ids[] = (string) $row['wc_product_id'];
            if ($row['shop_price'] !== '__absent__' && $row['shop_price'] !== '__empty__') {
                $shopHasOne++;
            }
        }
        echo Components::notice('warning', sprintf(
            /* translators: 1: how many, 2: WooCommerce ids, 3: how many of them the shop does have a price for */
            __('%1$s محصول در ووکامرس منتشر شده‌اند و ردیف بازارگاهشان قیمتی ندارد (شناسهٔ ووکامرس: %2$s). وضعیت عمومی و قابلیت خریدشان عوض نشده و هیچ قیمتی برایشان نوشته نشده است؛ تا قیمت تعیین نشود تأیید و انتشارشان از این صفحه رد می‌شود. از این میان %3$s مورد در خودِ فروشگاه قیمت دارند، که با «به‌روزکردن از فروشگاه» روی صفحهٔ همان محصول حل می‌شود؛ بقیه قیمت لازم دارند.', 'tecteb-marketplace-core'),
            esc_html(PersianDigits::toPersian((string) count($rows))),
            esc_html(PersianDigits::toPersian(implode('، ', $ids))),
            esc_html(PersianDigits::toPersian((string) $shopHasOne))
        ));
    }

    /**
     * The list when there is no WooCommerce to union with.
     *
     * Not a smaller version of the one above: a different list, drawn from the
     * marketplace's own rows, which is all this site has. Saying that plainly
     * beats a unified screen with one half silently missing — the manager
     * would read «فقط ردیف بازارگاه» off every row and conclude the shop had
     * lost its products.
     */
    private function renderMarketplaceOnlyCatalogue(
        Request $request,
        ProductCatalogueState $state,
        ?ProductStatus $status
    ): void {
        $products = $this->container->get(ProductRepositoryInterface::class);
        $revisions = $this->container->get(ProductRevisionRepositoryInterface::class);
        $vendors = $this->container->get(VendorRepositoryInterface::class);
        $stores = $this->container->get(StoreRepositoryInterface::class);
        $library = $this->container->get(ProductImageLibraryInterface::class);

        echo Components::notice('warning', __('ووکامرس روی این سایت پیدا نشد، پس محصول‌های فروشگاه خوانده نمی‌شوند. آنچه پایین می‌بینید فقط ردیف‌های خودِ بازارگاه است.', 'tecteb-marketplace-core'));

        $state = $state->withCounts(
            $products->countForManager($status, $state->search, 0, $state->onlyNeedsReview),
            $products->countsByStatusForManager($state->search, 0, $state->onlyNeedsReview),
            $products->countAwaitingReview()
        );
        $page = $products->forManagerWithSubmission(
            $status,
            $state->search,
            $state->perPage,
            ($state->page - 1) * $state->perPage,
            0,
            $state->sort,
            $state->onlyNeedsReview
        );
        $found = array_column($page, 'product');
        $shown = [];
        foreach ($page as $row) {
            $shown[$row['product']->id] = $row['submission'];
        }
        $ids = array_map(static fn (Product $product): int => $product->id, $found);
        $proposed = array_fill_keys($revisions->pendingProductIds($ids), true);
        $brandRequested = array_fill_keys(
            $this->container->get(BrandProposalRepositoryInterface::class)->openProductIds($ids),
            true
        );

        $rows = [];
        foreach ($found as $product) {
            $rows[] = new ProductCatalogueRow(
                $product->id,
                $product->details->title,
                $product->details->sku,
                $stores->find($product->vendorUserId)?->storeName
                    ?: ($vendors->findApplicationByUser($product->vendorUserId)?->details->storeName ?? ''),
                $product->status,
                $product->isProjected(),
                $product->isProjected() ? (string) (get_post_status((int) $product->wcProductId) ?: '') : '',
                $product->updatedAt,
                self::thumbnailOf($product, $library),
                isset($proposed[$product->id]),
                isset($brandRequested[$product->id]),
                $product->ownsItsStorefrontProduct(),
                (int) ($product->wcProductId ?? 0),
                CatalogueFacets::SOURCE_MARKETPLACE
            );
        }
        echo ProductCatalogueView::render($rows, $state);
        $this->seen += $shown;
    }

    /**
     * The picture: WooCommerce's featured image when there is one, the
     * marketplace row's own otherwise.
     *
     * That order and not the other: a managed product's thumbnail should be
     * what a shopper sees, and a row that has never been projected has only
     * its own.
     *
     * @param array<string,mixed> $row
     */
    private function thumbnailFor(array $row, ?Product $product, ProductImageLibraryInterface $library): string
    {
        $id = (int) ($row['thumbnail_id'] ?? 0);
        if ($id <= 0 && $product !== null) {
            $id = $product->mainImageId > 0 ? $product->mainImageId : (int) ($product->imageIds[0] ?? 0);
        }
        return $id > 0 ? $library->thumbnailUrl($id) : '';
    }

    /**
     * The reasons a row carries, as sentences — one list, from the service
     * that names them.
     *
     * @param list<string> $reasons
     * @return list<string>
     */
    private function blockerSentences(array $reasons): array
    {
        $sentences = CatalogueInventory::reasonSentences();
        $out = [];
        foreach ($reasons as $reason) {
            $out[] = (string) ($sentences[(string) $reason] ?? $reason);
        }
        return $out;
    }

    /**
     * The intake half of the list — or `null`, which means «not this
     * manager».
     *
     * `null` rather than disabled controls: a button that posts nothing looks
     * like a permission somebody could get by clicking harder. The capability
     * is the intake's own (`REVIEW_VENDOR`), not this page's, because taking
     * a product into the marketplace is a vendor decision rather than a
     * product review.
     */
    private function intakeControls(Request $request): ?CatalogueIntakeControls
    {
        if (!current_user_can(ProductIntakePage::CAPABILITY)) {
            return null;
        }
        $query = $request->postText('shop_q');
        $assignTo = $request->postInt('assign_to');
        $matches = [];
        $tooMany = false;
        if ($query !== '') {
            $stores = $this->container->get(StoreRepositoryInterface::class);
            $matches = $stores->searchByShopName($query, self::SHOP_MATCHES);
            $tooMany = count($matches) >= self::SHOP_MATCHES;
        }
        $name = '';
        if ($assignTo > 0) {
            foreach ($matches as $match) {
                if ((int) $match['vendor_user_id'] === $assignTo) {
                    $name = (string) $match['store_name'];
                }
            }
            if ($name === '') {
                $name = $this->container->get(StoreRepositoryInterface::class)
                    ->find($assignTo)?->storeName ?? '';
            }
        }
        return new CatalogueIntakeControls(
            wp_nonce_field(self::NONCE, '_wpnonce', true, false),
            $query,
            $matches,
            $assignTo,
            $name,
            admin_url('admin.php?page=' . MigrationPage::SLUG),
            $tooMany
        );
    }

    /** The picture a row shows: the main one, or the first of the gallery. */
    private static function thumbnailOf(Product $product, ProductImageLibraryInterface $library): string
    {
        $id = $product->mainImageId > 0 ? $product->mainImageId : (int) ($product->imageIds[0] ?? 0);
        return $id > 0 ? $library->thumbnailUrl($id) : '';
    }

    /**
     * One product, on its own page — «صفحهٔ جزئیات مستقل».
     *
     * Everything that used to be repeated for twenty products at once is here
     * for one: the pictures, both descriptions, the specification table, the
     * field-by-field comparison with WooCommerce and its two buttons, the
     * correction form, the link into the WooCommerce editor and into the SEO
     * plugin, the proposed revision with its diff, the SEO fields and the
     * decision history.
     *
     * The way back carries the search, the filter and the page number — and so
     * does every form on this page without being told to: a
     * `<form method="post">` with no `action` submits to the address it was
     * drawn at, which is the address the manager arrived from.
     */
    private function renderDetail(Request $request, int $productId): void
    {
        $state = $this->catalogueState($request);
        echo '<p class="tmc-catalogue__back"><a class="tmc-button tmc-button--ghost" href="'
            . esc_url($state->selfLink()) . '">'
            . esc_html__('بازگشت به فهرست محصولات', 'tecteb-marketplace-core') . '</a></p>';

        // **One statement for the product, its proposal's id and its submission
        // identity.** Three separate reads — `find()`, `pendingFor()`,
        // `submissionsOf()` — left a window in which a vendor's new submission
        // could land, and the page would then show the previous content while
        // recording the newer identity as seen.
        $snapshot = $this->container->get(ProductRepositoryInterface::class)->findWithSubmission($productId);
        $product = $snapshot['product'] ?? null;
        if ($product === null) {
            echo Components::state(
                'error',
                __('این محصول پیدا نشد.', 'tecteb-marketplace-core'),
                __('شناسهٔ این نشانی به هیچ ردیفی در بازارگاه نمی‌خورد. ممکن است محصول حذف شده باشد یا نشانی دست‌نویس باشد.', 'tecteb-marketplace-core')
            );
            return;
        }

        $decisions = $this->container->get(ProductDecisionRepositoryInterface::class);
        $history = $decisions->forProduct($product->id, null, 20);
        // **The proposal is loaded BY THE ID the snapshot named**, not by asking
        // again for «the pending one». A revision row is append-only, so this
        // returns the payload the token is about even if a newer proposal has
        // superseded it since — and that newer one carries a different token, so
        // it is correctly still unseen.
        $revisionId = (int) ($snapshot['revisionId'] ?? 0);
        $revision = $revisionId > 0
            ? $this->container->get(ProductRevisionRepositoryInterface::class)->find($revisionId)
            : null;

        // «باز شدن موفق جزئیات محصول، اعلان همان نسخهٔ نمایش‌داده‌شده را
        // خوانده‌شده کند» — the identity that came back WITH the product above.
        // `s0.r0` means nobody is waiting on it: opening a published product
        // with nothing proposed records nothing, because there is no submission
        // to have seen.
        // Recorded on `waiting`, from the same statement, NOT on the token:
        // `s0.r0` is a real submission identity for anything that predates the
        // decision trail, and this page also opens published products, which
        // have nothing to have seen.
        $shown = ($snapshot['waiting'] ?? false)
            ? [$product->id => (string) $snapshot['submission']]
            : [];
        /** @var StorefrontFieldsInterface|null $storefront */
        $storefront = $this->container->get(StorefrontFieldsInterface::class);

        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html(ProductMessages::displayTitle($product->details->title, $product->id)) . '</h2>'
            . Components::dataList([
                [
                    'label' => __('وضعیت بازارگاه', 'tecteb-marketplace-core'),
                    'value' => ProductMessages::status($product->status),
                ],
                [
                    'label' => __('وضعیت ووکامرس', 'tecteb-marketplace-core'),
                    // Through the same mapping the list uses. Until `alpha.33`
                    // this printed WooCommerce's raw value, so the manager read
                    // «منتشرشده» in the list and `publish` on the product.
                    'value' => $product->isProjected()
                        ? ProductMessages::shopStatus((string) (get_post_status((int) $product->wcProductId) ?: ''))
                        : ProductMessages::shopNotProjected(),
                ],
                [
                    'label' => __('آخرین تغییر', 'tecteb-marketplace-core'),
                    'value' => PersianDigits::toPersian(substr($product->updatedAt, 0, 16)),
                ],
                [
                    'label' => __('آخرین تصمیم', 'tecteb-marketplace-core'),
                    'value' => ProductDecisionHistoryView::latest($history[0] ?? null),
                    'raw' => true,
                ],
            ])
            . '</section>';

        // Accepting marketplace management, on the product's own page.
        // `alpha.55` put it on the migration screen, beside Dokan's tables —
        // and most of this shop's products are not Dokan's, so a manager who
        // had just imported a product from the review list had to find an
        // unrelated page to do the next step on it. «تا کاربر بین صفحه‌های
        // نامربوط سرگردان نشود.»
        echo $this->ownershipPanel($product);

        echo '<section class="tmc-card">' . $this->card($product, $revision !== null);
        if ($product->status === ProductStatus::Submitted) {
            echo Components::notice('info', __('تأیید این محصول یعنی همین نسخه منتشر می‌شود. موجودی از نسخهٔ زنده گرفته می‌شود تا فروش این چند روز برنگردد.', 'tecteb-marketplace-core'));
            echo $this->decisionForm('product', $product->id, [
                'approve' => __('تأیید و انتشار', 'tecteb-marketplace-core'),
                'changes' => __('نیازمند اصلاح', 'tecteb-marketplace-core'),
                'reject' => __('رد و بایگانی', 'tecteb-marketplace-core'),
            ], $storefront?->fingerprints($product) ?? []);
        }
        echo '</section>';

        if ($revision !== null) {
            echo $this->revisionPanel($product, $revision, $storefront);
        }
        echo $this->brandPanel($product);
        $this->seoPanel($product);

        echo '<section class="tmc-card">' . ProductDecisionHistoryView::render($history) . '</section>';

        // Handed up, not written here: `prepare()` records it once the whole
        // page is in the buffer, so a render that threw half way through has not
        // been read. And nothing here decides anything — the product is still in
        // the queue, and still waiting for an approval or a rejection.
        $this->seen += $shown;
    }

    /**
     * What the reviewer was shown, as it arrived back from their form.
     *
     * Read as a flat map of field => fingerprint and nothing else: these
     * values decide whether an approval is refused, so a nested structure
     * arriving from a POST has no business being followed.
     *
     * @return array<string,string>
     */
    private static function seenFingerprints(Request $request): array
    {
        $seen = [];
        foreach (ProjectedFieldOwnership::FIELDS as $field) {
            $value = $request->postKey('seen_' . $field);
            if ($value !== '') {
                $seen[$field] = $value;
            }
        }
        return $seen;
    }

    /**
     * One product, rendered the way somebody deciding about it needs it.
     *
     * Everything the card shows is read HERE and handed over, so the view
     * holds no container and asks no repository — and the two derived values
     * (the shop's description and the category path) come from the same code
     * that writes them, not from a second implementation.
     */
    private function card(Product $product, bool $hasPendingRevision = false): string
    {
        $catalog = $this->container->get(SyncCatalog::class);
        $available = $catalog->isAvailable();
        /** @var StorefrontFieldsInterface|null $storefront */
        $storefront = $this->container->get(StorefrontFieldsInterface::class);
        $fields = $storefront?->compare($product) ?? [];

        // The vendor's own long description, straight off the row — not out of
        // the comparison. Until `alpha.41` it came from `$field->marketplace`,
        // which was the text the PROJECTOR would have generated; now it is a
        // field the vendor writes, and `null` (a product from before migration
        // 23) has to survive the trip so the card can say «this one has none»
        // rather than «this one is empty».
        $description = $product->details->description;

        return ProductReviewCardView::render(
            $product,
            $this->imagesOf($product),
            $this->container->get(ProductCategoryDirectoryInterface::class)
                ->find($product->details->categoryKey)?->path ?? '',
            $this->container->get(SpecTemplateRepositoryInterface::class)
                ->findByCategory($product->details->categoryKey),
            $this->storeOf($product->vendorUserId),
            $fields,
            $description,
            $product->isProjected() ? ($storefront?->editorUrl((int) $product->wcProductId) ?? '') : '',
            $storefront?->seoPluginName() ?? '',
            wp_nonce_field(self::NONCE, 'tmc_review_nonce', true, false),
            $available,
            // Asked here rather than in the view, like every other value on this
            // card: the view holds no container and calls no adapter. And asked
            // only for a product that HAS a storefront post — the answer for one
            // that does not is a refusal with a reason, which is what the default
            // is.
            $product->isProjected() && $storefront !== null
                ? $storefront->viewLink((int) $product->wcProductId)
                : ['url' => '', 'public' => false, 'reason' => 'missing'],
            $hasPendingRevision,
            // WHY the comparison is empty, when it is. An empty list has four
            // causes and the card used to read all four as «the two sides are
            // identical»; asked here because the view calls no adapter.
            //
            // With no reader at all the answer is `unreadable` rather than
            // `compared`: a card that cannot ask has not established that the
            // two sides agree.
            $storefront?->comparisonState($product) ?? WooCommerceStorefrontFields::STATE_UNREADABLE
        );
    }

    /** @return list<array{url:string,id:int,main:bool}> */
    private function imagesOf(Product $product): array
    {
        $library = $this->container->get(ProductImageLibraryInterface::class);
        $ids = array_values(array_unique(array_merge(
            $product->mainImageId > 0 ? [$product->mainImageId] : [],
            $product->imageIds
        )));
        $out = [];
        foreach ($ids as $id) {
            $out[] = [
                'id' => (int) $id,
                'url' => $library->thumbnailUrl((int) $id),
                'main' => (int) $id === $product->mainImageId,
            ];
        }
        return $out;
    }

    /** @return array<string,string> */
    private function storeOf(int $vendorUserId): array
    {
        $settings = $this->container->get(StoreRepositoryInterface::class)->find($vendorUserId);
        $profile = $this->container->get(VendorRepositoryInterface::class)->findProfileByUser($vendorUserId);
        return [
            'name' => $settings?->storeName ?? ($profile?->storeName ?? ''),
            'city' => $settings?->city ?? '',
            'status' => $profile === null
                ? __('بدون پروندهٔ فروشندگی', 'tecteb-marketplace-core')
                : ($profile->canSell
                    ? __('اجازهٔ فروش دارد', 'tecteb-marketplace-core')
                    : __('اجازهٔ فروش ندارد', 'tecteb-marketplace-core')),
        ];
    }

    /**
     * The proposed version of a published product, with its diff.
     *
     * It carries the same lock the queue's form does. This form writes over a
     * PUBLISHED product, so it is the one where a manager's edit made between
     * drawing the page and pressing the button costs the most.
     */
    /**
     * The brand a vendor asked for, and the manager's three answers.
     *
     * ONE FIELD, because the owner asked for the picker's two-field shape to
     * go and because two of the three answers are the same gesture: whatever
     * name is in the box is resolved against the list, so leaving it alone
     * approves the name as asked, correcting it to a brand that EXISTS
     * attaches this product to that brand, and correcting it to anything else
     * registers the manager's spelling. The names already in the list are
     * suggested as you type, by the same endpoint the vendor's field uses.
     *
     * The third answer is its own button, because rejecting is not approving
     * something else: it needs a reason and it leaves the product's brand
     * exactly as it is.
     *
     * A request that got through registering and not applying shows only
     * «تلاش دوباره» — not a second approval, which would try to register a
     * brand that is already there.
     */
    private function brandPanel(Product $product): string
    {
        $open = $this->container->get(BrandProposalRepositoryInterface::class)->openFor($product->id);
        if ($open === null) {
            return '';
        }
        $registered = $open->outstanding() === 'apply_to_product';
        $html = '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('برند پیشنهادی فروشنده', 'tecteb-marketplace-core') . '</h2>'
            . '<p class="tmc-hint">' . esc_html(sprintf(
                /* translators: %s: the vendor's user id */
                __('فروشنده #%s نامی نوشته که در فهرست برندها نیست. تا تصمیم شما، برند فعلی این محصول دست‌نخورده است.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) $open->vendorUserId)
            )) . '</p>'
            . Components::dataList([
                [
                    'label' => __('برند فعلی محصول', 'tecteb-marketplace-core'),
                    'value' => $product->details->brand !== '' ? $product->details->brand : '—',
                ],
                [
                    'label' => __('وضعیت', 'tecteb-marketplace-core'),
                    'value' => $registered
                        ? __('به فهرست اضافه شده؛ اعمال روی این محصول کامل نشده است.', 'tecteb-marketplace-core')
                        : __('در انتظار تصمیم شما.', 'tecteb-marketplace-core'),
                ],
            ]);
        if ($registered) {
            return $html . $this->decisionForm('brand', $open->id, [
                'finish' => __('تلاش دوباره برای اعمال روی محصول', 'tecteb-marketplace-core'),
            ]) . '</section>';
        }
        return $html . $this->decisionForm('brand', $open->id, [
            'approve' => __('تأیید برند و اعمال روی محصول', 'tecteb-marketplace-core'),
            'reject' => __('رد پیشنهاد', 'tecteb-marketplace-core'),
        ], [], $this->brandNameField($open->name)) . '</section>';
    }

    /**
     * The manager's own brand box: the asked name, editable, with suggestions.
     *
     * Pre-filled rather than empty, so the common case — «yes, that name» — is
     * one click and nothing has to be retyped.
     *
     * **The datalist is seeded with the LIST, not with a search for the asked
     * name.** The first version searched for what was asked — and what was
     * asked is by definition NOT in the list, so the manager got an empty
     * datalist at the one moment the question «do we already have this under
     * another spelling?» is being decided. Measured in the browser: `option`
     * count nought.
     */
    private function brandNameField(string $asked): string
    {
        /** @var BrandDirectoryInterface $directory */
        $directory = $this->container->get(BrandDirectoryInterface::class);
        $html = '<div class="tmc-field">'
            . '<label class="tmc-field__label" for="tmc-brand-name">'
            . esc_html__('نام برند (برای اتصال به برند موجود، نام همان را بنویسید)', 'tecteb-marketplace-core')
            . '</label>'
            . '<input class="tmc-input" type="text" id="tmc-brand-name" name="brand_name"'
            . ' value="' . esc_attr($asked) . '"'
            . ' list="tmc-brand-names" autocomplete="off"'
            . ' maxlength="' . esc_attr((string) BrandName::MAX_LENGTH) . '"'
            . ' data-tmc-brand-field="1"'
            . ' data-suggest-url="' . esc_attr(admin_url('admin-ajax.php')) . '"'
            . ' data-suggest-nonce="' . esc_attr(wp_create_nonce(BrandSuggest::NONCE)) . '"'
            . ' data-suggest-action="' . esc_attr(BrandSuggest::ACTION) . '">'
            . '<datalist id="tmc-brand-names" data-tmc-brand-list="1">';
        foreach ($directory->search('', ProductBrandFieldView::SUGGEST) as $brand) {
            $html .= '<option value="' . esc_attr($brand->name) . '"></option>';
        }
        return $html . '</datalist></div>';
    }

    private function revisionPanel(
        Product $product,
        ProductRevision $revision,
        ?StorefrontFieldsInterface $storefront
    ): string {
        return '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('نسخهٔ پیشنهادی فروشنده', 'tecteb-marketplace-core') . '</h2>'
            . '<p class="tmc-hint">' . esc_html(sprintf(
                /* translators: %s: the vendor's user id */
                __('فروشنده #%s — نسخه فعلی روی سایت است و با رد این پیشنهاد حذف نمی‌شود.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) $revision->vendorUserId)
            )) . '</p>'
            . $this->diffTable($product, $revision)
            . $this->decisionForm('revision', $revision->id, [
                'approve' => __('تأیید تغییر', 'tecteb-marketplace-core'),
                'reject' => __('رد تغییر', 'tecteb-marketplace-core'),
            ], $storefront?->fingerprints($product) ?? [],
                $this->stockConflict($product, $revision) . $this->brandConflict($product, $revision))
            . '</section>';
    }

    private function diffTable(Product $product, ProductRevision $revision): string
    {
        $proposed = is_array($revision->payload['details'] ?? null) ? $revision->payload['details'] : [];
        $rows = '';
        foreach (SensitiveChange::SENSITIVE as $field) {
            $current = $product->details->{$field};
            $next = $proposed[$field] ?? $current;
            if ((string) $current === (string) $next) {
                continue;
            }
            // THE BRAND, AND ONLY WHEN THE PAYLOAD IS ACTUALLY ASKING FOR IT —
            // the same rule the stock row below has carried since `alpha.48`,
            // and for a reason one step along: the payload holds a brand
            // whether the vendor asked about it or not, so once the approval
            // started KEEPING the live brand this row was printing «B → A»
            // about a change that will not be made. A page that shows a
            // proposal it is not going to apply is the `alpha.13` fault in
            // review clothing, and it would send a manager to reject a
            // perfectly good stock proposal.
            //
            // A real conflict KEEPS its row: there the question is genuine,
            // and `brandConflict()` prints the three values beneath this table.
            if ($field === 'brand' && $this->proposedBrandVerdict($product, $revision) === ProposedBrand::KEEP) {
                continue;
            }
            $rows .= '<tr><th scope="row">' . esc_html(ProductMessages::field($field) !== $field
                ? ProductMessages::field($field)
                : $field) . '</th>'
                . '<td>' . esc_html((string) $current !== '' ? (string) $current : '—') . '</td>'
                . '<td><strong>' . esc_html((string) $next !== '' ? (string) $next : '—') . '</strong></td></tr>';
        }
        // ⛔ THE SENSITIVE SETS, which the loop above cannot draw.
        //
        // It compares with `(string) $current === (string) $next`, and casting
        // an array to a string is a PHP notice and the word «Array» — so the
        // set would either never differ or always differ, and `categoryKey` is
        // `''` on both sides of a multi-category change anyway (`soleKey()`
        // deliberately picks no winner). Until this block, approving a
        // revision APPLIED the proposed set while this table drew no row for
        // it: the manager agreed to a category change they were never shown,
        // which is `alpha.49` («consent is about the value that was SHOWN»)
        // and the owner's own requirement that the set be visible.
        //
        // And only when the payload is ACTUALLY asking — the same rule the
        // brand and stock rows carry. A revision written before `alpha.82`
        // has no `categoryIds` key, `detailsFromPayload()` keeps the LIVE set
        // for it, and drawing a row about a change that will not be made is
        // the `alpha.13` fault in review clothing.
        foreach (SensitiveChange::SENSITIVE_SETS as $field) {
            if (!array_key_exists($field, $proposed)) {
                continue;
            }
            $currentSet = $product->details->setFor($field);
            $nextSet = CategorySet::of(is_array($proposed[$field]) ? $proposed[$field] : []);
            if (CategorySet::same($currentSet, $nextSet)) {
                continue;
            }
            // ⛔ AN EMPTY PROPOSAL IS NOT «—», BECAUSE «—» WOULD BE A PROMISE.
            //
            // A vendor can untick every category, and then the revision
            // carries an empty set. Approving it writes NOTHING: clearing a
            // recorded relationship has no path in this package —
            // `syncCategorySet()` returns early on an empty set and
            // `saveCategorySet()` refuses one unconditionally, which is «هیچ
            // رابطه‌ای خودکار حذف نشود». Drawing a dash in the proposal column
            // would tell the manager their approval clears the categories, and
            // it would not: the `alpha.13` fault in review clothing, one row
            // along from the brand and stock rows that already guard it.
            //
            // So the row says what will happen, and points at the decision
            // that would change it (`docs/open-decisions-alpha82.md` §2).
            $nextText = $nextSet === [] && $currentSet !== []
                ? __(
                    'درخواست پاک‌کردن همهٔ دسته‌ها — با تأیید اعمال نمی‌شود و دسته‌های فعلی می‌مانند.',
                    'tecteb-marketplace-core'
                )
                : $this->categorySetText($nextSet);
            $rows .= '<tr><th scope="row">' . esc_html(ProductMessages::field($field) !== $field
                ? ProductMessages::field($field)
                : $field) . '</th>'
                . '<td>' . esc_html($this->categorySetText($currentSet)) . '</td>'
                . '<td><strong>' . esc_html($nextText) . '</strong></td></tr>';
        }
        // STOCK, and only when the payload is actually asking for it.
        // `details.stock` has been in every payload since `alpha.6` and was
        // never applied; reading THAT as a proposal would show a change on
        // every revision ever written. `stock_proposal` is the request.
        $stockProposal = $revision->payload['stock_proposal'] ?? null;
        if (is_array($stockProposal) && array_key_exists('value', $stockProposal)) {
            $rows .= '<tr><th scope="row">' . esc_html__('موجودی', 'tecteb-marketplace-core') . '</th>'
                . '<td>' . esc_html(PersianDigits::toPersian((string) $product->details->stock)) . '</td>'
                . '<td><strong>' . esc_html(PersianDigits::toPersian((string) (int) $stockProposal['value']))
                . '</strong></td></tr>';
        }
        $proposedSpecs = is_array($revision->payload['specs'] ?? null) ? array_map('strval', $revision->payload['specs']) : [];
        if (SensitiveChange::specsChanged($product->specs, $proposedSpecs)) {
            $rows .= '<tr><th scope="row">' . esc_html__('مشخصات پزشکی', 'tecteb-marketplace-core') . '</th>'
                . '<td>' . esc_html(self::flatten($product->specs)) . '</td>'
                . '<td><strong>' . esc_html(self::flatten($proposedSpecs)) . '</strong></td></tr>';
        }
        $proposedImages = is_array($revision->payload['images'] ?? null) ? array_map('intval', $revision->payload['images']) : $product->imageIds;
        if ($proposedImages !== $product->imageIds) {
            $rows .= '<tr><th scope="row">' . esc_html__('گالری', 'tecteb-marketplace-core') . '</th>'
                . '<td>' . esc_html(PersianDigits::toPersian((string) count($product->imageIds))) . '</td>'
                . '<td><strong>' . esc_html(PersianDigits::toPersian((string) count($proposedImages))) . '</strong></td></tr>';
        }
        if ($rows === '') {
            return '<p>' . esc_html__('این پیشنهاد تفاوتی با نسخه فعلی ندارد.', 'tecteb-marketplace-core') . '</p>';
        }
        return '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('فیلد', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('نسخه فعلی', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('پیشنهاد فروشنده', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    /**
     * A set of category ids as the manager has to read it: id AND full path.
     *
     * The id alone is unreadable and the leaf name alone is ambiguous — «لوازم
     * جانبی» sits on six branches of the owner's catalogue (`alpha.81`), so a
     * row that printed only leaf names would let a manager approve a move to
     * a different branch believing nothing changed. A term the directory can
     * no longer resolve is named as a number with «ترم حذف‌شده» rather than
     * dropped, because a deleted category is exactly the case the owner asked
     * to see.
     *
     * @param list<int> $ids
     */
    private function categorySetText(array $ids): string
    {
        if ($ids === []) {
            return '—';
        }
        /** @var ProductCategoryDirectoryInterface $directory */
        $directory = $this->container->get(ProductCategoryDirectoryInterface::class);
        $parts = [];
        foreach ($ids as $id) {
            $path = $directory->find((string) $id)?->path ?? '';
            $parts[] = $path !== ''
                ? sprintf('%s (#%s)', $path, PersianDigits::toPersian((string) $id))
                : sprintf(
                    /* translators: %s: the term id */
                    __('ترم حذف‌شده #%s', 'tecteb-marketplace-core'),
                    PersianDigits::toPersian((string) $id)
                );
        }
        return implode(' · ', $parts);
    }

    /**
     * The extra control a stock proposal needs, or '' when it needs none.
     *
     * The conflict is between PROPOSING and APPROVING: the vendor asked for 5
     * when the shop held 9, and the shop holds 2 now. `approveRevision()`
     * refuses that by default; this is where the manager is told the three
     * numbers and given the one control that says apply it anyway.
     *
     * A checkbox, and deliberately: a browser does not send an unticked one, so
     * the default travelling with every ordinary approval is «do not»
     * (`alpha.46`). And it is only rendered when the numbers actually disagree,
     * so an approval that has nothing to decide has nothing to tick.
     */
    private function stockConflict(Product $product, ProductRevision $revision): string
    {
        $proposal = $revision->payload['stock_proposal'] ?? null;
        if (!is_array($proposal) || !array_key_exists('baseline', $proposal) || !array_key_exists('value', $proposal)) {
            return '';
        }
        $baseline = (int) $proposal['baseline'];
        // Read from the shop, because that is the number a shopper meets and
        // the number `approveRevision()` will compare against.
        $current = $this->container->get(SyncCatalog::class)->pullStock($product->id) ?? $product->details->stock;
        if ($current === $baseline) {
            return '';
        }
        $id = 'stock-conflict-' . $revision->id;
        // THE NUMBER THIS PAGE IS SHOWING, carried with the permission.
        //
        // `alpha.49`: the checkbox used to be a blanket «apply it anyway», so
        // a sale between this render and the submit was overwritten without
        // ever appearing on a screen. The hidden field makes the permission
        // specific to the sentence above it, and `approveRevision()` refuses
        // when the shop has moved again — naming all four numbers.
        $seen = '<input type="hidden" name="seen_stock" value="' . esc_attr((string) $current) . '">';
        return '<div class="tmc-notice tmc-notice--warning"><p>' . esc_html(sprintf(
            /* translators: 1: stock when proposed, 2: stock now, 3: proposed stock */
            __('موجودی از زمان ثبت این پیشنهاد تغییر کرده است: هنگام پیشنهاد %1$s بود، اکنون %2$s است، و پیشنهاد فروشنده %3$s است. تأیید بدون انتخاب گزینهٔ زیر انجام نمی‌شود.', 'tecteb-marketplace-core'),
            PersianDigits::toPersian((string) $baseline),
            PersianDigits::toPersian((string) $current),
            PersianDigits::toPersian((string) (int) $proposal['value'])
        )) . '</p>'
            . '<p><label for="' . esc_attr($id) . '">'
            . '<input type="checkbox" id="' . esc_attr($id) . '" name="accept_stock_conflict" value="1"> '
            . esc_html__('موجودی پیشنهادی را با وجود این تغییر اعمال کن', 'tecteb-marketplace-core')
            . '</label></p>' . $seen . '</div>';
    }

    /**
     * The brand moved after this proposal was written — named, with one control.
     *
     * The same shape as `stockConflict()` above and for the same reason, one
     * field over: the brand has a decision path of its own
     * (`tmc_brand_proposals`), so approving a proposal can replay a snapshot
     * over a brand a manager approved afterwards. `ProposedBrand` decides
     * whether there is anything to ask; this renders the question when there
     * is.
     *
     * Rendered ONLY when the answer is `MOVED`, so an approval with nothing to
     * decide has nothing to tick — and the unticked checkbox is not sent, which
     * makes «do not» the default that travels with every ordinary approval
     * (`alpha.46`).
     */
    /**
     * `ProposedBrand`'s answer for this proposal, asked in ONE place.
     *
     * The diff table and the conflict control both need it, and two readers
     * deriving it separately are two rules — the day they disagree the manager
     * sees a row the approval will not apply, or a warning about a field the
     * table says nothing about. Returns `APPLY` when the payload says nothing
     * about the brand at all, which is «there is nothing to decide».
     */
    private function proposedBrandVerdict(Product $product, ProductRevision $revision): string
    {
        $details = $revision->payload['details'] ?? null;
        if (!is_array($details) || !array_key_exists('brand', $details)) {
            return ProposedBrand::APPLY;
        }
        $map = $revision->payload[ProductRevision::BASELINE] ?? null;
        $hasBaseline = is_array($map) && array_key_exists('brand', $map);
        return ProposedBrand::decide(
            $hasBaseline,
            $hasBaseline ? (string) $map['brand'] : '',
            (string) $details['brand'],
            $product->details->brand
        );
    }

    private function brandConflict(Product $product, ProductRevision $revision): string
    {
        if ($this->proposedBrandVerdict($product, $revision) !== ProposedBrand::MOVED) {
            return '';
        }
        $details = $revision->payload['details'] ?? [];
        $proposed = (string) (is_array($details) ? ($details['brand'] ?? '') : '');
        $map = $revision->payload[ProductRevision::BASELINE] ?? null;
        $hasBaseline = is_array($map) && array_key_exists('brand', $map);
        $baseline = $hasBaseline ? (string) $map['brand'] : '';
        $id = 'brand-conflict-' . $revision->id;
        $blank = __('بدون برند', 'tecteb-marketplace-core');
        // THE BRAND THIS PAGE IS SHOWING, carried with the permission —
        // `alpha.49`'s rule beside the number it was written for. A brand
        // approved between this render and the submit must not be overwritten
        // by a tick that was never aimed at it.
        $seen = '<input type="hidden" name="seen_brand" value="' . esc_attr($product->details->brand) . '">';
        return '<div class="tmc-notice tmc-notice--warning"><p>' . esc_html(sprintf(
            /* translators: 1: brand when proposed, 2: brand now, 3: proposed brand */
            __('برند این محصول از زمان ثبت این پیشنهاد تغییر کرده است: هنگام پیشنهاد «%1$s» بود، اکنون «%2$s» است، و این پیشنهاد «%3$s» را دارد. تأیید بدون انتخاب گزینهٔ زیر انجام نمی‌شود.', 'tecteb-marketplace-core'),
            $hasBaseline ? ($baseline === '' ? $blank : $baseline) : __('نامعلوم', 'tecteb-marketplace-core'),
            $product->details->brand === '' ? $blank : $product->details->brand,
            $proposed === '' ? $blank : $proposed
        )) . '</p>'
            . '<p><label for="' . esc_attr($id) . '">'
            . '<input type="checkbox" id="' . esc_attr($id) . '" name="accept_brand_conflict" value="1"> '
            . esc_html__('برند این پیشنهاد را با وجود این تغییر اعمال کن', 'tecteb-marketplace-core')
            . '</label></p>' . $seen . '</div>';
    }

    /** @param array<string,string> $values */
    private static function flatten(array $values): string
    {
        $parts = [];
        foreach ($values as $key => $value) {
            if (trim((string) $value) !== '') {
                $parts[] = $key . ': ' . $value;
            }
        }
        return $parts === [] ? '—' : implode('، ', $parts);
    }

    /** @param array<string,string> $decisions */
    /** @param array<string,string> $seen the shop's fingerprints as this page read them */
    private function decisionForm(string $subject, int $id, array $decisions, array $seen = [], string $extra = ''): string
    {
        $html = '<form method="post" class="tmc-review__form">'
            . wp_nonce_field(self::NONCE, 'tmc_review_nonce', true, false)
            . '<input type="hidden" name="subject" value="' . esc_attr($subject) . '">'
            . '<input type="hidden" name="subject_id" value="' . esc_attr((string) $id) . '">';
        // Carried, not recomputed on submit: the whole point is to compare
        // what the reviewer SAW against what is there now.
        foreach ($seen as $field => $fingerprint) {
            $html .= '<input type="hidden" name="seen_' . esc_attr((string) $field)
                . '" value="' . esc_attr((string) $fingerprint) . '">';
        }
        $html .= $extra
            . '<div class="tmc-field"><label class="tmc-field__label" for="note-' . esc_attr($subject . '-' . $id) . '">'
            . esc_html__('دلیل تصمیم (برای اصلاح و رد اجباری است)', 'tecteb-marketplace-core') . '</label>'
            . '<textarea class="tmc-input" id="note-' . esc_attr($subject . '-' . $id) . '" name="note" rows="2"></textarea></div><p>';
        foreach ($decisions as $value => $label) {
            $html .= '<button type="submit" class="tmc-button' . ($value === 'approve' ? ' tmc-button--primary' : '') . '"'
                . ' name="decision" value="' . esc_attr($value) . '">' . esc_html($label) . '</button> ';
        }
        return $html . '</p></form>';
    }

    /**
     * SEO — the manager's alone (§6). The vendor's form has no such fields and
     * never had: this is the only screen in the plugin where they exist.
     */
    private function seoPanel(Product $product): void
    {
        $catalog = $this->container->get(SyncCatalog::class);
        echo '<section class="tmc-card"><h2 class="tmc-card__title">' . esc_html__('سئوی محصول — فقط مدیر', 'tecteb-marketplace-core') . '</h2>'
            . '<p class="tmc-hint">' . esc_html__('نشانی عمومی، عنوان و توضیح متای محصول در اختیار فروشنده نیست. تغییر این‌ها بی‌درنگ روی صفحهٔ عمومی محصول اعمال می‌شود.', 'tecteb-marketplace-core') . '</p>';
        if (!$catalog->isAvailable()) {
            echo Components::notice('warning', __('WooCommerce فعال نیست، پس محصول‌های بازارگاه صفحهٔ عمومی ندارند و سئو جایی اعمال نمی‌شود. مقدارها ذخیره می‌شوند و با فعال‌شدن WooCommerce اعمال خواهند شد.', 'tecteb-marketplace-core'));
        }
        /** @var StorefrontFieldsInterface|null $storefront */
        $storefront = $this->container->get(StorefrontFieldsInterface::class);
        $seoPlugin = $storefront?->seoPluginName() ?? '';
        if ($seoPlugin !== '') {
            // The owner had values saved in Rank Math and this form showed them
            // blank, because it has never read anything but our own column. An
            // empty box next to a filled one is not a second opinion, it is a
            // trap: somebody types into it and the real fields stay as they
            // were. So when a real SEO plugin is here, the form goes and a link
            // to ITS editor takes its place.
            echo $this->seoHandover($product, $seoPlugin, $storefront);
            return;
        }
        $id = 'seo-' . $product->id;
        echo '<form method="post" class="tmc-review">'
            . wp_nonce_field(self::NONCE, 'tmc_review_nonce', true, false)
            . '<input type="hidden" name="subject" value="seo">'
            . '<input type="hidden" name="subject_id" value="' . esc_attr((string) $product->id) . '">'
            . '<p class="tmc-hint">' . esc_html(
                $product->isProjected()
                    ? sprintf(__('شناسهٔ محصول در فروشگاه: %s', 'tecteb-marketplace-core'), PersianDigits::toPersian((string) $product->wcProductId))
                    : __('این محصول هنوز به فروشگاه نگاشت نشده است.', 'tecteb-marketplace-core')
            ) . '</p>'
            . '<div class="tmc-field"><label class="tmc-field__label" for="' . $id . '-slug">'
            . esc_html__('نشانی (slug)', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tmc-input" type="text" id="' . $id . '-slug" name="seo_slug" dir="ltr" value="'
            . esc_attr($product->seo->slug) . '"></div>'
            . '<div class="tmc-field"><label class="tmc-field__label" for="' . $id . '-title">'
            . esc_html__('عنوان متا', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tmc-input" type="text" id="' . $id . '-title" name="seo_title" value="'
            . esc_attr($product->seo->title) . '"></div>'
            . '<div class="tmc-field"><label class="tmc-field__label" for="' . $id . '-desc">'
            . esc_html__('توضیح متا', 'tecteb-marketplace-core') . '</label>'
            . '<textarea class="tmc-input" id="' . $id . '-desc" name="seo_description" rows="2">'
            . esc_textarea($product->seo->description) . '</textarea></div>'
            . '<p><button type="submit" class="tmc-button tmc-button--primary" name="decision" value="save">'
            . esc_html__('ذخیره سئو', 'tecteb-marketplace-core') . '</button></p>'
            . '</form></section>';
    }

    /**
     * When a real SEO plugin is installed, it is the one place SEO lives.
     *
     * Nothing is deleted. `_tmc_seo_*` values written by earlier versions are
     * still in the database and still shown here — READ-ONLY, labelled as this
     * plugin's own older data, with the name of the field they belong in on the
     * other side. They are not copied across: writing into another plugin's
     * meta keys from here would be this plugin guessing at that plugin's
     * storage contract, and the day the guess is wrong it is the owner's search
     * results that pay for it.
     */
    private function seoHandover(Product $product, string $plugin, ?StorefrontFieldsInterface $storefront): string
    {
        $editor = $product->isProjected() ? $storefront?->editorUrl((int) $product->wcProductId) ?? '' : '';
        return Components::notice('info', sprintf(
            /* translators: %s: the SEO plugin's name, e.g. Rank Math */
            __('سئوی این محصول در «%s» تنظیم می‌شود — همان‌جایی که مقدارهای فعلی‌تان ذخیره شده‌اند. فرم جداگانهٔ سئو از این صفحه برداشته شد چون مقدارهای آن افزونه را نمی‌خواند و خالی نشان می‌داد.', 'tecteb-marketplace-core'),
            $plugin
        ))
            . '<p>' . ($editor !== ''
                ? '<a class="tmc-button" href="' . esc_url($editor . '#rank_math_metabox') . '">' . esc_html(sprintf(
                    /* translators: %s: the SEO plugin's name */
                    __('ویرایش سئو در %s', 'tecteb-marketplace-core'),
                    $plugin
                )) . '</a>'
                : esc_html__('هنوز در ووکامرس ساخته نشده است.', 'tecteb-marketplace-core'))
            . '</p>' . self::legacySeo($product) . '</section>';
    }

    /** The old plugin-specific values, shown so nobody thinks they were deleted. */
    private static function legacySeo(Product $product): string
    {
        $rows = array_filter([
            __('نشانی (slug)', 'tecteb-marketplace-core') => trim($product->seo->slug),
            __('عنوان متا', 'tecteb-marketplace-core') => trim($product->seo->title),
            __('توضیح متا', 'tecteb-marketplace-core') => trim($product->seo->description),
        ], static fn (string $v): bool => $v !== '');
        if ($rows === []) {
            return '';
        }
        $out = '<details class="tmc-history"><summary>'
            . esc_html__('دادهٔ سئوی قدیمیِ این افزونه (فقط برای دیدن)', 'tecteb-marketplace-core')
            . '</summary><p class="tmc-card__note">'
            . esc_html__('این مقدارها پاک نشده‌اند و جایی هم کپی نمی‌شوند. اگر می‌خواهید، خودتان آن‌ها را در افزونهٔ سئو بگذارید.', 'tecteb-marketplace-core')
            . '</p><ul>';
        foreach ($rows as $label => $value) {
            $out .= '<li>' . esc_html($label . ': ') . '<code>' . esc_html($value) . '</code></li>';
        }
        return $out . '</ul></details>';
    }

    private function renderPublishPermissions(): void
    {
        $policy = $this->container->get(ProductPublishPolicy::class);
        $granted = $policy->granted();
        echo '<section class="tmc-card"><h2 class="tmc-card__title">' . esc_html__('مجوز انتشار مستقیم', 'tecteb-marketplace-core') . '</h2>'
            . '<p class="tmc-hint">' . esc_html__('این مجوز از «اجازه فروش» جداست: فروشنده‌ای که آن را دارد، محصولش بدون صف بررسی منتشر می‌شود. پیش‌فرض برای همه خاموش است.', 'tecteb-marketplace-core') . '</p>';
        if ($granted === []) {
            echo '<p>' . esc_html__('هیچ فروشنده‌ای این مجوز را ندارد.', 'tecteb-marketplace-core') . '</p>';
        } else {
            echo '<ul class="tmc-list">';
            foreach ($granted as $vendorId) {
                echo '<li>' . Components::code('#' . $vendorId)
                    . ' <form method="post" class="tmc-inline">' . wp_nonce_field(self::NONCE, 'tmc_review_nonce', true, false)
                    . '<input type="hidden" name="subject" value="publishing">'
                    . '<input type="hidden" name="subject_id" value="' . esc_attr((string) $vendorId) . '">'
                    . '<button type="submit" class="tmc-button" name="decision" value="revoke">'
                    . esc_html__('برداشتن مجوز', 'tecteb-marketplace-core') . '</button></form></li>';
            }
            echo '</ul>';
        }
        echo '<form method="post">' . wp_nonce_field(self::NONCE, 'tmc_review_nonce', true, false)
            . '<input type="hidden" name="subject" value="publishing">'
            . '<div class="tmc-field"><label class="tmc-field__label" for="grant-vendor">'
            . esc_html__('شناسه کاربری فروشنده', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tmc-input tmc-input--short" type="text" id="grant-vendor" name="subject_id" dir="ltr" inputmode="numeric"></div>'
            . '<p><button type="submit" class="tmc-button tmc-button--primary" name="decision" value="grant">'
            . esc_html__('صدور مجوز انتشار مستقیم', 'tecteb-marketplace-core') . '</button></p></form></section>';
    }

    /** @return array{code:string,context:array<string,scalar|null>}|null */
    /**
     * One WooCommerce product that has no marketplace row — on its own page.
     *
     * This is where the technical account lives: who the evidence says sells
     * it, what every other plugin has written on it, what an intake could not
     * carry, and the purchase rule its own meta rows already express. The list
     * says ONE sentence per row and sends the manager here for the rest —
     * «توضیح فنی و تکراری به نمای جزئیات منتقل شود».
     *
     * Nothing on this page writes unless a button is pressed, and the only
     * button is the intake's. Opening a product does not import it and does
     * not change its ownership.
     */
    private function renderWooCommerceDetail(Request $request, int $wcId): void
    {
        $state = $this->catalogueState($request);
        $inventory = $this->container->get(CatalogueInventory::class);
        $products = $this->container->get(ProductRepositoryInterface::class);

        echo '<p><a class="tmc-button tmc-button--ghost" href="' . esc_url($state->selfLink()) . '">'
            . esc_html__('بازگشت به فهرست', 'tecteb-marketplace-core') . '</a></p>';

        // A product that HAS a marketplace row is that row's product, and its
        // own page is the review page. Sending the manager there rather than
        // drawing a second, thinner version of it is what keeps «هر محصول
        // مرتبط فقط یک بار» true of the detail view as well as of the list.
        $existing = $products->findByWcProduct($wcId);
        if ($existing !== null) {
            echo Components::notice('info', __('این محصول ردیف بازارگاه دارد و صفحهٔ بررسی‌اش همان است.', 'tecteb-marketplace-core'));
            $this->renderDetail($request, $existing->id);
            return;
        }

        $preview = $inventory->preview($wcId);
        $detail = (array) $preview['detail'];
        if ($detail === []) {
            echo Components::state(
                'empty',
                __('محصولی با این شناسه در ووکامرس نیست.', 'tecteb-marketplace-core'),
                __('ممکن است حذف شده باشد یا این نشانی دستی ساخته شده باشد.', 'tecteb-marketplace-core')
            );
            return;
        }
        $signals = (array) $preview['signals'];
        $decided = CatalogueBucket::decide(
            $signals,
            in_array((string) $detail['type'], [ProductType::SIMPLE, ProductType::VARIABLE], true)
        );
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);

        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html(ProductMessages::displayTitle((string) $detail['title'], $wcId)) . '</h2>';
        echo '<p class="tmc-hint">' . esc_html__('این محصول ردیفی در بازارگاه ندارد. هیچ گاردی از این افزونه دربارهٔ آن نظر نمی‌دهد و باز کردن این صفحه هیچ‌چیزی را عوض نکرد.', 'tecteb-marketplace-core') . '</p>';
        echo '<dl class="tmc-data">'
            . '<dt>' . esc_html__('شناسهٔ ووکامرس', 'tecteb-marketplace-core') . '</dt><dd>'
            . esc_html($fa($wcId)) . '</dd>'
            . '<dt>' . esc_html__('نوع / وضعیت انتشار', 'tecteb-marketplace-core') . '</dt><dd>'
            . esc_html((string) $detail['type']) . ' / '
            . esc_html(ProductMessages::shopStatus((string) $detail['status'])) . '</dd>'
            . '<dt>SKU</dt><dd>' . esc_html((string) $detail['sku'] !== '' ? (string) $detail['sku'] : '—') . '</dd>'
            . '<dt>' . esc_html__('قیمت', 'tecteb-marketplace-core') . '</dt><dd>'
            . esc_html($fa((string) $detail['regular_price'])) . '</dd>'
            . '<dt>' . esc_html__('موجودی', 'tecteb-marketplace-core') . '</dt><dd>'
            . esc_html((string) ($detail['manage_stock'] === true
                ? $fa((string) $detail['stock_quantity'])
                : __('مدیریت موجودی خاموش است', 'tecteb-marketplace-core'))) . '</dd>'
            . '<dt>' . esc_html__('دسته‌بندی مالکیت', 'tecteb-marketplace-core') . '</dt><dd>'
            . esc_html(CatalogueBucket::label((string) $decided['bucket'])) . '</dd>'
            . '</dl>';

        // The evidence, in full. The list prints the author's id and leaves
        // it there; this is where «نویسندهٔ وردپرس به‌تنهایی اثبات
        // فروشنده‌بودن نیست» is actually readable.
        echo '<h3 class="tmc-card__subtitle">'
            . esc_html__('شواهد فروشنده‌بودن', 'tecteb-marketplace-core') . '</h3>';
        echo '<dl class="tmc-data">'
            . '<dt>' . esc_html__('نویسندهٔ پست', 'tecteb-marketplace-core') . '</dt><dd>'
            . esc_html($fa((int) $signals['author_id']))
            . ($signals['author_exists'] === true
                ? ''
                : ' — ' . esc_html__('این کاربر وجود ندارد', 'tecteb-marketplace-core')) . '</dd>'
            . '<dt>' . esc_html__('نقش‌ها', 'tecteb-marketplace-core') . '</dt><dd>'
            . esc_html(implode('، ', array_map('strval', (array) $signals['author_roles'])) ?: '—') . '</dd>'
            . '<dt>' . esc_html__('پروندهٔ فروشندگی', 'tecteb-marketplace-core') . '</dt><dd>'
            . esc_html((string) $signals['vendor_profile'] !== ''
                ? (string) $signals['vendor_profile']
                : __('ندارد', 'tecteb-marketplace-core')) . '</dd>'
            . '<dt>' . esc_html__('فروشندهٔ دکان', 'tecteb-marketplace-core') . '</dt><dd>'
            . esc_html($signals['dokan_seller'] === true
                ? __('هست', 'tecteb-marketplace-core')
                : __('نیست', 'tecteb-marketplace-core')) . '</dd>'
            . '</dl>';

        if ((array) $decided['reasons'] !== []) {
            echo '<ul class="tmc-list">';
            foreach ($this->blockerSentences((array) $decided['reasons']) as $sentence) {
                echo '<li>' . esc_html($sentence) . '</li>';
            }
            echo '</ul>';
            if ((string) $decided['bucket'] === CatalogueBucket::DOKAN) {
                echo '<p><a class="tmc-button" href="'
                    . esc_url(admin_url('admin.php?page=' . MigrationPage::SLUG)) . '">'
                    . esc_html__('مسیر انتقال از دکان', 'tecteb-marketplace-core') . '</a></p>';
            }
        }

        $risks = (array) $preview['risks'];
        echo '<h3 class="tmc-card__subtitle">'
            . esc_html__('آنچه ورود و پذیرش مدیریت نمی‌تواند نگه دارد', 'tecteb-marketplace-core') . '</h3>';
        if ($risks === []) {
            echo '<p>' . esc_html__('هیچ فیلدی در این محصول نیست که فرم این بازارگاه نتواند بیانش کند.', 'tecteb-marketplace-core') . '</p>';
        } else {
            echo '<p>' . esc_html__('ورود هیچ‌کدام از این‌ها را عوض نمی‌کند — ورود فقط یک ردیف «مشاهده‌شده» می‌سازد. این فهرست دربارهٔ پذیرش مدیریت است، که اقدام جداگانه‌ای است و تا تأیید صریح هر مورد انجام نمی‌شود.', 'tecteb-marketplace-core') . '</p>';
            echo '<ul class="tmc-list">';
            foreach ($risks as $sentence) {
                echo '<li>' . esc_html((string) $sentence) . '</li>';
            }
            echo '</ul>';
        }

        $plugins = (array) $preview['plugins'];
        if ($plugins !== []) {
            echo '<h3 class="tmc-card__subtitle">'
                . esc_html__('افزونه‌های دیگری که روی این محصول داده نوشته‌اند', 'tecteb-marketplace-core') . '</h3>';
            echo '<p>' . esc_html__('فقط پیشوند کلیدها و تعدادشان — مقدارها خوانده نمی‌شوند.', 'tecteb-marketplace-core') . '</p>';
            echo '<table class="tmc-table"><thead><tr>'
                . '<th scope="col">' . esc_html__('افزونه / پیشوند', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('تعداد کلید', 'tecteb-marketplace-core') . '</th>'
                . '</tr></thead><tbody>';
            foreach ($plugins as $prefix => $count) {
                echo '<tr><td>' . Components::code((string) $prefix) . '</td><td>'
                    . esc_html($fa((int) $count)) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        if (current_user_can(ProductIntakePage::CAPABILITY)) {
            echo $this->oneProductIntakeForm($wcId, $decided, $request);
        }
        echo '</section>';
    }

    /**
     * The intake, for this one product.
     *
     * The same two verbs as the list — preview and import — on an explicit
     * list of exactly one id. A page that offered «import everything that
     * scanned clean» would be the sweep the instruction forbids.
     *
     * @param array{bucket:string, vendor_user_id:int, reasons:list<string>} $decided
     */
    private function oneProductIntakeForm(int $wcId, array $decided, Request $request): string
    {
        $ready = $decided['reasons'] === []
            && in_array($decided['bucket'], [CatalogueBucket::VENDOR, CatalogueBucket::SHOP], true);
        if (!$ready) {
            return '<p class="tmc-card__note">'
                . esc_html__('این محصول با این شواهد وارد نمی‌شود. دلیلش بالا گفته شده و تا تصمیم مالک، این افزونه فروشنده‌ای برایش حدس نمی‌زند.', 'tecteb-marketplace-core')
                . '</p>';
        }
        $controls = $this->intakeControls($request);
        if ($controls === null) {
            return '';
        }
        return '<form method="post" class="tmc-catalogue__intake">'
            . $controls->nonceField
            . '<input type="hidden" name="wc_ids[]" value="' . esc_attr((string) $wcId) . '">'
            . '<h3 class="tmc-card__subtitle">'
            . esc_html__('ورود این محصول به بازارگاه', 'tecteb-marketplace-core') . '</h3>'
            . '<p class="tmc-hint">' . esc_html__('«ورود» یک ردیف بازارگاه می‌سازد که به همین محصول اشاره می‌کند، با مالکیت «مشاهده‌شده». شناسه، نشانی، وضعیت انتشار، نویسنده، قیمت، موجودی، تصویرها و سفارش‌های قبلی دست‌نخورده می‌مانند.', 'tecteb-marketplace-core') . '</p>'
            . '<div class="tmc-field">'
            . '<label class="tmc-field__label" for="tmc-one-shop">'
            . esc_html__('فروشگاهی که این محصول به آن تعلق دارد — با نام جست‌وجو کنید:', 'tecteb-marketplace-core')
            . '</label>'
            . '<input class="tmc-input" type="search" id="tmc-one-shop" name="shop_q"'
            . ' value="' . esc_attr($controls->shopQuery) . '">'
            . ' <button type="submit" class="tmc-button" name="intake_action" value="find_shop">'
            . esc_html__('جست‌وجوی فروشگاه', 'tecteb-marketplace-core') . '</button></div>'
            . self::shopChoices($controls)
            . '<p><button type="submit" class="tmc-button" name="intake_action" value="preview">'
            . esc_html__('پیش‌نمایش (هیچ نوشتنی)', 'tecteb-marketplace-core') . '</button> '
            . '<button type="submit" class="tmc-button tmc-button--primary" name="intake_action" value="import">'
            . esc_html__('ورود این محصول', 'tecteb-marketplace-core') . '</button></p>'
            . '</form>';
    }

    /** The shop radios, drawn the same way here and in the list. */
    private static function shopChoices(CatalogueIntakeControls $controls): string
    {
        if ($controls->shopMatches === []) {
            return $controls->shopQuery === ''
                ? ''
                : '<p class="tmc-card__note">'
                    . esc_html__('فروشگاهی با این نام پیدا نشد.', 'tecteb-marketplace-core') . '</p>';
        }
        $html = '<fieldset class="tmc-fieldset"><legend>'
            . esc_html__('کدام فروشگاه؟', 'tecteb-marketplace-core') . '</legend>';
        foreach ($controls->shopMatches as $shop) {
            $id = (int) $shop['vendor_user_id'];
            $html .= '<label class="tmc-choice"><input type="radio" name="assign_to"'
                . ' value="' . esc_attr((string) $id) . '"'
                . ($id === $controls->assignTo ? ' checked' : '') . '> '
                . esc_html((string) $shop['store_name']) . '</label>';
        }
        return $html . '</fieldset>';
    }

    /**
     * «پذیرش مدیریت بازارگاه» for an observed row — the explicit second act.
     *
     * An intake makes a row that only WATCHES: nothing of this plugin's
     * decides anything about the product, which is why it is safe to do in
     * batches. Accepting management is the moment that changes, so it is a
     * separate button with its own preview — and from `alpha.56` three things
     * stand between the button and the write:
     *
     *   1. the risks: fields this marketplace's form cannot express, each
     *      named, each acknowledged by key («حذف یا تبدیل ضمنی نکن»);
     *   2. the drift: what has changed in WooCommerce SINCE the intake, with
     *      the fingerprint of each shown value travelling back, so the
     *      permission is about the numbers that were printed and not about
     *      whatever is there when the form arrives (`alpha.49`, `alpha.55`);
     *   3. a read that failed is neither of those and is not «they agree»: it
     *      is said out loud and acceptance refuses.
     *
     * Rendered only for an observed row. An owned one has nothing to accept
     * and a product with no link has nothing to accept it for.
     */
    private function ownershipPanel(Product $product): string
    {
        $wcId = (int) ($product->wcProductId ?? 0);
        if ($wcId <= 0) {
            return '';
        }
        if ($product->ownsItsStorefrontProduct()) {
            // The other direction, and only for a product this plugin
            // CLAIMED rather than created. `_tmc_claimed_from_status` is
            // written by `claimStorefrontPost()` alone and holds the status
            // the post had before, so it is the one honest marker of «this
            // was somebody else's product and can be handed back». Offering
            // it for a product the marketplace created would be offering to
            // make a vendor's own listing inert.
            if ((string) get_post_meta($wcId, WooCommerceProjector::CLAIMED_FROM_STATUS_META, true) === '') {
                return '';
            }
            return '<section class="tmc-card"><h2 class="tmc-card__title">'
                . esc_html__('پس‌گرفتن مدیریت بازارگاه', 'tecteb-marketplace-core') . '</h2>'
                . '<p class="tmc-hint">' . esc_html__('این محصول پیش از ورود مال فروشگاه بود و مدیریتش به بازارگاه منتقل شده. پس‌گرفتن، ردیف را به «فقط مشاهده‌شده» برمی‌گرداند: از آن لحظه هیچ گاردی از این افزونه دربارهٔ آن نظر نمی‌دهد. محصول ووکامرس، قیمت، موجودی و سفارش‌های قبلی دست نمی‌خورند.', 'tecteb-marketplace-core') . '</p>'
                . '<p><form method="post" class="tmc-inline-form">'
                . wp_nonce_field(self::NONCE, 'tmc_ownership_nonce', true, false)
                . '<input type="hidden" name="product_id" value="' . esc_attr((string) $product->id) . '">'
                . '<button type="submit" class="tmc-button" name="ownership_action" value="give_back">'
                . esc_html__('پس‌گرفتن مدیریت', 'tecteb-marketplace-core')
                . '</button></form></p></section>';
        }
        $transfer = $this->container->get(TransferOwnership::class);
        $risks = $transfer->risksFor($product->id);
        $readable = $transfer->storefrontReadable($product->id);
        $drift = $readable ? $transfer->storefrontDrift($product->id) : [];

        $html = '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('پذیرش مدیریت بازارگاه', 'tecteb-marketplace-core') . '</h2>'
            . '<p class="tmc-hint">' . esc_html__('این ردیف فقط به محصول ووکامرس اشاره می‌کند و هیچ گاردی از این افزونه دربارهٔ آن نظر نمی‌دهد. پذیرش مدیریت یعنی از آن لحظه بازارگاه تصمیم می‌گیرد این محصول فروخته شود یا نه؛ توقف فروش آن را به پیش‌نویس می‌برد و گارد خرید دربارهٔ آن نظر می‌دهد. محصول ووکامرس با این کار بازنویسی نمی‌شود و همین دکمه در جهت عکس هم کار می‌کند.', 'tecteb-marketplace-core') . '</p>';

        if (!$readable) {
            $html .= Components::notice('warning', __('مقدارهای فعلی این محصول در ووکامرس خوانده نشد، پس معلوم نیست مبنای ورود از آن موقع کهنه شده یا نه. تا خوانده نشود پذیرش انجام نمی‌شود — «نمی‌دانم» با «یکی‌اند» یکی نیست.', 'tecteb-marketplace-core'));
        }
        if ($risks !== []) {
            $html .= '<h3 class="tmc-card__subtitle">'
                . esc_html__('آنچه پذیرش عوض می‌کند یا نمی‌تواند نگه دارد', 'tecteb-marketplace-core')
                . '</h3><ul class="tmc-list">';
            foreach ($risks as $sentence) {
                $html .= '<li>' . esc_html((string) $sentence) . '</li>';
            }
            $html .= '</ul>';
        }
        if ($drift !== []) {
            $html .= '<h3 class="tmc-card__subtitle">'
                . esc_html__('از زمان ورود، این مقدارها در ووکامرس عوض شده‌اند', 'tecteb-marketplace-core')
                . '</h3><table class="tmc-table"><thead><tr>'
                . '<th scope="col">' . esc_html__('فیلد', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('مبنای ورود', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('مقدار فعلی فروشگاه', 'tecteb-marketplace-core') . '</th>'
                . '</tr></thead><tbody>';
            foreach ($drift as $pair) {
                $html .= '<tr><td>' . esc_html((string) $pair['label']) . '</td>'
                    . '<td>' . esc_html((string) $pair['row'] !== '' ? (string) $pair['row'] : '—') . '</td>'
                    . '<td>' . esc_html((string) $pair['shop'] !== '' ? (string) $pair['shop'] : '—') . '</td></tr>';
            }
            $html .= '</tbody></table>'
                . '<p>' . esc_html__('پذیرش با وجود این تفاوت‌ها، مقدار مبنای ورود را مرجع می‌کند و اولین همگام‌سازی مقدار فعلی فروشگاه را بازنویسی می‌کند. اگر مقدار فعلی درست است، اول مبنا را تازه کنید.', 'tecteb-marketplace-core') . '</p>';
        }

        $accept = '<form method="post" class="tmc-inline-form">'
            . wp_nonce_field(self::NONCE, 'tmc_ownership_nonce', true, false)
            . '<input type="hidden" name="product_id" value="' . esc_attr((string) $product->id) . '">';
        foreach (array_keys($risks) as $key) {
            // The key travels with the tick, so a form that did not print
            // this risk cannot acknowledge it.
            $accept .= '<input type="hidden" name="acknowledged[]" value="' . esc_attr((string) $key) . '">';
        }
        foreach ($drift as $field => $pair) {
            // And the VALUE travels with it, as the fingerprint the drift
            // itself handed us: a permission about «whatever is there when
            // the form arrives» is not a permission.
            $accept .= '<input type="hidden" name="seen_shop[' . esc_attr((string) $field) . ']"'
                . ' value="' . esc_attr((string) $pair['fingerprint']) . '">';
        }
        $accept .= '<button type="submit" class="tmc-button tmc-button--primary"'
            . ' name="ownership_action" value="take"' . ($readable ? '' : ' disabled') . '>'
            . ($risks === [] && $drift === []
                ? esc_html__('پذیرش مدیریت بازارگاه', 'tecteb-marketplace-core')
                : esc_html__('با وجود این موارد، مدیریت را بپذیر', 'tecteb-marketplace-core'))
            . '</button></form>';

        if ($drift !== []) {
            // The other way out, and the one that loses nothing: take the
            // snapshot again and there is no difference left to accept.
            $accept .= '<form method="post" class="tmc-inline-form">'
                . wp_nonce_field(self::NONCE, 'tmc_ownership_nonce', true, false)
                . '<input type="hidden" name="product_id" value="' . esc_attr((string) $product->id) . '">'
                . '<button type="submit" class="tmc-button" name="ownership_action" value="refresh">'
                . esc_html__('مبنا را از فروشگاه تازه کن', 'tecteb-marketplace-core')
                . '</button></form>';
        }
        return $html . '<p>' . $accept . '</p></section>';
    }

    /**
     * The ownership buttons, answered.
     *
     * Its own handler and its own nonce, because it is its own decision: a
     * review verb and «this product is ours now» are not the same kind of
     * act, and a page where one form's nonce authorises the other is a page
     * where a rendered review form carries a permission nobody asked for
     * (`alpha.46` measured exactly that on the WooCommerce panel).
     *
     * @return string `''`, or `ok:`/`err:` and a sentence
     */
    private function handleOwnership(Request $request): string
    {
        if (!$request->isPost() || !$request->hasPost('ownership_action')) {
            return '';
        }
        if (!current_user_can(ProductIntakePage::CAPABILITY)) {
            return 'err:' . __('دسترسی لازم برای پذیرش مدیریت را ندارید.', 'tecteb-marketplace-core');
        }
        if (!$request->nonceOk('tmc_ownership_nonce', self::NONCE)) {
            return 'err:' . __('درخواست معتبر نبود. صفحه را تازه کنید و دوباره تلاش کنید.', 'tecteb-marketplace-core');
        }
        $transfer = $this->container->get(TransferOwnership::class);
        $productId = $request->postInt('product_id');
        $action = $request->postKey('ownership_action');

        if ($action === 'refresh') {
            $result = $transfer->refreshFromStorefront($productId);
            return $result->ok
                ? 'ok:' . sprintf(
                    /* translators: %s: how many fields were brought up to date */
                    __('مبنای توافق از مقدارهای فعلی ووکامرس تازه شد (%s فیلد). محصول ووکامرس عوض نشد.', 'tecteb-marketplace-core'),
                    PersianDigits::toPersian((string) ($result->context['refreshed'] ?? 0))
                )
                : 'err:' . __('تازه‌کردن مبنا انجام نشد: ', 'tecteb-marketplace-core') . $result->code;
        }
        if ($action === 'give_back') {
            $result = $transfer->giveBack($productId);
            return $result->ok
                ? 'ok:' . __('مالکیت عملیاتی پس گرفته شد و این ردیف دوباره فقط نگاشت است.', 'tecteb-marketplace-core')
                : 'err:' . __('پس‌گرفتن مالکیت انجام نشد: ', 'tecteb-marketplace-core') . $result->code;
        }
        if ($action !== 'take') {
            return 'err:' . __('اقدام ناشناخته بود.', 'tecteb-marketplace-core');
        }

        $result = $transfer->take(
            $productId,
            $request->postTextList('acknowledged'),
            $request->postMap('seen_shop')
        );
        if ($result->ok) {
            return 'ok:' . __('مدیریت این محصول به بازارگاه منتقل شد. از این پس توقف فروش و گارد خرید دربارهٔ آن تصمیم می‌گیرند و محصول ووکامرس بازنویسی نشد.', 'tecteb-marketplace-core');
        }
        // Four refusals, four next steps. One sentence for all of them would
        // send three of the four readers to the wrong place.
        return 'err:' . match ($result->code) {
            'ownership_risks_unacknowledged' => sprintf(
                /* translators: %s: the sentences for the fields at risk */
                __('پذیرش انجام نشد، چون این موارد تأیید نشده بودند: %s صفحه را تازه کنید و دکمهٔ «با وجود این موارد» را بزنید.', 'tecteb-marketplace-core'),
                implode(' ', array_map('strval', (array) ($result->context['sentences'] ?? [])))
            ),
            'storefront_moved' => sprintf(
                /* translators: %s: the names of the fields that moved */
                __('پذیرش انجام نشد: از زمان ورود این مقدارها در ووکامرس عوض شده‌اند و این فرم آن‌ها را حمل نمی‌کرد: %s. صفحه را تازه کنید تا مقدارهای فعلی را ببینید.', 'tecteb-marketplace-core'),
                (string) ($result->context['fields'] ?? '')
            ),
            'storefront_moved_again' => sprintf(
                /* translators: 1: field, 2: the value the shop holds now */
                __('پذیرش انجام نشد: «%1$s» بین نمایش صفحه و زدن دکمه عوض شد — مقدار فعلی فروشگاه %2$s است. آن مقدار بازنویسی نشد؛ صفحه را تازه کنید.', 'tecteb-marketplace-core'),
                (string) ($result->context['field'] ?? ''),
                PersianDigits::toPersian((string) ($result->context['now'] ?? ''))
            ),
            'storefront_unreadable' => __('پذیرش انجام نشد: مقدارهای فعلی این محصول در ووکامرس خوانده نشد، پس معلوم نیست مبنای ورود کهنه است یا نه.', 'tecteb-marketplace-core'),
            default => __('این تغییر مالکیت انجام نشد: ', 'tecteb-marketplace-core') . $result->code,
        };
    }

    /**
     * The intake's three buttons: find a shop, preview, import.
     *
     * **Separate from `handleAction()` on purpose.** That one maps a subject
     * and a decision onto a review verb and returns ONE code, which
     * `ProductMessages` turns into one sentence. An intake of twelve products
     * has twelve answers, and a count on its own would leave a manager hunting
     * for the ones that did not go — so this returns the sentences, product by
     * product, the way the intake screen has since `alpha.55`.
     *
     * **«find a shop» writes nothing and must not look like it did.** It is a
     * submit because the search has to work with JavaScript off, and the whole
     * form is posted so what was already ticked stays ticked — the trick the
     * category picker has used since `alpha.25`. It returns no notice at all:
     * the results appear in the form, and a success message about a search
     * would read like a success message about an import.
     *
     * @return string `''`, or `ok:`/`err:` and a sentence
     */
    private function handleIntake(Request $request): string
    {
        if (!$request->isPost() || !$request->hasPost('intake_action')) {
            return '';
        }
        $action = $request->postKey('intake_action');
        if ($action === 'find_shop') {
            return '';
        }
        // The capability is the intake's own, checked here and not only in
        // the view: a page that drew no button would still have accepted the
        // POST. «ورود و پذیرش صریح و مجاز» is about permission, not markup.
        if (!current_user_can(ProductIntakePage::CAPABILITY)) {
            return 'err:' . __('دسترسی لازم برای ورود محصول را ندارید.', 'tecteb-marketplace-core');
        }
        if (!$request->nonceOk('_wpnonce', self::NONCE)) {
            return 'err:' . __('درخواست معتبر نبود. صفحه را تازه کنید و دوباره تلاش کنید.', 'tecteb-marketplace-core');
        }

        $intake = $this->container->get(ImportExistingProducts::class);
        $ids = [];
        foreach ($request->postTextList('wc_ids') as $raw) {
            $id = (int) $raw;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return 'err:' . __('هیچ محصولی انتخاب نشده بود.', 'tecteb-marketplace-core');
        }
        $assignTo = $request->postInt('assign_to');

        if ($action === 'preview') {
            return 'ok:' . __('پیش‌نمایش (هیچ نوشتنی انجام نشد): ', 'tecteb-marketplace-core')
                . self::intakeSentences($intake->preview($ids, $assignTo));
        }
        if ($action !== 'import') {
            return 'err:' . __('اقدام ناشناخته بود.', 'tecteb-marketplace-core');
        }

        $batch = $intake->importBatch($intake->newRunId(), $ids, $assignTo);
        if (($batch['code'] ?? '') === 'forbidden') {
            return 'err:' . __('دسترسی لازم را ندارید.', 'tecteb-marketplace-core');
        }
        $counts = $batch['counts'] ?? ['imported' => 0, 'skipped' => 0, 'failed' => 0];
        return (($batch['ok'] ?? false) ? 'ok:' : 'err:') . sprintf(
            /* translators: 1: imported, 2: skipped, 3: failed, 4: run id */
            __('%1$s وارد شد، %2$s رد شد، %3$s انجام نشد. شناسهٔ اجرا: %4$s. ', 'tecteb-marketplace-core'),
            PersianDigits::toPersian((string) $counts['imported']),
            PersianDigits::toPersian((string) $counts['skipped']),
            PersianDigits::toPersian((string) $counts['failed']),
            (string) ($batch['run_id'] ?? '')
        ) . self::intakeSentences($batch['results'] ?? []);
    }

    /**
     * Each product's verdict, named.
     *
     * A count on its own would leave a manager hunting for the ones that did
     * not go, which is the whole reason the intake answers per product.
     *
     * @param list<array<string,mixed>> $rows
     */
    private static function intakeSentences(array $rows): string
    {
        $sentences = ImportExistingProducts::reasonSentences();
        $parts = [];
        foreach ($rows as $row) {
            $reason = (string) ($row['reason'] ?? '');
            $parts[] = sprintf(
                '#%s: %s',
                PersianDigits::toPersian((string) ($row['wc_product_id'] ?? 0)),
                $reason === ''
                    ? (string) ($row['verdict'] ?? '')
                    : ($sentences[$reason] ?? $reason)
            );
        }
        return implode(' | ', array_slice($parts, 0, 25));
    }

    private function handleAction(Request $request): ?array
    {
        if (!$request->isPost() || !$request->hasPost('decision')) {
            return null;
        }
        if (!$request->nonceOk('tmc_review_nonce', self::NONCE)) {
            return ['code' => 'forbidden', 'context' => []];
        }
        $review = $this->container->get(ReviewProducts::class);
        $id = $request->postInt('subject_id');
        $note = $request->postTextarea('note');
        $result = match ($request->postKey('subject') . ':' . $request->postKey('decision')) {
            // The fingerprints the review screen rendered travel back with the
            // decision. A manager can fix a typo in WooCommerce while this
            // page is open, and an approval that wrote over it would undo an
            // edit nobody was shown.
            'product:approve' => $review->approve($id, self::seenFingerprints($request)),
            'product:changes' => $review->requestChanges($id, $note),
            'product:reject' => $review->reject($id, $note),
            'revision:approve' => $review->approveRevision(
                $id,
                self::seenFingerprints($request),
                // An unticked checkbox is not sent, so absence IS the
                // conservative answer and nothing has to default it.
                $request->postChecked('accept_stock_conflict'),
                // The number the page printed beside that checkbox. Only when
                // the form actually carried it: a missing field is a refusal
                // on the override path, never a zero — existence and value are
                // two questions (`alpha.28`).
                ($request->hasPost('seen_stock') ? $request->postInt('seen_stock') : null),
                // The brand's half of the same question, and the brand the
                // page printed beside it. Existence and value stay two
                // questions here too: a form that carries no `seen_brand` is
                // refused on the override path rather than read as «no brand».
                $request->postChecked('accept_brand_conflict'),
                // Parenthesised, because PHPCompatibility is right: two
                // ternaries as consecutive arguments read as nested ones to a
                // human even though PHP parses them apart, and this round's
                // run flagged exactly that at this line.
                ($request->hasPost('seen_brand') ? $request->postText('seen_brand') : null)
            ),
            'revision:reject' => $review->rejectRevision($id, $note),
            'seo:save' => $review->setSeo(
                $id,
                $request->postText('seo_slug'),
                $request->postText('seo_title'),
                $request->postTextarea('seo_description')
            ),
            'publishing:grant' => $review->setDirectPublishing($id, true),
            'publishing:revoke' => $review->setDirectPublishing($id, false),
            'storefront:prepare' => $review->prepareStorefront($id),
            'correct:save' => $review->correct($id, [
                'title' => $request->postText('fix_title'),
                'short_description' => $request->postTextarea('fix_short'),
                'category' => $request->postText('fix_category'),
            ]),
            'field:keep', 'field:accept' => $review->resolveField(
                $id,
                $request->postKey('field'),
                $request->postKey('decision')
            ),
            'product_fields:keep', 'product_fields:accept' => $review->resolveProduct(
                $id,
                $request->postKey('decision')
            ),
            // THE TWO HALVES OF A BRAND REQUEST, as two verbs.
            //
            // `approve` registers the name and then applies it; `finish` does
            // only the second half, which is what makes a half-finished
            // approval retryable instead of stuck — «ثبت برند و اعمال آن روی
            // محصول باید قابل تفکیک و تلاش دوباره باشد».
            'brand:approve' => $this->container->get(ManageBrands::class)->approve($id, $request->postText('brand_name')),
            'brand:finish' => $this->container->get(ManageBrands::class)->finish($id),
            'brand:reject' => $this->container->get(ManageBrands::class)->reject($id, $note),
            default => null,
        };
        return $result === null ? null : ['code' => $result->code, 'context' => $result->context];
    }
}
