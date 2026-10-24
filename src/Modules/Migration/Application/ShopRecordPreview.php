<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Modules\Migration\Domain\RecordSource;

/**
 * What a shop's staff, balance and withdrawal records amount to — before
 * anything is imported, and without writing a thing.
 *
 * ### Why a preview at all, and why it comes first
 *
 * The record import had no caller in `src/` until this round:
 * `ImportFromDokan::importShopRecordPage()` existed, was tested, was driven by
 * an evidence tool — and no button, no handler and no queue job ever reached
 * it. So the handover page asked `vendorsWithRecords()`, got the union of
 * three empty tables, and told a manager whose product import had just
 * finished «هنوز سابقه‌ای وارد نشده» with a link back to the import screen.
 * The one safe reading of that sentence was the wrong one: run the import
 * again.
 *
 * The first step after installing must therefore be **looking**, not
 * importing. This class is the looking: it has no write method of any kind,
 * which is how «پیش‌نمایش فقط‌خواندنی» is enforced rather than promised. The
 * shape is `MigrateMinMax::preview()`'s and the reason is the same one.
 *
 * ### Six verdicts, because four facts need six names
 *
 * The instruction asks for «صفر سابقه»، «هنوز بررسی نشده»، «خواندن ناموفق» and
 * «واردشده» to be shown apart, and that a read error must never be read as
 * nought. Four are not quite enough to say that honestly, so there are six and
 * all four of those stay distinct:
 *
 *  - `IMPORTED`   — we hold every row the source has.
 *  - `PARTIAL`    — we hold some. Both numbers are carried, because «۱۲ از ۳۰»
 *                   and «۳۰ از ۳۰» are different situations and one number
 *                   cannot tell them apart.
 *  - `PENDING`    — the source has rows and we hold none. Nothing is wrong;
 *                   nobody has pressed the button.
 *  - `EMPTY`      — the source was read and holds nothing. There is nothing to
 *                   import and nothing is missing.
 *  - `UNREADABLE` — a read failed, on either side. **Never nought.**
 *  - `UNCHECKED`  — nothing was established. Either Dokan is not running, or
 *                   the table is not on this install, or our own record store
 *                   is not bound — each carried in `reason` so the page can
 *                   say which.
 *
 * `UNREADABLE` and `UNCHECKED` are kept apart because they ask different
 * things of a person: the first is «something is broken, look at it» and the
 * second is «there was nothing here to ask». Giving them one name is the
 * mistake `alpha.68` recorded one layer down — two «don't know»s under one
 * label make a guard too wide.
 *
 * ### What it deliberately does not do
 *
 * It does not import, approve, grant, settle or decide. It does not add the
 * closing balance to the withdrawals — they overlap by design, and the
 * handover page's own rule is that no screen puts a plus sign between them.
 */
final class ShopRecordPreview
{
    public const IMPORTED = 'imported';

    public const PARTIAL = 'partial';

    public const PENDING = 'pending';

    public const EMPTY = 'empty';

    public const UNREADABLE = 'unreadable';

    public const UNCHECKED = 'unchecked';

    /** The three kinds, in the order the import walks them. */
    public const KINDS = ['staff', 'balance', 'withdrawals'];

    public function __construct(
        private readonly DokanReaderInterface $dokan,
        /**
         * Optional, and `null` is reported rather than treated as nought: an
         * install below schema 17 has nowhere to keep records, so what we hold
         * is genuinely unestablished. Same shape as `ImportFromDokan`'s own
         * optional dependency on it.
         */
        private readonly ?ShopRecordRepositoryInterface $records = null
    ) {
    }

    /**
     * One shop, three kinds, each with a verdict and both counts.
     *
     * @return array{
     *     vendor_user_id:int,
     *     kinds:array<string, array{verdict:string, reason:string, source:array{state:string, count:int|null}, imported:int|null}>,
     *     anything_to_import:bool,
     *     anything_unreadable:bool
     * }
     */
    public function forVendor(int $vendorUserId): array
    {
        $source = $this->dokan->recordSourceFor($vendorUserId);
        $held = $this->records?->importedCountsFor($vendorUserId)
            ?? ['staff' => null, 'balance' => null, 'withdrawals' => null];

        $kinds = [];
        $toImport = false;
        $unreadable = false;
        foreach (self::KINDS as $kind) {
            $kindSource = $source[$kind] ?? RecordSource::unreadable();
            $kindHeld = $held[$kind] ?? null;
            $row = $this->verdict($kindSource, $kindHeld, $this->records === null);
            $kinds[$kind] = [
                'verdict' => $row[0],
                'reason' => $row[1],
                'source' => $kindSource->toArray(),
                'imported' => $kindHeld,
            ];
            if (in_array($row[0], [self::PENDING, self::PARTIAL], true)) {
                $toImport = true;
            }
            if ($row[0] === self::UNREADABLE) {
                $unreadable = true;
            }
        }

        return [
            'vendor_user_id' => $vendorUserId,
            'kinds' => $kinds,
            'anything_to_import' => $toImport,
            'anything_unreadable' => $unreadable,
        ];
    }

    /**
     * @param list<int> $vendorUserIds
     * @return list<array<string,mixed>>
     */
    public function forVendors(array $vendorUserIds): array
    {
        $out = [];
        foreach ($vendorUserIds as $vendorUserId) {
            $out[] = $this->forVendor((int) $vendorUserId);
        }
        return $out;
    }

    /**
     * Whether any shop in the list has records waiting to be brought over.
     *
     * Used to decide whether the import button is worth showing, and NOT to
     * decide anything about money or access.
     *
     * @param list<int> $vendorUserIds
     */
    public function anythingToImport(array $vendorUserIds): bool
    {
        foreach ($this->forVendors($vendorUserIds) as $row) {
            if ($row['anything_to_import'] === true) {
                return true;
            }
        }
        return false;
    }

    /**
     * The verdict for one kind, with the order of the tests being the point.
     *
     * ⛔ **An unreadable side is decided before anything else.** If either read
     * failed, nothing further is knowable and no count may be shown as a
     * total — so that test comes first, ahead of «did we import any», which
     * would otherwise answer `IMPORTED` off a number whose completeness
     * nobody could check.
     *
     * @return array{0:string, 1:string} verdict, machine-readable reason
     */
    private function verdict(RecordSource $source, ?int $imported, bool $storeMissing): array
    {
        if ($storeMissing) {
            return [self::UNCHECKED, 'record_store_not_installed'];
        }
        if ($imported === null) {
            return [self::UNREADABLE, 'our_records_unreadable'];
        }
        if ($source->state === RecordSource::UNREADABLE) {
            return [self::UNREADABLE, 'source_unreadable'];
        }
        if ($source->state === RecordSource::DOKAN_ABSENT) {
            return [self::UNCHECKED, 'dokan_not_running'];
        }
        if ($source->state === RecordSource::SOURCE_ABSENT) {
            // Not `EMPTY`: this install has nothing that would ever write such
            // a row, so «صفر» would be a statement about the shop made from a
            // fact about the plugin. Dokan Lite and vendor staff are exactly
            // this case.
            return [self::UNCHECKED, 'source_not_on_this_install'];
        }
        $rows = (int) $source->count;
        if ($rows === 0) {
            // And if we somehow hold rows the source no longer has, say so
            // rather than printing «صفر سابقه» over the top of them.
            return $imported > 0
                ? [self::IMPORTED, 'held_but_source_now_empty']
                : [self::EMPTY, 'source_has_none'];
        }
        if ($imported === 0) {
            return [self::PENDING, 'not_imported_yet'];
        }
        return $imported >= $rows
            ? [self::IMPORTED, 'all_rows_held']
            : [self::PARTIAL, 'some_rows_held'];
    }
}
