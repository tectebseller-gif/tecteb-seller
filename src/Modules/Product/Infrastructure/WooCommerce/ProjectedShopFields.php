<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Modules\Product\Domain\ShopEditMerge;

/**
 * The three fields `project()` used to write over a person's edit, and the two
 * meta rows that stop it.
 *
 * `ShopEditMerge` is the rule for ONE field and knows nothing about
 * WooCommerce; this class is the three fields together, the shop reads and the
 * record. The split is the same one `ProjectedQuantity` has: a rule a unit test
 * can reach, and the plumbing that reads a `WC_Product`.
 *
 * Two rows, and they answer different questions:
 *
 *  - `RECORD_META` — `field => {sent, shop}`, what we last wrote and what the
 *    shop held when we read it back. Both, because WooCommerce filters on the
 *    way in (`alpha.26`) and a site filter clamping a price would otherwise
 *    make every later projection believe a person had edited it.
 *  - `CONFLICT_META` — `field => {answer, shop, ours}`, so the review screen
 *    can print the two numbers rather than a word. «تعارض تغییرات هم‌زمان را
 *    گزارش کن، نه اینکه بی‌صدا یکی را انتخاب کنی.»
 *
 * The values in both rows are the CANONICAL form — minor units for the price,
 * grams for the weight — which is also the form the marketplace's own screens
 * show, so a manager reading the report sees the same numbers they see
 * everywhere else. A value that could not be canonicalised is stored as the
 * trimmed text it was, under the `unreadable` answer, because printing nothing
 * would be the one thing worse than printing a number nobody can parse.
 */
final class ProjectedShopFields
{
    /** `field => {sent, shop}` — what this plugin last wrote there. */
    public const RECORD_META = '_tmc_shop_field_written';

    /** `field => {answer, shop, ours}` — for a person to look at. */
    public const CONFLICT_META = '_tmc_shop_field_conflicts';

    /** The record itself would not store — reported, never left stale. */
    public const RECORD_NOT_STORED = '_record_not_stored';

    /**
     * What the shop holds right now, in the units the shop uses.
     *
     * Read with the `edit` context where WooCommerce offers one, so a display
     * filter on somebody's site cannot be mistaken for an edit. The weight is
     * left out for a variation, which has none.
     *
     * @return array<string,string>
     */
    public static function shopValues(mixed $wcProduct, bool $withWeight = true): array
    {
        $values = [
            ShopEditMerge::PRICE => (string) $wcProduct->get_regular_price(),
            ShopEditMerge::SKU => (string) $wcProduct->get_sku(),
        ];
        if ($withWeight) {
            $values[ShopEditMerge::WEIGHT] = method_exists($wcProduct, 'get_weight')
                ? (string) $wcProduct->get_weight()
                : '';
        }
        return $values;
    }

    /**
     * The stored record, in the one shape the readers expect.
     *
     * @return array<string,array{sent:string, shop:string}>
     */
    public static function record(int $wcProductId): array
    {
        return ShopEditMerge::normalise(get_post_meta($wcProductId, self::RECORD_META, true));
    }

    /**
     * Which of the fields this projection may write, and why for each.
     *
     * `$createdByUs` is the same parameter `ProjectedFieldOwnership::owner()`
     * carries and for the same reason: every byte of a post this projection
     * just created was written a moment ago by us, and there is nobody else it
     * could belong to. Without it a brand-new product would compare our price
     * against an empty shop field, differ, and never get a price at all.
     *
     * @param array<string,string> $shop   what the shop holds
     * @param array<string,string> $ours   what we want to write, in shop units
     * @param array<string,array{sent:string, shop:string}> $stored
     * @return array{answers:array<string,string>, may:array<string,bool>, report:array<string,array{answer:string, shop:string, ours:string}>}
     */
    public static function merge(
        array $shop,
        array $ours,
        array $stored,
        bool $createdByUs,
        bool $oursIsFresh = false
    ): array {
        $answers = [];
        $may = [];
        $report = [];
        foreach ($ours as $field => $want) {
            $has = (string) ($shop[$field] ?? '');
            $answer = $createdByUs && trim($want) !== ''
                ? ShopEditMerge::WRITE
                : ShopEditMerge::decide($field, $stored[$field] ?? null, $has, $want, $oursIsFresh);
            $answers[$field] = $answer;
            $may[$field] = ShopEditMerge::writes($answer);
            if (ShopEditMerge::reports($answer)) {
                $report[$field] = [
                    'answer' => $answer,
                    'shop' => ShopEditMerge::canonical($field, $has) ?? trim($has),
                    'ours' => ShopEditMerge::canonical($field, $want) ?? trim($want),
                ];
            }
        }
        return ['answers' => $answers, 'may' => $may, 'report' => $report];
    }

    /**
     * Writes the record for the fields that were written, and the report for
     * the ones worth a person's attention.
     *
     * The record is taken from a FRESH read, never from what was sent — the
     * `alpha.26` rule that `stampOwned()` already follows one object along.
     * A field we did not write keeps whatever record it had: the record says
     * «this is what we last wrote», and it is still true.
     *
     * @param array<string,string> $sent   what we asked the shop to hold
     * @param array<string,bool> $wrote    which fields were actually written
     * @param array<string,array{answer:string, shop:string, ours:string}> $report
     */
    public static function store(int $wcProductId, array $sent, array $wrote, array $report, bool $withWeight = true): void
    {
        $stored = self::record($wcProductId);
        $fresh = wc_get_product($wcProductId);
        $problem = [];
        if ($fresh !== null && $fresh !== false) {
            $now = self::shopValues($fresh, $withWeight);
            foreach ($wrote as $field => $didWrite) {
                if (!$didWrite) {
                    continue;
                }
                $sentCanonical = ShopEditMerge::canonical($field, (string) ($sent[$field] ?? ''));
                $shopCanonical = ShopEditMerge::canonical($field, (string) ($now[$field] ?? ''));
                if ($sentCanonical === null || $shopCanonical === null) {
                    // A value we cannot canonicalise cannot be compared next
                    // time, so recording it would be recording a claim no
                    // reader can check. Said out loud instead.
                    unset($stored[$field]);
                    $problem[$field] = [
                        'answer' => ShopEditMerge::UNREADABLE,
                        'shop' => trim((string) ($now[$field] ?? '')),
                        'ours' => trim((string) ($sent[$field] ?? '')),
                    ];
                    continue;
                }
                $stored[$field] = ShopEditMerge::record($sentCanonical, $shopCanonical);
            }
        }

        if ($stored === []) {
            delete_post_meta($wcProductId, self::RECORD_META);
        } else {
            update_post_meta($wcProductId, self::RECORD_META, $stored);
            // Read back, because `update_post_meta()` answers the same thing
            // for «failed» and «already that» (`alpha.28`), and a record that
            // did not store would license the next projection to overwrite.
            if (ShopEditMerge::normalise(get_post_meta($wcProductId, self::RECORD_META, true)) !== $stored) {
                delete_post_meta($wcProductId, self::RECORD_META);
                $problem[self::RECORD_NOT_STORED] = [
                    'answer' => self::RECORD_NOT_STORED,
                    'shop' => '',
                    'ours' => '',
                ];
            }
        }

        $conflicts = [...$report, ...$problem];
        if ($conflicts === []) {
            // Deleted rather than set empty, so a page asking «is anything
            // held» never has to tell `[]` from absent (`alpha.60`).
            delete_post_meta($wcProductId, self::CONFLICT_META);
            return;
        }
        update_post_meta($wcProductId, self::CONFLICT_META, $conflicts);
    }

    /**
     * The report as a page can read it.
     *
     * @return array<string,array{answer:string, shop:string, ours:string}>
     */
    public static function conflicts(int $wcProductId): array
    {
        $stored = get_post_meta($wcProductId, self::CONFLICT_META, true);
        if (!is_array($stored)) {
            return [];
        }
        $out = [];
        foreach ($stored as $field => $entry) {
            if (!is_string($field) || !is_array($entry)) {
                continue;
            }
            $answer = $entry['answer'] ?? null;
            if (!is_string($answer) || $answer === '') {
                continue;
            }
            $out[$field] = [
                'answer' => $answer,
                'shop' => is_string($entry['shop'] ?? null) ? $entry['shop'] : '',
                'ours' => is_string($entry['ours'] ?? null) ? $entry['ours'] : '',
            ];
        }
        return $out;
    }
}
