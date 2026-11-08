<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

/**
 * What a shop's ownership counts mean — ONE derived fact, four answers.
 *
 * ### Why this is a class and not two comparisons
 *
 * «آیا مالکیت منتقل شده؟» was asked in three places and answered by the same
 * two comparisons written out three times: the `ownership_taken` gate in
 * `MigrationCompleteness`, and steps 9 and 10 in `MigrationPlanChecklist`.
 * Three copies of one rule are three rules the day one of them is corrected,
 * and the two that were not corrected go on being right about their own
 * question (`alpha.78`). So the rule lives here and all three read it.
 *
 * ### ⛔ The fourth answer, and the defect it closes
 *
 * `marketplace > 0 && observed === 0` is a true sentence about a shop that
 * holds products. About a shop that holds NONE it is false for ever, and
 * «false for ever» is not a judgement — it is a gate with no exit, which this
 * project has now met four times (`alpha.60`, `alpha.68`, `alpha.80`,
 * `alpha.96`) and names each time as worse than the defect it came for.
 *
 * Measured on the owner's own site: shop 40 carries a profile import stamp and
 * no products. Its `ownership_taken` gate was `0 > 0 && 0 === 0` ⇒ false, so
 * the shop could never be `complete`; and steps 9 and 10 read
 * `TODO / rows_still_observed / 0` — a sentence that says «۰ ردیف هنوز
 * مشاهده‌ای است», which is a contradiction printed as instructions. The only
 * exits on offer were to invent a product or to delete the shop, and the
 * owner's §1 refuses both: «فعلاً فروشگاه ۴۰ را حذف نکن… راه‌حل اصولی… نه
 * دورزدن مصنوعی شرط‌ها».
 *
 * `NOTHING_TO_TRANSFER` is that principled answer, and it is a POSITIVE fact
 * rather than an absence — three things have to be true at once:
 *
 *  1. the tally was **read** (`readable`), so nought is a measured nought and
 *     not a statement that failed (`alpha.8`);
 *  2. both counts are nought, so there is genuinely nothing left to move;
 *  3. the shop carries an **import stamp**, so something on this side says
 *     «این فروشگاه وارد شده است».
 *
 * Drop the third and an arbitrary id that was never imported reads as
 * «transferred», which is the `alpha.72` circularity from the other end:
 * a gate must not be satisfiable by a shop nobody ever migrated. Drop the
 * first and a broken read reads as done, which is the one direction this
 * whole file exists to refuse.
 *
 * ### Pure
 *
 * No WordPress, no database, no repository. Every branch is reachable from a
 * unit test with three arguments.
 */
final class OwnershipState
{
    /** The rows are the marketplace's to run. The ordinary finished state. */
    public const TAKEN = 'taken';

    /**
     * A measured nought on an imported shop: there is nothing to transfer.
     *
     * Kept apart from `TAKEN` for the reason `MigrationCompleteness::
     * RECORDS_NO_SOURCE` is kept apart from `RECORDS_SETTLED`: «همه‌اش منتقل
     * شد» and «چیزی برای انتقال نبود» are two different sentences, both of
     * them finished, and the page says which one this shop is.
     */
    public const NOTHING_TO_TRANSFER = 'nothing_to_transfer';

    /** Rows are still catalogue notes, or nothing says this shop was imported. */
    public const ROWS_OBSERVED = 'rows_observed';

    /** The counts could not be read. Never nought, and never finished. */
    public const UNREADABLE = 'unreadable';

    /**
     * @param array{marketplace?:int, observed?:int, readable?:bool} $tally
     *        as `ProductRepositoryInterface::ownershipTallyForVendor()` gives
     *        it — `readable` false means the statement did not run, and the
     *        two numbers then mean nothing at all.
     * @param bool $importStamped whether anything on THIS side records that
     *        this shop arrived through an import.
     */
    public static function of(array $tally, bool $importStamped): string
    {
        // ⛔ A missing key is treated as «not readable», not as nought. A
        // caller holding an older array shape must land on `UNREADABLE`
        // rather than on a verdict, because the one thing worse than an
        // unmeasured step is an unmeasured step that reads as finished.
        if (($tally['readable'] ?? false) !== true) {
            return self::UNREADABLE;
        }
        $marketplace = (int) ($tally['marketplace'] ?? 0);
        $observed = (int) ($tally['observed'] ?? 0);
        if ($observed > 0) {
            return self::ROWS_OBSERVED;
        }
        if ($marketplace > 0) {
            return self::TAKEN;
        }
        return $importStamped ? self::NOTHING_TO_TRANSFER : self::ROWS_OBSERVED;
    }

    /**
     * Whether there is anything left for a person to do about ownership.
     *
     * Both finished answers close the gate, and neither of the other two
     * does — including `UNREADABLE`, which is the whole point: a figure
     * nobody could read must not open a gate (`alpha.68`).
     */
    public static function settled(string $state): bool
    {
        return $state === self::TAKEN || $state === self::NOTHING_TO_TRANSFER;
    }
}
