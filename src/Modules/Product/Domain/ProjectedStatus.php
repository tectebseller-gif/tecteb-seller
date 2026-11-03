<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * What a projection would write to a storefront product's status — and
 * whether writing it would CHANGE anything.
 *
 * ### Why this is one class and not two sentences
 *
 * `WooCommerceProjector::project()` writes `post_status` and
 * `catalog_visibility` from the marketplace row's own status, unconditionally,
 * unless `mirrored_status` holds the write back (`alpha.67`). So the answer to
 * «would accepting management change what a customer sees?» is already
 * decided by three values — the row's status, the stamp, and what the shop
 * holds — and it is decided IDENTICALLY wherever it is asked.
 *
 * Until `alpha.92` it was asked in one place only, and that place was the
 * write itself: nothing upstream could say what the write would do, so the
 * staged transfer could not refuse a product whose publish state it was about
 * to destroy. Measured in `docs/dokan-exit-drift-analysis-alpha91-fa.md` §2:
 * the Dokan intake never passed `create()`'s seventh argument, so every one of
 * shop 102's 184 rows sits at «پیش‌نویس» with NO stamp, and the first
 * projection after a transfer would write `draft` + `hidden` over a published
 * product. Nothing on any screen said so, because nothing had ever asked.
 *
 * ⛔ **`AGREES` compares what the projector WRITES against what the shop
 * HOLDS, never the enum mapping.** `ProductStatus::forIntakeOf('pending')` is
 * «پیش‌نویس», so a row at Draft beside a `pending` product looks like
 * agreement through the map and is not: the projector writes the literal
 * `draft`, which is exactly the `alpha.67` finding — a pending product
 * dropped out of the catalogue and out of search at the first projection.
 * `private` and `future` fail the same comparison for the same reason, which
 * is why this class needs no taxonomy of statuses it cannot express: a value
 * the projector would overwrite is reported with BOTH strings, and a manager
 * reading «publish → draft» needs no vocabulary lesson.
 *
 * Pure: no WordPress, no database, no user-facing text. The sentences live
 * where the other Persian labels live, beside the drift they are printed with.
 */
final class ProjectedStatus
{
    /**
     * `mirrored_status` holds a value, so the projection writes NEITHER the
     * status nor the catalogue visibility. Nothing can change; nothing to ask.
     */
    public const HELD = 'held';

    /** The projection would write exactly the status the shop already holds. */
    public const AGREES = 'agrees';

    /** The projection would write a DIFFERENT status. This has to stop. */
    public const WOULD_CHANGE = 'would_change';

    /**
     * The shop's status could not be read.
     *
     * «نمی‌دانم» is an answer and it is not «they agree» (`alpha.49`): a read
     * that failed must never become a licence to write.
     */
    public const UNREADABLE = 'unreadable';

    /** The `post_status` a projection of this row would write. */
    public static function statusOf(ProductStatus $rowStatus): string
    {
        return $rowStatus === ProductStatus::Published ? 'publish' : 'draft';
    }

    /**
     * The `catalog_visibility` a projection of this row would write.
     *
     * Held with the status rather than beside it, because it answers the same
     * question — «is this product supposed to be seen» — and giving one
     * question two answers is how `alpha.66` produced a green banner over a
     * shut gate.
     */
    public static function visibilityOf(ProductStatus $rowStatus): string
    {
        return $rowStatus === ProductStatus::Published ? 'visible' : 'hidden';
    }

    /**
     * One of the four verdicts above.
     *
     * @param ?string $mirroredStatus the row's stamp; `null` or blank = none
     * @param ?string $shopStatus WooCommerce's own `post_status`; `null` when
     *        the product could not be read at all
     */
    public static function decide(
        ProductStatus $rowStatus,
        ?string $mirroredStatus,
        ?string $shopStatus
    ): string {
        if ($mirroredStatus !== null && trim($mirroredStatus) !== '') {
            // The stamp is checked FIRST and on purpose: a held row cannot
            // change the shop's status whatever the shop holds, so asking
            // about the value would invent a question that has no consequence.
            return self::HELD;
        }
        if ($shopStatus === null || trim($shopStatus) === '') {
            return self::UNREADABLE;
        }
        return self::statusOf($rowStatus) === trim($shopStatus)
            ? self::AGREES
            : self::WOULD_CHANGE;
    }

    /** Whether this verdict permits a transfer without a human looking. */
    public static function settled(string $verdict): bool
    {
        return $verdict === self::HELD || $verdict === self::AGREES;
    }
}
