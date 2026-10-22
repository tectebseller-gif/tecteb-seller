<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Finance\Application\LedgerRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerAccount;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerEntry;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerTransaction;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables as T;

/**
 * The ledger. Inserts only — there is no update or delete method on purpose.
 *
 * The whole transaction goes in one INSERT with several VALUES tuples, so the
 * unique index on (event_key, account) decides the race: either every line of
 * an event lands or none does, and a duplicate callback loses cleanly.
 */
final class DbLedgerRepository implements LedgerRepositoryInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock
    ) {
    }

    public function record(LedgerTransaction $transaction): bool
    {
        if ($transaction->isEmpty() || !$transaction->balances()) {
            return false;
        }
        $now = $this->now();
        $tuples = [];
        $params = [];
        foreach ($transaction->lines() as $line) {
            /** @var Money $amount */
            $amount = $line['amount'];
            $tuples[] = '(%s, %d, %s, %d, %s, %d, %s, %s, %s, ' . ($line['reverses'] === null ? 'NULL' : '%d') . ', %s, %s)';
            array_push(
                $params,
                $transaction->eventKey,
                $transaction->vendorUserId,
                $line['account']->value,
                $amount->minor,
                $amount->currency,
                $amount->exponent,
                $transaction->orderRef,
                $transaction->itemRef,
                (string) $line['reason']
            );
            if ($line['reverses'] !== null) {
                $params[] = (int) $line['reverses'];
            }
            array_push($params, $transaction->snapshotJson(), $now);
        }

        $sql = 'INSERT INTO `' . $this->table() . '`
                (event_key, vendor_user_id, account, amount_minor, currency, exponent, order_ref, item_ref, reason, reverses_entry_id, snapshot, created_at)
                VALUES ' . implode(', ', $tuples);
        $written = $this->db->execute($sql, $params) !== null;
        if ($written) {
            $this->figuresChanged($transaction->vendorUserId);
        }
        return $written;
    }

    public function hasEvent(string $eventKey): bool
    {
        return (int) $this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->table() . '` WHERE event_key = %s',
            [$eventKey]
        ) > 0;
    }

    public function unitsFor(int $vendorUserId): array
    {
        $rows = $this->db->getResults(
            'SELECT DISTINCT currency, exponent FROM `' . $this->table() . '`
             WHERE vendor_user_id = %d ORDER BY currency ASC, exponent ASC',
            [$vendorUserId]
        );
        return array_map(
            static fn (array $row): array => [
                'currency' => (string) $row['currency'],
                'exponent' => (int) $row['exponent'],
            ],
            $rows
        );
    }

    public function vendorsWithMixedUnits(int $limit = 200): array
    {
        // ⛔ MIXED MEANS TWO CURRENCIES, not two exponents — and that
        // narrowed in `alpha.67`. From that release a vendor's books hold the
        // exponent their orders were captured at AND the finer one an exact
        // commission needs, which are the same money written twice
        // (`Money::rescale()` is a power of ten). Counting them as two units
        // would put EVERY upgraded vendor on this report and make the health
        // page shout about a site that is correct — a guard wider than the
        // defect is worse than the defect (`alpha.60`).
        //
        // `units` still lists each `currency/exponent` seen, because a person
        // meeting a blocked order wants to know exactly what is on the books.
        $rows = $this->db->getResults(
            'SELECT vendor_user_id,
                    GROUP_CONCAT(DISTINCT CONCAT(currency, \'/\', exponent) ORDER BY currency, exponent) AS units,
                    COUNT(*) AS entries
               FROM `' . $this->table() . '`
              WHERE vendor_user_id > 0
              GROUP BY vendor_user_id
             HAVING COUNT(DISTINCT currency) > 1
              ORDER BY vendor_user_id ASC LIMIT %d',
            [max(1, min(1000, $limit))]
        );
        return array_map(
            static fn (array $row): array => [
                'vendor_user_id' => (int) $row['vendor_user_id'],
                'units' => (string) $row['units'],
                'entries' => (int) $row['entries'],
            ],
            $rows
        );
    }

    public function coversOrder(int $wcOrderId): bool
    {
        if ($wcOrderId <= 0) {
            return false;
        }
        // `LIKE 'order:12:item:%'` — anchored at the start, so it uses the
        // index rather than scanning. The trailing colon matters: without it
        // `order:1:item:` would also match order 12's keys.
        return (int) $this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->table() . '` WHERE event_key LIKE %s LIMIT 1',
            ['order:' . $wcOrderId . ':item:%']
        ) > 0;
    }

    public function forVendor(int $vendorUserId, int $limit = 100): array
    {
        $rows = $this->db->getResults(
            'SELECT * FROM `' . $this->table() . '` WHERE vendor_user_id = %d ORDER BY id DESC LIMIT %d',
            [$vendorUserId, max(1, min(500, $limit))]
        );
        return array_map(fn (array $row): LedgerEntry => $this->hydrate($row), $rows);
    }

    public function forEvent(string $eventKey): array
    {
        $rows = $this->db->getResults(
            'SELECT * FROM `' . $this->table() . '` WHERE event_key = %s ORDER BY id ASC',
            [$eventKey]
        );
        return array_map(fn (array $row): LedgerEntry => $this->hydrate($row), $rows);
    }

    /**
     * Sum per account — PER UNIT, combined exactly, and never across two
     * currencies.
     *
     * ⛔ **`SUM(amount_minor)` grouped by account alone was a sum of two
     * different things, and the owner's §2 names the control.** Every row of
     * `tmc_ledger_entries` has carried `currency` and `exponent` since
     * migration 4, and from `alpha.67` one vendor's books legitimately hold
     * two exponents of one currency: the order's own, and the four-places-
     * finer one an exact commission needs. Measured on `alpha.67`'s bytes
     * with ۱۰۰ تومان at exponent 0 and ۸۸٫۳۷۵ تومان at exponent 4 in one
     * account: `rows_at_e0=100`, `rows_at_e4=883750`, and the answer was
     * `883850` — a number that is neither figure in any unit. The exact
     * answer is ۱۸۸٫۳۷۵, which is `1883750` at exponent 4.
     *
     * So the grouping includes the unit, and the groups are combined in PHP
     * by a power of ten — exact, no `float`, and no `POW()`, which returns a
     * float in MySQL and is the same mistake one layer down (`alpha.39`).
     *
     * **Two currencies are not combined at all, in either direction.** A
     * marketplace records in one currency; a second one appearing is a fact
     * to report, not an average (`vendorsWithMixedUnits()` is the reporting
     * half). Such an account answers with `refused: mixed_currencies`, the
     * currencies named, and NO figure — because the honest answer to «what
     * is the total» across two kinds of money is that there is not one.
     *
     * The figure comes back with its unit and NOT as text: formatting is
     * `MoneyText`'s job one layer out, and a repository that returned a
     * rendered string would be a second place that decides how money looks.
     *
     * @return array<string, array{
     *   minor:int|null, currency:string, exponent:int|null,
     *   exact:bool, refused:string, currencies:string,
     *   groups:list<array{currency:string, exponent:int, minor:int}>
     * }>
     */
    public function balances(int $vendorUserId): array
    {
        $rows = $this->db->getResults(
            'SELECT account, currency, exponent, SUM(amount_minor) AS total
               FROM `' . $this->table() . '`
              WHERE vendor_user_id = %d
              GROUP BY account, currency, exponent
              ORDER BY account ASC, currency ASC, exponent ASC',
            [$vendorUserId]
        );
        /** @var array<string, list<array{currency:string, exponent:int, minor:int}>> $byAccount */
        $byAccount = [];
        foreach ($rows as $row) {
            $byAccount[(string) $row['account']][] = [
                'currency' => strtoupper(trim((string) $row['currency'])),
                'exponent' => (int) $row['exponent'],
                'minor' => (int) $row['total'],
            ];
        }
        $out = [];
        foreach ($byAccount as $account => $groups) {
            $out[$account] = self::combine($groups);
        }
        return $out;
    }

    /**
     * One account's groups brought into one unit, or a named refusal.
     *
     * The unit is the FINEST exponent present, so every other group is
     * multiplied into it — a whole power of ten, which loses nothing. The
     * other direction would divide and would have to round, and rounding is
     * the one thing this release does not do.
     *
     * @param list<array{currency:string, exponent:int, minor:int}> $groups
     * @return array{minor:int|null, currency:string, exponent:int|null,
     *               exact:bool, refused:string, currencies:string,
     *               groups:list<array{currency:string, exponent:int, minor:int}>}
     */
    private static function combine(array $groups): array
    {
        $currencies = [];
        $finest = null;
        foreach ($groups as $group) {
            $currencies[$group['currency']] = true;
            $finest = $finest === null ? $group['exponent'] : max($finest, $group['exponent']);
        }
        $names = array_keys($currencies);
        sort($names);
        if (count($names) > 1) {
            return [
                'minor' => null,
                'currency' => '',
                'exponent' => null,
                'exact' => false,
                'refused' => 'mixed_currencies',
                'currencies' => implode(',', $names),
                'groups' => $groups,
            ];
        }
        $minor = 0;
        foreach ($groups as $group) {
            $factor = 10 ** max(0, (int) $finest - $group['exponent']);
            // ⛔ ASKED BEFORE THE MULTIPLICATION, never after. An `int` that
            // overflows in PHP becomes a FLOAT, and a float in a money path
            // is the one thing this release does not allow — it would answer
            // a figure that looks like a total and is not. `VendorBalance`
            // guards the same multiplication the same way (`alpha.67`).
            if ($group['minor'] !== 0 && intdiv(PHP_INT_MAX, abs($group['minor'])) < $factor) {
                return [
                    'minor' => null,
                    'currency' => (string) ($names[0] ?? ''),
                    'exponent' => null,
                    'exact' => false,
                    'refused' => 'scale_exceeds_integer_range',
                    'currencies' => (string) ($names[0] ?? ''),
                    'groups' => $groups,
                ];
            }
            $minor += $group['minor'] * $factor;
        }
        return [
            'minor' => $minor,
            'currency' => (string) ($names[0] ?? ''),
            'exponent' => $finest,
            'exact' => true,
            'refused' => '',
            'currencies' => (string) ($names[0] ?? ''),
            'groups' => $groups,
        ];
    }

    private function table(): string
    {
        return T::table($this->db, T::LEDGER);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): LedgerEntry
    {
        return new LedgerEntry(
            (int) $row['id'],
            (string) $row['event_key'],
            (int) $row['vendor_user_id'],
            LedgerAccount::tryFrom((string) $row['account']) ?? LedgerAccount::CentralPayment,
            Money::of((int) $row['amount_minor'], (string) $row['currency'], (int) $row['exponent']),
            (string) $row['order_ref'],
            (string) $row['item_ref'],
            (string) $row['reason'],
            $row['reverses_entry_id'] !== null ? (int) $row['reverses_entry_id'] : null,
            (string) ($row['snapshot'] ?? ''),
            (string) $row['created_at']
        );
    }

    /**
     * The one hook a report cache listens to.
     *
     * Fired from the repository rather than from the service above it,
     * because Application may not call WordPress — and fired from the WRITE
     * rather than from the caller, which is the mistake `alpha.16` made with
     * the store page: forgetting before the save let a read land in between
     * and re-cache the stale answer under the new version.
     */
    private function figuresChanged(int $vendorUserId): void
    {
        if ($vendorUserId > 0 && function_exists('do_action')) {
            do_action('tmc_vendor_figures_changed', $vendorUserId);
        }
    }
}
