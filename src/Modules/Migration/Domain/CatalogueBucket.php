<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Domain;

use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;

/**
 * WHOSE PRODUCT IS THIS — one decision, asked two ways.
 *
 * The detail page asks it about one product and can afford to read everything
 * about it; the list asks it about a hundred products at once and cannot. Two
 * implementations of «whose is this» would agree until the day they did not,
 * and the day they did not a manager would be looking at a list that says
 * `dokan` and a detail page that says `shop` for the same row (`alpha.14`).
 *
 * So the GATHERING is written twice — once per product, once per page — and
 * the DECISION is written here, once, as a pure function over the facts both
 * gatherings can produce.
 *
 * The order of the rules is the decision. A marketplace row wins over
 * everything, because a row is a thing somebody did; after that, two systems
 * claiming one product is a question rather than an answer; and an
 * administrator's product is the shop's own rather than the administrator's,
 * which is the one inference this file refuses to make
 * («محصول فروشگاه اصلی را به فروشندهٔ تصادفی یا مدیر واردشده نسبت نده»).
 */
final class CatalogueBucket
{
    /** A marketplace row this plugin runs. */
    public const MARKETPLACE = 'marketplace';
    /** A marketplace row that only watches. */
    public const OBSERVED = 'observed';
    /** A Dokan vendor's product, which has its own transfer path. */
    public const DOKAN = 'dokan';
    /** The main shop's own product — nobody's vendor product. */
    public const SHOP = 'shop';
    /** An approved marketplace vendor's product with no row yet. */
    public const VENDOR = 'vendor';
    /** A question rather than an answer. */
    public const AMBIGUOUS = 'ambiguous';
    /** A marketplace draft with no WooCommerce product behind it yet. */
    public const DRAFT_ONLY = 'draft_only';

    /** @var list<string> */
    public const ALL = [
        self::MARKETPLACE,
        self::OBSERVED,
        self::DOKAN,
        self::SHOP,
        self::VENDOR,
        self::AMBIGUOUS,
        self::DRAFT_ONLY,
    ];

    /**
     * @param array{
     *     author_id:int,
     *     author_exists:bool,
     *     author_is_admin:bool,
     *     dokan_seller:bool,
     *     vendor_profile:string,
     *     marketplace_rows:list<array{vendor_user_id:int, ownership:string}>
     * } $signals
     * @return array{bucket:string, vendor_user_id:int, reasons:list<string>}
     */
    public static function decide(array $signals, bool $typeSupported = true): array
    {
        $links = $signals['marketplace_rows'];
        $reasons = [];
        $vendor = 0;
        $bucket = self::AMBIGUOUS;

        if (count($links) > 1) {
            // Two rows for one product: which is the real one is a decision
            // about somebody's catalogue, so it is reported and nothing else.
            $reasons[] = 'duplicate_marketplace_link';
        } elseif (count($links) === 1) {
            $vendor = (int) $links[0]['vendor_user_id'];
            $bucket = $links[0]['ownership'] === LinkOwnership::Marketplace->value
                ? self::MARKETPLACE
                : self::OBSERVED;
        } elseif ($signals['dokan_seller'] === true && $signals['vendor_profile'] !== '') {
            // Both a Dokan seller AND marketplace paperwork. Which system
            // should own the product is exactly the decision this must not
            // take.
            $reasons[] = 'seller_in_both_systems';
            $vendor = (int) $signals['author_id'];
        } elseif ($signals['dokan_seller'] === true) {
            $bucket = self::DOKAN;
            $vendor = (int) $signals['author_id'];
            // Dokan's products already have a path — `ImportFromDokan`, which
            // reads Dokan's own tables and records the shop's past with them.
            // A second road into the same table would be the parallel path the
            // instruction forbids, so this one names the other.
            $reasons[] = 'dokan_product_has_its_own_path';
        } elseif ($signals['author_exists'] !== true) {
            $reasons[] = 'author_missing';
        } elseif ($signals['vendor_profile'] === 'approved') {
            // The clean case: an approved vendor's product with no row yet.
            $bucket = self::VENDOR;
            $vendor = (int) $signals['author_id'];
        } elseif ($signals['vendor_profile'] !== '') {
            // Paperwork that is not an approval: submitted, rejected,
            // suspended. None of those is permission to sell.
            $reasons[] = 'vendor_application_' . $signals['vendor_profile'];
            $vendor = (int) $signals['author_id'];
        } elseif ($signals['author_is_admin'] === true) {
            $bucket = self::SHOP;
            // Deliberately NOT the author: an administrator is not a vendor,
            // and making them one would put the shop's own catalogue under
            // somebody's vendor account.
            $vendor = 0;
            $reasons[] = 'shop_product_needs_owner_decision';
        } else {
            $reasons[] = 'author_is_not_a_vendor';
            $vendor = 0;
        }

        if (!$typeSupported) {
            $reasons[] = 'type_not_supported';
        }

        return ['bucket' => $bucket, 'vendor_user_id' => $vendor, 'reasons' => $reasons];
    }

    /** The Persian name of each bucket, for a screen. */
    public static function label(string $bucket): string
    {
        return match ($bucket) {
            self::MARKETPLACE => 'بازارگاه',
            self::OBSERVED => 'فقط مشاهده‌شده',
            self::DOKAN => 'دکان',
            self::SHOP => 'فروشگاه اصلی',
            self::VENDOR => 'فروشندهٔ بازارگاه، بی ردیف',
            self::DRAFT_ONLY => 'پیش‌نویس بازارگاه، بی محصول ووکامرس',
            default => 'مبهم',
        };
    }
}
