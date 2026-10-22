<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Application;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables as Finance;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0022VendorMoneyUnit as Units;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0033MoneyUnitOnRows as Unit;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders as Catalog;

/**
 * WHETHER GOING BACK TO `alpha.66` IS STILL SAFE — answered from the
 * database, not by looking at rows by hand.
 *
 * ⛔ **Why this class exists.** `alpha.67` shipped §47 of
 * `docs/upgrade-and-rollback.md` telling the reader to inspect rows before
 * rolling back. The owner rejected that, and rightly: «بررسی دستی ردیف‌ها
 * به‌تنهایی مسیر امن بازگشت نیست». A human reading a table cannot be sure
 * they looked at every row, cannot look at the rows a sale writes while they
 * are looking, and has nothing to show afterwards. So the question becomes a
 * COUNT, and the count is the gate.
 *
 * **The question, precisely.** `alpha.66` and everything before it reads an
 * amount at the vendor's one money unit — the registry's exponent. From
 * `alpha.67` an exact commission is recorded FOUR places finer and the row
 * says so in `tmc_exponent`. So the hazard is exactly: a row whose stated
 * exponent is finer than that vendor's registry exponent. `alpha.66` would
 * read such a row as a figure ten thousand times too large. One is enough:
 * this is not a threshold.
 *
 * **Where it looks.** Every store that carries money AND its own unit:
 * `tmc_ledger_entries` (migration 4, which is the authoritative record), and
 * the four tables migration 33 reached — order items, withdrawals,
 * withdrawal lines and returns. A vendor with NO registry row is compared
 * against exponent 0, which is what every release up to `alpha.66`
 * effectively assumed.
 *
 * **Three answers, not two** (`alpha.49`). `safe` means every table was read
 * and nothing finer was found. `blocked` means something finer exists, with
 * the count per table. `unknown` means a read failed — and that is NOT
 * «safe»: a gate that answers «go ahead» because it could not look is worse
 * than no gate (`alpha.60`).
 *
 * **What this class does NOT do, and will not.** It does not convert
 * anything, and there is no method to. A down-conversion would have to divide
 * ۱۲٫۶۲۵ by ten thousand and round, which is the one thing this release
 * forbids; and a figure rewritten at the coarse scale could never be
 * recovered. The only compatible recovery is restoring the backup taken
 * before the upgrade, and `lossSince()` counts ONE part of what that costs:
 * the money rows this plugin added since the dump. It is a lower bound on
 * the money side, not an estimate of the restore — see its own docblock.
 */
final class MoneyScaleRollbackGate
{
    public const SAFE = 'safe';
    public const BLOCKED = 'blocked';
    public const UNKNOWN = 'unknown';

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * The gate.
     *
     * @return array{verdict:string, safe:bool, readable:bool, reason:string,
     *               counts:array<string,int|null>, vendors:list<int>}
     */
    public function check(): array
    {
        $counts = [];
        $vendors = [];
        $readable = true;
        foreach ($this->tables() as $label => $table) {
            $answer = $this->finerRowsIn($table['name'], $table['exponent']);
            $counts[$label] = $answer['count'];
            if ($answer['count'] === null) {
                $readable = false;
                continue;
            }
            foreach ($answer['vendors'] as $vendorUserId) {
                $vendors[$vendorUserId] = true;
            }
        }
        $finer = 0;
        foreach ($counts as $count) {
            $finer += (int) $count;
        }
        $vendorList = array_map('intval', array_keys($vendors));
        sort($vendorList);

        if (!$readable) {
            // «نمی‌دانم» — and a rollback gate that cannot read refuses,
            // because the cost of being wrong here is a ledger read ten
            // thousand times out.
            return [
                'verdict' => self::UNKNOWN,
                'safe' => false,
                'readable' => false,
                'reason' => 'one of the money tables could not be read; this is not an answer of «safe»',
                'counts' => $counts,
                'vendors' => $vendorList,
            ];
        }
        if ($finer > 0) {
            return [
                'verdict' => self::BLOCKED,
                'safe' => false,
                'readable' => true,
                'reason' => $finer . ' row(s) are recorded at an exponent finer than their vendor\'s '
                    . 'registry unit; alpha.66 would read each of them as a figure 10,000 times too large',
                'counts' => $counts,
                'vendors' => $vendorList,
            ];
        }
        return [
            'verdict' => self::SAFE,
            'safe' => true,
            'readable' => true,
            'reason' => 'no row carries an exponent finer than its vendor\'s registry unit',
            'counts' => $counts,
            'vendors' => $vendorList,
        ];
    }

    /**
     * NEW ROWS IN THE FIVE MONEY TABLES SINCE `$takenAt` — counted.
     *
     * ⛔ **THIS IS NOT AN ESTIMATE OF WHAT RESTORING A BACKUP WOULD COST,
     * and `alpha.68` presented it as one.** The owner was right to reject
     * that framing. Restoring a database dump puts back the WHOLE database,
     * so it also discards, with no count of any kind here:
     *
     *   * orders, customers, users, products and posts created since the
     *     dump — none of which live in these tables;
     *   * every EDIT to a row that already existed when the dump was taken,
     *     in these tables and everywhere else. This counts rows by
     *     `created_at`, so a withdrawal that was approved, an obligation
     *     that was paid or a product whose price changed afterwards is
     *     invisible to it;
     *   * anything a plugin or the theme stores outside these five tables,
     *     including WooCommerce's own order tables and post meta.
     *
     * So what it answers is exactly one narrow question — «how many money
     * rows did this plugin add after that moment» — and it is useful for
     * that: a `0` means this plugin recorded nothing new, and a number means
     * a human must look at those rows before anybody restores anything. It
     * is a lower bound on the money side and nothing more. The full cost of
     * a restore is a judgement about the whole site, and
     * `docs/upgrade-and-rollback.md` §49 states it in those words rather
     * than offering a figure that would be believed.
     *
     * Nothing is deleted and nothing is written; this is a question.
     *
     * `null` for a table means the count could not be read, which is
     * reported rather than shown as nought.
     *
     * @param string $takenAt `Y-m-d H:i:s`, the backup's own moment
     * @return array{taken_at:string, readable:bool, rows:array<string,int|null>, total:int}
     */
    public function lossSince(string $takenAt): array
    {
        $rows = [];
        $readable = true;
        $total = 0;
        foreach ($this->tables() as $label => $table) {
            $count = $this->db->getVar(
                'SELECT COUNT(*) FROM `' . $table['name'] . '` WHERE created_at > %s',
                [$takenAt]
            );
            if ($count === null) {
                $rows[$label] = null;
                $readable = false;
                continue;
            }
            $rows[$label] = (int) $count;
            $total += (int) $count;
        }
        return [
            'taken_at' => $takenAt,
            'readable' => $readable,
            'rows' => $rows,
            'total' => $total,
        ];
    }

    /**
     * The money stores and the column each keeps its exponent in.
     *
     * @return array<string, array{name:string, exponent:string}>
     */
    private function tables(): array
    {
        return [
            'ledger_entries' => [
                'name' => Finance::table($this->db, Finance::LEDGER),
                // The ledger has carried its own unit since migration 4.
                'exponent' => 'exponent',
            ],
            'order_items' => [
                'name' => Catalog::table($this->db, Catalog::ORDER_ITEMS),
                'exponent' => Unit::EXPONENT,
            ],
            'withdrawals' => [
                'name' => Unit::table($this->db, Unit::WITHDRAWALS),
                'exponent' => Unit::EXPONENT,
            ],
            'withdrawal_lines' => [
                'name' => Unit::table($this->db, Unit::WITHDRAWAL_LINES),
                'exponent' => Unit::EXPONENT,
            ],
            'returns' => [
                'name' => Unit::table($this->db, Unit::RETURNS),
                'exponent' => Unit::EXPONENT,
            ],
        ];
    }

    /**
     * Rows in one table whose stated exponent is finer than their vendor's
     * registry unit — and which vendors they belong to.
     *
     * A `LEFT JOIN` so a vendor with no registry row is compared against
     * exponent 0: that is what every release up to `alpha.66` read such a
     * vendor's amounts at.
     *
     * @return array{count:int|null, vendors:list<int>}
     */
    private function finerRowsIn(string $table, string $exponentColumn): array
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1
            || preg_match('/^[A-Za-z0-9_]+$/', $exponentColumn) !== 1) {
            return ['count' => null, 'vendors' => []];
        }
        // The columns are asked for first: between a file swap and the
        // upgrade gate running they may not be there, and a gate that
        // reports a hard SQL error as «safe» is the shape this class exists
        // to remove.
        if ($exponentColumn === Unit::EXPONENT && Unit::unitColumnState($this->db, $table) !== Unit::PRESENT) {
            // ABSENT means this release's columns are not on the table, so no
            // row can carry a finer unit — a true nought. UNKNOWN is not.
            return Unit::unitColumnState($this->db, $table) === Unit::ABSENT
                ? ['count' => 0, 'vendors' => []]
                : ['count' => null, 'vendors' => []];
        }
        $units = Units::table($this->db, Units::UNITS);
        $rows = $this->db->getResults(
            'SELECT t.vendor_user_id AS vendor_user_id, COUNT(*) AS rows_finer
               FROM `' . $table . '` t
               LEFT JOIN `' . $units . '` u ON u.vendor_user_id = t.vendor_user_id
              WHERE t.`' . $exponentColumn . '` IS NOT NULL
                AND t.`' . $exponentColumn . '` > COALESCE(u.exponent, 0)
              GROUP BY t.vendor_user_id'
        );
        // ⛔ `getResults()` answers `[]` for «no such row» AND for «the read
        // failed» (`alpha.54`), and here those are opposites. So the count is
        // asked separately, where `null` can only mean failure.
        $total = $this->db->getVar(
            'SELECT COUNT(*)
               FROM `' . $table . '` t
               LEFT JOIN `' . $units . '` u ON u.vendor_user_id = t.vendor_user_id
              WHERE t.`' . $exponentColumn . '` IS NOT NULL
                AND t.`' . $exponentColumn . '` > COALESCE(u.exponent, 0)'
        );
        if ($total === null) {
            return ['count' => null, 'vendors' => []];
        }
        $vendors = [];
        foreach ($rows as $row) {
            $vendors[] = (int) $row['vendor_user_id'];
        }
        return ['count' => (int) $total, 'vendors' => $vendors];
    }
}
