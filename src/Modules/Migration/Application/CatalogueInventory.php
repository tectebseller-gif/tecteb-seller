<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Modules\Migration\Domain\CatalogueBucket;
use Tecteb\Marketplace\Modules\Migration\Domain\CatalogueFacets;

/**
 * What is already in this shop, and who — if anybody — sells it.
 *
 * Read-only, a page at a time, and it writes nothing: «پیش‌نمایش فقط‌خواندنی و
 * صفحه‌بندی‌شده». It is the first half of bringing existing products into the
 * marketplace, and it is deliberately a SEPARATE service from the intake,
 * because the instruction separates the two acts: being listed is not being
 * managed.
 *
 * ---------------------------------------------------------------------------
 * THE FOUR BUCKETS, AND THE FIFTH THAT MATTERS MOST
 * ---------------------------------------------------------------------------
 *
 *   MARKETPLACE  a marketplace row owns it. Nothing to do.
 *   OBSERVED     a marketplace row points at it and nothing follows. This is
 *                what an intake produces, and what `TransferOwnership` turns
 *                into the first.
 *   DOKAN        its author is a Dokan seller. Dokan runs it today.
 *   SHOP         its author is an administrator or shop manager with no
 *                vendor paperwork: the main shop's own product.
 *   AMBIGUOUS    anything else — and this bucket is the point of the whole
 *                class.
 *
 * «نویسندهٔ وردپرس به‌تنهایی اثبات فروشنده‌بودن نیست» is a rule about the
 * fourth and fifth. An author who is a plain customer, an author whose user
 * has been deleted, an author who is BOTH a Dokan seller and a marketplace
 * vendor, a product two marketplace rows point at, a vendor application that
 * was rejected or suspended: each of those is a question for a person, and
 * every one of them answers `ambiguous` with the reason named.
 *
 * And an ambiguous product does not hold up the rest: «موارد مبهم باید در
 * پیش‌نمایش متوقف شوند و مانع ورود موارد سالم و مستقل نشوند». So this reports
 * per product, and the intake takes a list of ids — never «everything that
 * scanned clean».
 *
 * ---------------------------------------------------------------------------
 * WHAT IT WILL NOT DO
 * ---------------------------------------------------------------------------
 *
 * It never picks a vendor for a product that has none. «محصول فروشگاه اصلی را
 * به فروشندهٔ تصادفی یا مدیر واردشده نسبت نده» — so a `shop` product's vendor
 * is reported as `0` with `needs_decision` beside it, and the intake refuses
 * it by name until somebody says whose it is. The decision is the owner's and
 * this class's job is to make the question precise, not to answer it.
 */
final class CatalogueInventory
{
    // The names are `CatalogueBucket`'s, aliased here because this class's
    // callers have used them since `alpha.55`. One source of truth, two
    // spellings — and `CatalogueBucket` is the one that decides.
    public const BUCKET_MARKETPLACE = CatalogueBucket::MARKETPLACE;
    public const BUCKET_OBSERVED = CatalogueBucket::OBSERVED;
    public const BUCKET_DOKAN = CatalogueBucket::DOKAN;
    public const BUCKET_SHOP = CatalogueBucket::SHOP;

    /**
     * An approved marketplace vendor's product with no marketplace row.
     *
     * A fifth bucket, and it is here because the four the instruction names
     * do not cover it: this is not the main shop's own product and not
     * Dokan's, it is a product belonging to somebody who is already an
     * approved vendor here and simply has no row for it. It is the ONE case
     * an intake can take without a decision from the owner, so it gets a
     * name of its own rather than being filed under «ambiguous», which would
     * make the only workable case look like a problem.
     */
    public const BUCKET_VENDOR = CatalogueBucket::VENDOR;

    public const BUCKET_AMBIGUOUS = CatalogueBucket::AMBIGUOUS;

    /** How many products one page of the inventory looks at. */
    public const PAGE = 50;

    public function __construct(private readonly CatalogueReaderInterface $reader)
    {
    }

    /**
     * One page of the inventory: the counts, then the rows, then the
     * site-wide ambiguities.
     *
     * The counts come from `COUNT(*)` and do not depend on the page, so a
     * manager sees the whole shape of the catalogue on the first screen and
     * walks the rows at their own pace.
     *
     * @return array<string,mixed>
     */
    public function page(int $afterId = 0, int $limit = self::PAGE): array
    {
        if (!$this->reader->isAvailable()) {
            return ['available' => false, 'counts' => [], 'rows' => [], 'cursor' => 0, 'done' => true];
        }
        $page = $this->reader->productsAfter($afterId, $limit);
        $rows = [];
        $buckets = [
            self::BUCKET_MARKETPLACE => 0,
            self::BUCKET_OBSERVED => 0,
            self::BUCKET_DOKAN => 0,
            self::BUCKET_SHOP => 0,
            self::BUCKET_VENDOR => 0,
            self::BUCKET_AMBIGUOUS => 0,
        ];
        foreach ($page['rows'] as $row) {
            $classified = $this->classify($row);
            $buckets[$classified['bucket']]++;
            $rows[] = $classified;
        }
        return [
            'available' => true,
            'counts' => $this->reader->counts(),
            'buckets_on_this_page' => $buckets,
            'rows' => $rows,
            'cursor' => (int) $page['cursor'],
            'done' => (bool) $page['done'],
            'duplicate_links' => $this->reader->duplicateLinks(),
        ];
    }

    /**
     * THE ONE LIST: one page of every product this site has, classified.
     *
     * **Why this replaced two screens.** Until this round a manager had
     * «بررسی محصولات», which listed marketplace rows, and «ورود محصولات
     * موجود», which listed WooCommerce posts. A product on both sides was on
     * both screens with nothing on either saying it was one product, and the
     * only way to find out was to open it. The instruction is one list, «هر
     * محصول مرتبط فقط یک بار», and the intake folded into it.
     *
     * **Showing is not importing.** Every row here is drawn from a read.
     * Nothing about any product changes by looking at this page: no row is
     * created, no author moved, no ownership taken. «نمایش خودکار باشد و
     * ورود یا تغییر مالکیت خودکار انجام نشود» — the intake is a named button
     * on an explicit list of ids, further down the same page.
     *
     * **The bucket of a row with no product is its own.** A marketplace row
     * with no WooCommerce product behind it is either a vendor's draft, which
     * is ordinary, or a row pointing at a post somebody deleted, which is
     * not. Both are `draft_only` and the second says so — `orphan_link` —
     * because no other screen in this plugin can show either.
     *
     * @param array{search?:string, source?:string, wc_status?:string, management?:string} $filters
     * @return array<string,mixed>
     */
    public function unified(array $filters, int $limit, int $offset, string $sort = 'updated'): array
    {
        if (!$this->reader->isAvailable()) {
            // Without WooCommerce there is no post table to union with, so
            // this list cannot be drawn at all — and saying so is better than
            // drawing the marketplace half and calling it the catalogue.
            return ['available' => false, 'rows' => [], 'total' => 0, 'facets' => [], 'buckets' => []];
        }
        $page = $this->reader->unifiedPage($filters, $limit, $offset, $sort);
        $authors = $this->reader->authorSignals(array_map(
            static fn (array $row): int => (int) $row['author_id'],
            $page['rows']
        ));

        $rows = [];
        $buckets = array_fill_keys(CatalogueBucket::ALL, 0);
        foreach ($page['rows'] as $row) {
            $rows[] = $classified = $this->classifyUnified($row, $authors);
            $buckets[$classified['bucket']] = ($buckets[$classified['bucket']] ?? 0) + 1;
        }

        return [
            'available' => true,
            'rows' => $rows,
            'total' => (int) $page['total'],
            'facets' => $page['facets'],
            'buckets' => $buckets,
        ];
    }

    /**
     * One row of the unified list, classified from facts already read.
     *
     * The same `CatalogueBucket::decide()` the detail path calls, fed from the
     * page's batched reads instead of a per-product query. That is the whole
     * reason the decision was lifted out of this class: two gatherings, one
     * rule, so a list saying `dokan` beside a detail page saying `shop` is
     * impossible rather than unlikely.
     *
     * @param array<string,mixed> $row from `unifiedPage()`
     * @param array<int,array<string,mixed>> $authors from `authorSignals()`
     * @return array<string,mixed>
     */
    private function classifyUnified(array $row, array $authors): array
    {
        $wcId = (int) $row['wc_product_id'];
        $author = (int) $row['author_id'];
        $signals = $authors[$author] ?? [
            'author_id' => $author,
            'author_exists' => false,
            'author_roles' => [],
            'author_is_admin' => false,
            'dokan_seller' => false,
            'vendor_profile' => '',
        ];
        $signals['marketplace_rows'] = (array) ($row['marketplace_rows'] ?? []);

        if ($wcId === 0) {
            // A marketplace row with no product behind it. There is no author
            // to read and no WooCommerce state to classify: the row itself
            // says whose it is.
            $bucket = CatalogueBucket::DRAFT_ONLY;
            $vendor = (int) $row['vendor_user_id'];
            $reasons = ($row['orphan_link'] ?? false) === true ? ['storefront_post_missing'] : [];
        } else {
            $decided = CatalogueBucket::decide($signals, ($row['type_supported'] ?? false) === true);
            $bucket = $decided['bucket'];
            $vendor = $decided['vendor_user_id'];
            $reasons = $decided['reasons'];
        }

        return $row + [
            'bucket' => $bucket,
            'bucket_label' => CatalogueBucket::label($bucket),
            'source_label' => CatalogueFacets::sourceLabel((string) $row['source']),
            'management_label' => CatalogueFacets::managementLabel((string) $row['management']),
            'vendor_user_id_decided' => $vendor,
            'author_roles' => $signals['author_roles'],
            'vendor_profile' => (string) $signals['vendor_profile'],
            'dokan_seller' => (bool) $signals['dokan_seller'],
            'reasons' => $reasons,
            // The same narrow rule `classify()` states: exactly one bucket
            // may be taken with no decision from anybody.
            'intake_ready' => $bucket === self::BUCKET_VENDOR && $reasons === [],
        ];
    }

    /**
     * One product, classified, with its evidence and its blockers.
     *
     * @param array<string,mixed> $row from `productsAfter()`
     * @return array<string,mixed>
     */
    /**
     * Published in WooCommerce, priced nowhere — reported and nothing else.
     *
     * A passthrough and not a decision, because §1-هـ is explicit that the
     * public product must not change: «محصول منتشرشدهٔ بی‌قیمت را جداگانه
     * گزارش کن، بدون تغییر وضعیت عمومی یا قابلیت خریدش». There is deliberately
     * no companion that fixes one.
     *
     * @return list<array{wc_product_id:int, product_id:int, title:string, shop_price:string, ownership:string}>
     */
    public function pricelessPublished(int $limit = 100): array
    {
        return $this->reader->isAvailable() ? $this->reader->publishedWithoutPrice($limit) : [];
    }

    public function classify(array $row): array
    {
        $wcId = (int) ($row['wc_product_id'] ?? 0);
        $signals = $this->reader->vendorSignals($wcId);
        $links = $signals['marketplace_rows'];
        // ONE decision, asked two ways. This path reads everything about one
        // product; the manager's list gathers the same facts for a whole page
        // in a handful of statements. The ORDER OF THE RULES is the decision
        // and it lives in `CatalogueBucket` so the two cannot drift apart —
        // a list saying `dokan` beside a detail page saying `shop` would be
        // the `alpha.14` mistake in a place a manager acts on.
        $decided = CatalogueBucket::decide($signals, ($row['type_supported'] ?? false) === true);
        $bucket = $decided['bucket'];
        $vendor = $decided['vendor_user_id'];
        $reasons = $decided['reasons'];

        return [
            'wc_product_id' => $wcId,
            'title' => (string) ($row['title'] ?? ''),
            'status' => (string) ($row['post_status'] ?? ''),
            'type' => (string) ($row['type'] ?? ''),
            'type_supported' => (bool) ($row['type_supported'] ?? false),
            'variations' => (int) ($row['variations'] ?? 0),
            'sku' => (string) ($row['sku'] ?? ''),
            'author_id' => (int) $signals['author_id'],
            'author_roles' => $signals['author_roles'],
            'vendor_profile' => (string) $signals['vendor_profile'],
            'dokan_seller' => (bool) $signals['dokan_seller'],
            'marketplace_rows' => $links,
            'bucket' => $bucket,
            'vendor_user_id' => $vendor,
            'reasons' => $reasons,
            'plugins' => $this->reader->pluginKeys($wcId),
            // Whether an intake could run on this product AS IT STANDS,
            // with no decision from anybody. A `false` here is never a
            // failure: it is a question with a name, in `reasons`.
            //
            // Exactly one bucket qualifies, and the narrowness is the point:
            // a product this marketplace already owns needs nothing, an
            // observed one has had its intake, a Dokan one has its own path,
            // the shop's own needs an owner, and an ambiguous one is a
            // question. A product whose vendor the MANAGER names explicitly
            // is a different call — see `ImportExistingProducts::assignTo`.
            'intake_ready' => $bucket === self::BUCKET_VENDOR && $reasons === [],
        ];
    }

    /**
     * What one product would look like after an intake, and what an intake
     * could not carry — both read-only.
     *
     * @return array<string,mixed>
     */
    public function preview(int $wcProductId): array
    {
        return [
            'detail' => $this->reader->detail($wcProductId),
            'signals' => $this->reader->vendorSignals($wcProductId),
            'risks' => $this->reader->preservationRisks($wcProductId),
            'plugins' => $this->reader->pluginKeys($wcProductId),
        ];
    }

    /** The reasons, each with the sentence a manager reads. */
    public static function reasonSentences(): array
    {
        return [
            'duplicate_marketplace_link' =>
                'بیش از یک ردیف بازارگاه به این محصول اشاره می‌کند. کدام ردیف واقعی است، تصمیمی دربارهٔ کاتالوگ یک نفر است.',
            'seller_in_both_systems' =>
                'نویسندهٔ این محصول هم فروشندهٔ دکان است و هم پروندهٔ فروشندگی در بازارگاه دارد. اینکه کدام سیستم مالک محصول باشد، تصمیم مالک است.',
            'author_missing' =>
                'کاربر نویسندهٔ این محصول وجود ندارد، پس هیچ شاهدی از فروشنده‌بودن نیست.',
            'dokan_product_has_its_own_path' =>
                'این محصول مال فروشندهٔ دکان است و مسیر ورودش «انتقال از دکان» است، نه این صفحه — آن مسیر جدول‌های خودِ دکان را هم می‌خواند.',
            'shop_product_needs_owner_decision' =>
                'نویسنده مدیر یا مدیر فروشگاه است، پس این محصول خودِ فروشگاه اصلی است. این بازارگاه فروشنده‌ای برایش حدس نمی‌زند.',
            'author_is_not_a_vendor' =>
                'نویسنده نه فروشندهٔ دکان است، نه پروندهٔ فروشندگی دارد، نه مدیر است. فروشنده نامشخص است.',
            'type_not_supported' =>
                'نوع این محصول در فرم این بازارگاه وجود ندارد و تبدیل نمی‌شود.',
            'vendor_application_submitted' =>
                'پروندهٔ فروشندگی نویسنده ارسال شده ولی تأیید نشده. ارسال، اجازهٔ فروش نیست.',
            'vendor_application_in_review' =>
                'پروندهٔ فروشندگی نویسنده در حال بررسی است.',
            'vendor_application_changes_requested' =>
                'پروندهٔ فروشندگی نویسنده نیازمند اصلاح است.',
            'vendor_application_rejected' =>
                'پروندهٔ فروشندگی نویسنده رد شده است.',
            'vendor_application_suspended' =>
                'فروشندهٔ این محصول تعلیق شده است.',
            'vendor_application_draft' =>
                'پروندهٔ فروشندگی نویسنده پیش‌نویس است و ارسال نشده.',
            'storefront_post_missing' =>
                'این ردیف بازارگاه به محصولی اشاره می‌کند که در ووکامرس نیست. ردیف دست نمی‌خورد و این صفحه فقط نشانش می‌دهد.',
            'vendor_application_profile_without_application' =>
                'نویسنده پروفایل فروشگاه دارد ولی پروندهٔ درخواستی ندارد (فروشگاه واردشده یا دستی).',
        ];
    }
}
