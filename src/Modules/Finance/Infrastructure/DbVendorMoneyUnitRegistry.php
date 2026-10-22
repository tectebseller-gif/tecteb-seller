<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Finance\Application\VendorMoneyUnitRegistryInterface;
use Tecteb\Marketplace\Modules\Finance\Domain\MoneyUnitClaim;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables as Ledger;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0022VendorMoneyUnit as T;

/**
 * Which money a vendor's books are kept in — recorded once, read thereafter.
 *
 * The row is the guard. `alpha.39` asked the LEDGER whether a unit was
 * acceptable, and the ledger holds many rows per vendor on purpose, so there
 * was no index for a second concurrent writer to collide with: two first
 * captures both saw an empty answer and both wrote, in different units. Here
 * the first `INSERT … ON DUPLICATE KEY` fixes the unit against a primary key
 * on the vendor, and every later claim — including the one a microsecond
 * behind — reads back what was fixed.
 *
 * Nothing in this class decides a business rule. It records the unit the first
 * accrual used and refuses to let a later one disagree.
 */
final class DbVendorMoneyUnitRegistry implements VendorMoneyUnitRegistryInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock
    ) {
    }

    public function claim(int $vendorUserId, string $currency, int $exponent): MoneyUnitClaim
    {
        $currency = strtoupper(trim($currency));
        if ($vendorUserId <= 0 || preg_match('/^[A-Z]{3}$/', $currency) !== 1 || $exponent < 0 || $exponent > 6) {
            // Refused before the database is touched: a unit `Money` cannot
            // carry is not a unit to record, and recording it would make the
            // next read throw instead of answering.
            return MoneyUnitClaim::refused(MoneyUnitClaim::UNSUPPORTED, $currency, $exponent);
        }

        // THE LEDGER FIRST, and the reason is upgrade order. A site coming from
        // schema 21 has a ledger and — for any vendor the migration found
        // already mixed — no row here. Claiming against the row alone would
        // let such a vendor's next accrual fix a unit over books that hold two,
        // which is the one thing «بدون تبدیل حدسی» rules out.
        $books = $this->ledgerUnits($vendorUserId);
        if ($books === null) {
            return MoneyUnitClaim::refused(MoneyUnitClaim::UNREADABLE);
        }
        // MORE THAN ONE CURRENCY — not more than one exponent. From
        // `alpha.67` a vendor's books hold both the exponent their orders
        // were captured at and the finer one an exact commission needs, and
        // those two are the same money written twice (`Money::rescale()` is a
        // power of ten). Counting exponents here refused every sale on every
        // upgraded site, which running the path showed and reading it did not.
        if (count($books) > 1) {
            return MoneyUnitClaim::refused(MoneyUnitClaim::MIXED);
        }

        // Then the keyed row, which is what makes this safe under concurrency:
        // the INSERT either fixes the unit or collides with the row that
        // already did, and the read afterwards is of the STORED value either
        // way. A no-op `ON DUPLICATE KEY UPDATE` rather than `INSERT IGNORE`
        // so a genuine failure is still `null` — `INSERT IGNORE` would hide a
        // broken write as «0 rows», and this repository has unpicked that
        // shape before.
        $written = $this->db->execute(
            'INSERT INTO `' . $this->table() . '`
             (`vendor_user_id`, `currency`, `exponent`, `source_event`, `created_at`)
             VALUES (%d, %s, %d, %s, %s)
             ON DUPLICATE KEY UPDATE `vendor_user_id` = `vendor_user_id`',
            [$vendorUserId, $currency, $exponent, 'claim', $this->now()]
        );
        if ($written === null) {
            return MoneyUnitClaim::refused(MoneyUnitClaim::UNREADABLE);
        }

        $stored = $this->storedUnit($vendorUserId);
        if ($stored === null) {
            // The write said it worked and the row is not there. Not assumed
            // either way: a unit nobody can read back is not a unit to record
            // money against.
            return MoneyUnitClaim::refused(MoneyUnitClaim::UNREADABLE);
        }
        // THE CURRENCY is what has to match. The stored row keeps the FIRST
        // unit it ever recorded and is deliberately NOT updated to a finer
        // exponent: it is the right answer for every legacy row that does not
        // carry its own unit, and rewriting it would reinterpret those rows.
        if ($stored['currency'] !== $currency) {
            return MoneyUnitClaim::refused(
                MoneyUnitClaim::DIFFERENT,
                $stored['currency'],
                $stored['exponent']
            );
        }
        // And the books, where they hold anything, must be in the same
        // currency. They can differ only on data from before this table
        // existed, and a different CURRENCY there is a person's decision
        // rather than a conversion.
        if ($books !== [] && $books[0]['currency'] !== $currency) {
            return MoneyUnitClaim::refused(
                MoneyUnitClaim::DIFFERENT,
                $books[0]['currency'],
                $books[0]['exponent']
            );
        }
        // The unit any sum over these books must be taken in: the finest of
        // what is recorded, what the row says, and what is being offered.
        $finest = max(
            $exponent,
            $stored['exponent'],
            $books === [] ? 0 : (int) $books[0]['exponent']
        );
        return MoneyUnitClaim::agreed($currency, $exponent, $finest);
    }

    public function unitOf(int $vendorUserId): ?array
    {
        if ($vendorUserId <= 0) {
            return null;
        }
        $stored = $this->storedUnit($vendorUserId);
        if ($stored !== null) {
            return $stored;
        }
        // No row — which on a site upgraded from schema 21 means either «no
        // sales yet» or «the migration found mixed books and deliberately
        // wrote nothing». The ledger answers which, and a single unit on disk
        // is a fact worth returning.
        $books = $this->ledgerUnits($vendorUserId);
        return $books !== null && count($books) === 1 ? $books[0] : null;
    }

    public function isMixed(int $vendorUserId): ?bool
    {
        $books = $this->ledgerUnits($vendorUserId);
        return $books === null ? null : count($books) > 1;
    }

    /**
     * The FINEST exponent this vendor's ledger holds, or `null` when unreadable.
     *
     * What a balance has to be summed in. Separate from `unitOf()`, which
     * answers the different question «what unit does a row that does not say
     * mean» — and that one is the COARSE, first-recorded unit. Two questions,
     * two answers, because `alpha.66` showed what one answer to two questions
     * does.
     */
    public function finestExponentOf(int $vendorUserId): ?int
    {
        if ($vendorUserId <= 0) {
            return null;
        }
        $books = $this->ledgerUnits($vendorUserId);
        if ($books === null) {
            return null;
        }
        if ($books === []) {
            return $this->storedUnit($vendorUserId)['exponent'] ?? 0;
        }
        return (int) $books[0]['exponent'];
    }

    /** @return array{currency:string, exponent:int}|null */
    private function storedUnit(int $vendorUserId): ?array
    {
        $row = $this->db->getRow(
            'SELECT `currency`, `exponent` FROM `' . $this->table() . '` WHERE `vendor_user_id` = %d',
            [$vendorUserId]
        );
        return $row === null
            ? null
            : ['currency' => (string) $row['currency'], 'exponent' => (int) $row['exponent']];
    }

    /**
     * Every distinct unit this vendor's LEDGER holds — or `null` when the
     * question could not be asked.
     *
     * `getResults()` answers an empty array for «no rows» and for «the query
     * failed» alike, which is the collision `alpha.37`'s migration was broken
     * by and `alpha.39`'s `unitsFor()` reintroduced here. So the shape of the
     * answer is read from a single row that carries a COUNT: `getRow()` gives
     * `null` only when the read failed, and an aggregate over an empty table
     * still returns a row — so `total = 0` is «no sales» and `null` is «no
     * answer», and the two can never be confused again.
     *
     * ⛔ **It counts distinct CURRENCY, not distinct `(currency, exponent)`,
     * and reports `MAX(exponent)` rather than `MIN`.** From `alpha.67` one
     * vendor's books legitimately hold two exponents of one currency — the
     * one their orders were captured at and the finer one an exact commission
     * needs — and those are the same money written twice. Counting them as
     * two units marked every upgraded vendor «mixed» and refused their next
     * sale; `MAX` is reported because the finest unit is the one a sum has to
     * be taken in, and every coarser row rescales into it losslessly.
     *
     * @return list<array{currency:string, exponent:int}>|null
     */
    private function ledgerUnits(int $vendorUserId): ?array
    {
        $table = Ledger::table($this->db, Ledger::LEDGER);
        $row = $this->db->getRow(
            'SELECT COUNT(*) AS total,
                    COUNT(DISTINCT `currency`) AS units,
                    MIN(`currency`) AS currency, MAX(`exponent`) AS exponent
               FROM `' . $table . '` WHERE `vendor_user_id` = %d',
            [$vendorUserId]
        );
        if ($row === null) {
            return null;
        }
        if ((int) $row['total'] === 0) {
            return [];
        }
        if ((int) $row['units'] > 1) {
            // More than one, and WHICH ones is a separate question a report
            // asks (`vendorsWithMixedUnits()`); the caller here only needs to
            // know it may not write.
            return [
                ['currency' => (string) $row['currency'], 'exponent' => (int) $row['exponent']],
                ['currency' => '', 'exponent' => -1],
            ];
        }
        return [['currency' => (string) $row['currency'], 'exponent' => (int) $row['exponent']]];
    }

    private function table(): string
    {
        return T::table($this->db, T::UNITS);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
