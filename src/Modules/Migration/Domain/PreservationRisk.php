<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Domain;

/**
 * Which preservation risks a projection would actually OVERWRITE, and which it
 * only discloses — measured from the projector's setters, not judged.
 *
 * ### The number this exists for
 *
 * On shop 102 the staged transfer reported «آماده انتقال در صفحه فعلی: ۰ ·
 * دارای ریسک یا اختلاف در صفحه فعلی: ۵۰». `OwnershipTransferPlan` put a row in
 * `needs_decision` if `preservationRisksFrom()` named ANY key, and
 * `TransferOwnership::take()` demanded an acknowledgement for every one — so
 * a product whose only finding was «this shop records a shipping class» was
 * as blocked as one whose stock management the first save would switch on.
 *
 * ⛔ **And five of the ten keys name a field the projector never writes.**
 * Counted in `WooCommerceProjector` on `alpha.91`'s bytes and again here:
 *
 * | key                 | setters in `project()` |
 * |---------------------|------------------------|
 * | `manage_stock`      | **3** — and one is unconditional |
 * | `catalog_visibility`| **2** |
 * | `dimensions`        | 0 (`set_length`/`set_width`/`set_height`) |
 * | `shipping_class`    | 0 (`set_shipping_class_id`) |
 * | `tags`              | 0 (`set_tag_ids`) |
 * | `related`           | 0 (`set_upsell_ids`/`set_cross_sell_ids`) |
 * | `backorders`        | 0 (`set_backorders`) |
 * | `sold_individually` | 0 (`set_sold_individually`) |
 *
 * Their own sentences already said so — «مقدار فعلی ووکامرس دست‌نخورده
 * می‌مانَد» — and the gate did not read its own sentence. The owner's §3:
 * «مواردی که واقعاً در همگام‌سازی تغییر نمی‌کنند، مانع انتقال نباشند.»
 *
 * ### What stays blocking, and why each one earns it
 *
 *  * **`manage_stock`** — `set_manage_stock(true)` is unconditional, so the
 *    first save switches a vendor's manual stock handling on.
 *  * **`catalog_visibility`** — written from the marketplace status whenever
 *    the row carries no mirrored stamp, so `search`-only or `hidden` becomes
 *    `visible`.
 *  * **`price_scale`** — the shop holds a fractional price this marketplace
 *    cannot store as an integer. Nothing is overwritten, and it still blocks:
 *    `ImportExistingProducts::detailsFrom()` cannot even build the snapshot,
 *    so neither the drift report nor the re-snapshot can run for that row —
 *    and rounding money has been forbidden since `alpha.39`.
 *  * **`type`** — a type the four-step form has no shape for. `project()`
 *    branches on `simple` versus `variable` and treats anything else as the
 *    former.
 *  * **`product`** — the read failed. «نمی‌دانم» is never «they agree»
 *    (`alpha.49`).
 *
 * The owner's §3 names four to keep protected — `manage_stock`,
 * `catalog_visibility`, `price_scale` and `type` — and all four are here.
 *
 * ### ⛔ An unknown key is BLOCKING
 *
 * `isBlocking()` answers true for a key in neither list. A risk added to the
 * reader and forgotten here must not become waivable in silence, and the
 * conservative direction for a classification is always «more work left»
 * (`alpha.89`). `PreservationRiskClassificationTest` asserts every key the
 * reader can produce is in exactly one list, so this fallback is a second
 * line of defence rather than the plan.
 *
 * ### What a disclosure does NOT become
 *
 * It is not accepted, waived or hidden. It is printed on the screen, carried
 * in the transfer's audit row, and left alone — because the honest record of
 * a field nobody writes is a sentence, not a tick. «هیچ ریسک یا اختلاف واقعی
 * به‌صورت خودکار پذیرفته نشود» holds: nothing in the blocking list is ever
 * accepted without a human, and the batch keeps passing an EMPTY
 * acknowledgement array, so a blocking risk stops it whatever this file says.
 */
final class PreservationRisk
{
    /** Keys whose value a projection would overwrite, or cannot express. */
    public const BLOCKING = [
        'product',
        'type',
        'catalog_visibility',
        'manage_stock',
        'price_scale',
    ];

    /**
     * Keys this marketplace has no field for and the projector never writes.
     *
     * Reported, never waived — and `sold_individually` is here because the
     * marketplace's quantity rule STANDS DOWN for such a product and records
     * the contradiction (`alpha.45`), so the shop's own behaviour is what
     * continues.
     */
    public const DISCLOSURE = [
        'backorders',
        'sold_individually',
        'dimensions',
        'shipping_class',
        'tags',
        'related',
    ];

    public static function isBlocking(string $key): bool
    {
        return !in_array($key, self::DISCLOSURE, true);
    }

    /**
     * The blocking keys of one `preservationRisksFrom()` answer.
     *
     * @param array<string,string> $risks
     * @return list<string>
     */
    public static function blockingIn(array $risks): array
    {
        return array_values(array_filter(
            array_keys($risks),
            static fn (string $key): bool => self::isBlocking($key)
        ));
    }

    /**
     * The disclosure keys of the same answer.
     *
     * @param array<string,string> $risks
     * @return list<string>
     */
    public static function disclosuresIn(array $risks): array
    {
        return array_values(array_filter(
            array_keys($risks),
            static fn (string $key): bool => !self::isBlocking($key)
        ));
    }
}
