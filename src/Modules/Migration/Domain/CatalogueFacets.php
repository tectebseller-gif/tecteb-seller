<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Domain;

/**
 * THE THREE QUESTIONS A UNIFIED PRODUCT LIST IS FILTERED BY, kept apart.
 *
 * «وضعیت انتشار با وضعیت ورود/مالکیت قاتی نشود» is the instruction, and it is
 * not a cosmetic request: a manager who reads «draft» as «not imported yet»
 * will import a product that is already managed, and one who reads
 * «مشاهده‌شده» as «not published» will go looking for a publish button that
 * is not the problem. They are three independent facts about one row:
 *
 *   * **منبع** — which side of the catalogue the row comes from. A
 *     WooCommerce product with no marketplace row, a product with one, or a
 *     marketplace row with no WooCommerce product behind it at all. Derived
 *     from whether each half of the pair exists, so it is decidable in SQL
 *     and the pager can be bounded.
 *   * **وضعیت انتشار** — WooCommerce's own `post_status`. Read from the post
 *     and never mirrored into a column of ours: a status copied into our
 *     table is a second truth, and the first person to edit the post in
 *     wp-admin makes it wrong without anybody finding out (`alpha.29`).
 *   * **وضعیت مدیریت** — whether this plugin runs the product
 *     (`link_ownership`), merely watches it, or has never heard of it. This
 *     is the one that decides whether any guard of ours has an opinion about
 *     the product, which is why it is a facet of its own rather than a
 *     footnote on the source column.
 *
 * The values live here, once, because the control that offers a choice and
 * the query that applies it drift apart the moment they are written twice —
 * the rule this repository already learned for `ProductStatus`,
 * `ProductSort` and `StorefrontField::REQUIRED`.
 */
final class CatalogueFacets
{
    /** A WooCommerce product with a marketplace row pointing at it. */
    public const SOURCE_MARKETPLACE = 'marketplace';
    /** A WooCommerce product no marketplace row points at. */
    public const SOURCE_WOOCOMMERCE = 'woocommerce';
    /** A marketplace row with no WooCommerce product behind it — a draft, or a deleted post. */
    public const SOURCE_DRAFT = 'draft';

    /** This plugin runs the product: its guards have an opinion about it. */
    public const MANAGED_OURS = 'ours';
    /** A row that only watches: nothing of ours writes to the product. */
    public const MANAGED_OBSERVED = 'observed';
    /** No marketplace row at all. */
    public const MANAGED_NONE = 'none';

    /** The value that means «there is no WooCommerce post to have a status». */
    public const STATUS_ABSENT = '__absent__';

    /** @var array<string,string> */
    public const SOURCES = [
        self::SOURCE_MARKETPLACE => 'دارای ردیف بازارگاه',
        self::SOURCE_WOOCOMMERCE => 'فقط در ووکامرس',
        self::SOURCE_DRAFT => 'فقط ردیف بازارگاه',
    ];

    /** @var array<string,string> */
    public const MANAGEMENT = [
        self::MANAGED_OURS => 'زیر مدیریت بازارگاه',
        self::MANAGED_OBSERVED => 'فقط مشاهده‌شده',
        self::MANAGED_NONE => 'بی ردیف بازارگاه',
    ];

    /** An unrecognised value is «همه», never an error: a stale bookmark should show the list. */
    public static function source(string $raw): string
    {
        return array_key_exists($raw, self::SOURCES) ? $raw : '';
    }

    public static function management(string $raw): string
    {
        return array_key_exists($raw, self::MANAGEMENT) ? $raw : '';
    }

    /**
     * A WooCommerce publish status, by SHAPE rather than by a list of names.
     *
     * `post_status` is extensible — a plugin on the owner's site may register
     * one this file has never heard of, and a list written here would silently
     * drop the chip for it. Anything that looks like a status key is bound as
     * a parameter and asked of the database; a status nothing has is simply an
     * empty result, which is the truthful answer.
     */
    public static function wcStatus(string $raw): string
    {
        if ($raw === self::STATUS_ABSENT) {
            return $raw;
        }
        return preg_match('/^[a-z0-9_-]{1,32}$/', $raw) === 1 ? $raw : '';
    }

    public static function sourceLabel(string $source): string
    {
        return self::SOURCES[$source] ?? $source;
    }

    public static function managementLabel(string $management): string
    {
        return self::MANAGEMENT[$management] ?? $management;
    }
}
