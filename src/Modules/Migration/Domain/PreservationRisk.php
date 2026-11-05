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

    /**
     * Blocking keys the projection writes ONLY while the row carries no
     * `mirrored_status` stamp — so a stamped row cannot lose them.
     *
     * ### ⛔ The measurement, and why this list is not empty
     *
     * `WooCommerceProjector::project()` writes the catalogue visibility in
     * ONE place and it is inside the stamp guard
     * (`WooCommerceProjector.php:218-222`):
     *
     * ```php
     * $statusHeld = $product->storefrontStatusIsHeld();
     * if (!$statusHeld) {
     *     $wcProduct->set_status(ProjectedStatus::statusOf($product->status));
     *     $wcProduct->set_catalog_visibility(ProjectedStatus::visibilityOf($product->status));
     * }
     * ```
     *
     * The only other `set_catalog_visibility()` in that file is in
     * `withdraw()` (line 526) — a manager deliberately taking a product off
     * sale, not a projection — so for a HELD row the count of projection
     * writes is **zero**, exactly as it is for the six disclosures.
     *
     * And `storefrontStatusIsHeld()` is `mirroredStatus !== null && trim(...)
     * !== ''` (`Product.php:121-124`), which is the same predicate
     * `ProjectedStatus::decide()` answers `HELD` for — the same three values,
     * read once. `PreservationRiskClassificationTest` pins that this list
     * holds only keys the projector writes under `!$statusHeld`, so the day
     * one moves out of that `if` the test fails rather than this comment
     * becoming wrong in silence (`alpha.90`).
     *
     * ### Why the key stays in BLOCKING and is released here instead
     *
     * The owner's §3 names `catalog_visibility` among four whose protection
     * «باید حفظ شود», and `testTheFourProtectedKeysStayBlocking()` holds it
     * in `BLOCKING`. Both stay true: the classification is unchanged, and
     * what this adds is the ROW's context — on an unstamped row the key
     * blocks exactly as before. Nothing is waived for anybody; a question
     * with no consequence stops being asked (`alpha.87`).
     *
     * ### ⛔ And the hazard is deferred, not gone — so it is disclosed
     *
     * `updateStatus()` clears the stamp the moment a human in this
     * marketplace decides the status, and from the next projection the
     * visibility IS written. So a product at `hidden` or `search` keeps its
     * value through the transfer and through every sync — and would lose it
     * if a manager later publishes or unpublishes it from this panel. That
     * is why the released key is still printed, with a sentence that says
     * so, and never silently dropped.
     */
    public const HELD_WITH_STATUS = [
        'catalog_visibility',
    ];

    public static function isBlocking(string $key): bool
    {
        return !in_array($key, self::DISCLOSURE, true);
    }

    /**
     * Whether this key blocks GIVEN what the row's stamp makes possible.
     *
     * `$statusHeld` false is the conservative answer, so a caller that
     * cannot tell gets the pre-`alpha.93` behaviour rather than a release
     * (`alpha.89`).
     */
    public static function isBlockingGiven(string $key, bool $statusHeld): bool
    {
        if ($statusHeld && in_array($key, self::HELD_WITH_STATUS, true)) {
            return false;
        }
        return self::isBlocking($key);
    }

    /**
     * The blocking keys of one answer, for a row whose stamp state is known.
     *
     * @param array<string,string> $risks
     * @return list<string>
     */
    public static function blockingGiven(array $risks, bool $statusHeld): array
    {
        return array_values(array_filter(
            array_keys($risks),
            static fn (string $key): bool => self::isBlockingGiven($key, $statusHeld)
        ));
    }

    /**
     * The disclosure keys of the same answer, including any released by the
     * stamp — because a released key is REPORTED, not removed.
     *
     * @param array<string,string> $risks
     * @return list<string>
     */
    public static function disclosuresGiven(array $risks, bool $statusHeld): array
    {
        return array_values(array_filter(
            array_keys($risks),
            static fn (string $key): bool => !self::isBlockingGiven($key, $statusHeld)
        ));
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
