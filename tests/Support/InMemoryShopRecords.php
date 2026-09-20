<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Modules\Migration\Application\ShopRecordRepositoryInterface;

/**
 * Imported Dokan records in memory.
 *
 * Amounts stay strings all the way through, exactly as the real repository
 * keeps them, so a test that sums money is doing the same thing production
 * does rather than an easier thing that happens to agree.
 */
final class InMemoryShopRecords implements ShopRecordRepositoryInterface
{
    /** @var list<array<string,mixed>> */
    public array $staff = [];
    /** @var list<array<string,mixed>> */
    public array $balance = [];
    /** @var list<array<string,mixed>> */
    public array $withdrawals = [];

    public function recordStaff(string $runId, array $staff): string
    {
        foreach ($this->staff as $row) {
            if ($row['staff_user_id'] === $staff['staff_user_id'] && $row['vendor_user_id'] === $staff['vendor_user_id']) {
                return self::ALREADY;
            }
        }
        $this->staff[] = $staff + ['run_id' => $runId];
        return self::RECORDED;
    }

    public function recordBalance(string $runId, array $row): string
    {
        $this->balance[] = $row + ['run_id' => $runId];
        return self::RECORDED;
    }

    public function recordWithdrawal(string $runId, array $row): string
    {
        $this->withdrawals[] = $row + ['run_id' => $runId];
        return self::RECORDED;
    }

    public function summaryForVendor(int $vendorUserId): array
    {
        $balance = $this->rowsFor($this->balance, $vendorUserId);
        $withdraw = $this->rowsFor($this->withdrawals, $vendorUserId);
        return [
            'staff' => count($this->rowsFor($this->staff, $vendorUserId)),
            'balance_rows' => count($balance),
            'debit' => $this->sum($balance, 'debit'),
            'credit' => $this->sum($balance, 'credit'),
            'withdrawals' => count($withdraw),
            'withdrawn' => $this->sum($withdraw, 'amount'),
        ];
    }

    public function staffForVendor(int $vendorUserId): array
    {
        return array_values($this->rowsFor($this->staff, $vendorUserId));
    }

    public function withdrawalsByStatus(int $vendorUserId): array
    {
        $out = [];
        foreach ($this->rowsFor($this->withdrawals, $vendorUserId) as $row) {
            $status = (string) $row['status'];
            $out[$status] ??= ['count' => 0, 'rows' => []];
            $out[$status]['count']++;
            $out[$status]['rows'][] = $row;
        }
        foreach ($out as $status => $tally) {
            $out[$status] = ['count' => $tally['count'], 'total' => $this->sum($tally['rows'], 'amount')];
        }
        ksort($out);
        return $out;
    }

    public function balanceByType(int $vendorUserId): array
    {
        $out = [];
        foreach ($this->rowsFor($this->balance, $vendorUserId) as $row) {
            $type = (string) $row['trn_type'];
            $out[$type] ??= ['count' => 0, 'rows' => []];
            $out[$type]['count']++;
            $out[$type]['rows'][] = $row;
        }
        foreach ($out as $type => $tally) {
            $out[$type] = [
                'count' => $tally['count'],
                'debit' => $this->sum($tally['rows'], 'debit'),
                'credit' => $this->sum($tally['rows'], 'credit'),
            ];
        }
        ksort($out);
        return $out;
    }

    public function closingBalance(int $vendorUserId): string
    {
        $rows = $this->rowsFor($this->balance, $vendorUserId);
        return $this->format(
            $this->minor($this->sum($rows, 'credit')) - $this->minor($this->sum($rows, 'debit'))
        );
    }

    public function vendorsWithRecords(): array
    {
        $seen = [];
        foreach ([$this->staff, $this->balance, $this->withdrawals] as $set) {
            foreach ($set as $row) {
                $seen[(int) $row['vendor_user_id']] = true;
            }
        }
        $ids = array_keys($seen);
        sort($ids);
        return $ids;
    }

    public function deleteRun(string $runId): array
    {
        if ($runId === '') {
            return ['staff' => 0, 'balance' => 0, 'withdrawals' => 0];
        }
        $counts = [];
        foreach (['staff', 'balance', 'withdrawals'] as $set) {
            $before = count($this->{$set});
            $this->{$set} = array_values(array_filter(
                $this->{$set},
                static fn (array $row): bool => ($row['run_id'] ?? '') !== $runId
            ));
            $counts[$set] = $before - count($this->{$set});
        }
        return $counts;
    }

    public function countsForRun(string $runId): array
    {
        $counts = [];
        foreach (['staff', 'balance', 'withdrawals'] as $set) {
            $counts[$set] = count(array_filter(
                $this->{$set},
                static fn (array $row): bool => ($row['run_id'] ?? '') === $runId
            ));
        }
        return $counts;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function rowsFor(array $rows, int $vendorUserId): array
    {
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => (int) $row['vendor_user_id'] === $vendorUserId
        ));
    }

    /**
     * Summed in integer hundredths of a unit rather than with `+` on floats,
     * for the reason the whole feature exists: a rounded balance is somebody's
     * money.
     *
     * @param list<array<string,mixed>> $rows
     */
    private function sum(array $rows, string $key): string
    {
        $total = 0;
        foreach ($rows as $row) {
            $total += $this->minor((string) ($row[$key] ?? '0'));
        }
        return $this->format($total);
    }

    private function minor(string $amount): int
    {
        $amount = trim($amount);
        $negative = str_starts_with($amount, '-');
        $digits = (string) preg_replace('/[^0-9.]/', '', $amount);
        [$whole, $fraction] = array_pad(explode('.', $digits, 2), 2, '');
        $fraction = substr($fraction . '0000', 0, 4);
        $value = ((int) $whole) * 10000 + (int) $fraction;
        return $negative ? -$value : $value;
    }

    private function format(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);
        return $sign . intdiv($minor, 10000) . '.' . str_pad((string) ($minor % 10000), 4, '0', STR_PAD_LEFT);
    }
}
