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
            array_push($params, '', $now);
        }

        $sql = 'INSERT INTO `' . $this->table() . '`
                (event_key, vendor_user_id, account, amount_minor, currency, exponent, order_ref, item_ref, reason, reverses_entry_id, snapshot, created_at)
                VALUES ' . implode(', ', $tuples);
        return $this->db->execute($sql, $params) !== null;
    }

    public function hasEvent(string $eventKey): bool
    {
        return (int) $this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->table() . '` WHERE event_key = %s',
            [$eventKey]
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

    public function balances(int $vendorUserId): array
    {
        $rows = $this->db->getResults(
            'SELECT account, SUM(amount_minor) AS total FROM `' . $this->table() . '`
             WHERE vendor_user_id = %d GROUP BY account',
            [$vendorUserId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['account']] = (int) $row['total'];
        }
        return $out;
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
}
