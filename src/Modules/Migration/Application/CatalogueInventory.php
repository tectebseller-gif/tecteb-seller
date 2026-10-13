<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;

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
    public const BUCKET_MARKETPLACE = 'marketplace';
    public const BUCKET_OBSERVED = 'observed';
    public const BUCKET_DOKAN = 'dokan';
    public const BUCKET_SHOP = 'shop';

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
    public const BUCKET_VENDOR = 'vendor';

    public const BUCKET_AMBIGUOUS = 'ambiguous';

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
     * One product, classified, with its evidence and its blockers.
     *
     * @param array<string,mixed> $row from `productsAfter()`
     * @return array<string,mixed>
     */
    public function classify(array $row): array
    {
        $wcId = (int) ($row['wc_product_id'] ?? 0);
        $signals = $this->reader->vendorSignals($wcId);
        $links = $signals['marketplace_rows'];
        $reasons = [];
        $vendor = 0;
        $bucket = self::BUCKET_AMBIGUOUS;

        if (count($links) > 1) {
            // Two rows for one product: which is the real one is a decision
            // about somebody's catalogue, so it is reported and nothing else.
            $reasons[] = 'duplicate_marketplace_link';
        } elseif (count($links) === 1) {
            $vendor = (int) $links[0]['vendor_user_id'];
            $bucket = $links[0]['ownership'] === LinkOwnership::Marketplace->value
                ? self::BUCKET_MARKETPLACE
                : self::BUCKET_OBSERVED;
        } elseif ($signals['dokan_seller'] === true && $signals['vendor_profile'] !== '') {
            // Both a Dokan seller AND marketplace paperwork. Which system
            // should own the product is exactly the decision this round must
            // not take.
            $reasons[] = 'seller_in_both_systems';
            $vendor = (int) $signals['author_id'];
        } elseif ($signals['dokan_seller'] === true) {
            $bucket = self::BUCKET_DOKAN;
            $vendor = (int) $signals['author_id'];
            // Dokan's products already have a path — `ImportFromDokan`, which
            // reads Dokan's own tables and records the shop's past with them.
            // A second road into the same table would be the parallel path
            // the instruction forbids, so this one names the other.
            $reasons[] = 'dokan_product_has_its_own_path';
        } elseif ($signals['author_exists'] !== true) {
            $reasons[] = 'author_missing';
        } elseif ($signals['vendor_profile'] === 'approved') {
            // The clean case: an approved vendor's product with no row yet.
            $bucket = self::BUCKET_VENDOR;
            $vendor = (int) $signals['author_id'];
        } elseif ($signals['vendor_profile'] !== '') {
            // Paperwork that is not an approval: submitted, rejected,
            // suspended. None of those is permission to sell.
            $reasons[] = 'vendor_application_' . $signals['vendor_profile'];
            $vendor = (int) $signals['author_id'];
        } elseif ($signals['author_is_admin'] === true) {
            $bucket = self::BUCKET_SHOP;
            // Deliberately NOT the author: an administrator is not a vendor,
            // and making them one would put the shop's own catalogue under
            // somebody's vendor account. «محصول فروشگاه اصلی را به فروشندهٔ
            // تصادفی یا مدیر واردشده نسبت نده.»
            $vendor = 0;
            $reasons[] = 'shop_product_needs_owner_decision';
        } else {
            $reasons[] = 'author_is_not_a_vendor';
            $vendor = 0;
        }

        if (($row['type_supported'] ?? false) !== true) {
            $reasons[] = 'type_not_supported';
        }

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
            'vendor_application_profile_without_application' =>
                'نویسنده پروفایل فروشگاه دارد ولی پروندهٔ درخواستی ندارد (فروشگاه واردشده یا دستی).',
        ];
    }
}
