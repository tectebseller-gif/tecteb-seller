<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Modules\Migration\Application\DokanReaderInterface;

/**
 * Dokan's data without Dokan.
 *
 * It can only be read, exactly like the real one — there is no setter that
 * writes back, because the interface it implements has no such method and the
 * test would otherwise be able to prove a safety property the production code
 * does not have.
 */
final class FakeDokanReader implements DokanReaderInterface
{
    /** @var list<array{user_id:int, store_name:string, email:string, enabled:bool}> */
    public array $vendorRows = [];

    /** @var list<array{wc_product_id:int, vendor_user_id:int, title:string, sku:string, price_minor:int, stock:int}> */
    public array $productRows = [];

    /**
     * @var list<array{wc_order_id:int, vendor_user_id:int, status:string, total_minor:int, net_minor?:int, commission_minor?:int, refunded?:bool}>
     *
     * The three history keys are optional in a fixture and filled in by
     * `orders()`, so a test that only cares about ids does not have to state
     * money it is not testing.
     */
    public array $orderRows = [];

    public bool $available = true;

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function vendors(): array
    {
        return $this->available ? $this->vendorRows : [];
    }

    public function products(): array
    {
        return $this->available ? $this->productRows : [];
    }

    public function orders(): array
    {
        if (!$this->available) {
            return [];
        }
        return array_map(static function (array $row): array {
            $total = (int) $row['total_minor'];
            // Unknown net is the total, never a computed share — the same rule
            // the real reader follows, so a fixture cannot accidentally prove a
            // recalculation the production code refuses to do.
            $row['net_minor'] ??= $total;
            $row['commission_minor'] ??= max(0, $total - (int) $row['net_minor']);
            $row['refunded'] ??= false;
            return $row;
        }, $this->orderRows);
    }

    /**
     * Keyset paging over the fixture rows, with the SAME semantics as the SQL:
     * strictly after the id, ascending, at most `$limit`.
     *
     * A fake that ignored the cursor and returned everything would make a
     * resumable-import test pass while the real reader re-imported page one for
     * ever — which is precisely the class of bug the cursor exists to prevent.
     */
    public function vendorsAfter(int $afterUserId, int $limit = DokanReaderInterface::PAGE): array
    {
        return $this->page($this->vendors(), 'user_id', $afterUserId, $limit);
    }

    public function productsAfter(int $afterId, int $limit = DokanReaderInterface::PAGE): array
    {
        return $this->page($this->products(), 'wc_product_id', $afterId, $limit);
    }

    public function ordersAfter(int $afterId, int $limit = DokanReaderInterface::PAGE): array
    {
        return $this->page($this->orders(), 'wc_order_id', $afterId, $limit);
    }

    /** @var list<array<string,mixed>> */
    public array $staffRows = [];

    /** @var list<array<string,mixed>> */
    public array $balanceRows = [];

    /** @var list<array<string,mixed>> */
    public array $withdrawRows = [];

    public function staffFor(int $vendorUserId): array
    {
        $out = [];
        foreach ($this->staffRows as $row) {
            if ((int) ($row['vendor_user_id'] ?? 0) !== $vendorUserId) {
                continue;
            }
            $out[] = [
                'staff_user_id' => (int) $row['staff_user_id'],
                'vendor_user_id' => $vendorUserId,
                'display_name' => (string) ($row['display_name'] ?? ''),
                'user_email' => (string) ($row['user_email'] ?? ''),
                'dokan_role' => (string) ($row['dokan_role'] ?? ''),
            ];
        }
        return $out;
    }

    public function balanceRowsAfter(int $afterId, int $limit = self::PAGE): array
    {
        return $this->pageOf($this->balanceRows, 'row_id', $afterId, $limit, static fn (array $row): array => [
            'row_id' => (int) $row['row_id'],
            'trn_id' => (int) $row['trn_id'],
            'vendor_user_id' => (int) $row['vendor_user_id'],
            'trn_type' => (string) ($row['trn_type'] ?? ''),
            'particulars' => (string) ($row['particulars'] ?? ''),
            // Strings throughout, exactly as the real reader returns them:
            // a fake that handed back floats would let a rounding bug pass.
            'debit' => (string) ($row['debit'] ?? '0.0000'),
            'credit' => (string) ($row['credit'] ?? '0.0000'),
            'status' => (string) ($row['status'] ?? ''),
            'trn_date' => (string) ($row['trn_date'] ?? ''),
        ]);
    }

    public function withdrawalsAfter(int $afterId, int $limit = self::PAGE): array
    {
        return $this->pageOf($this->withdrawRows, 'withdraw_id', $afterId, $limit, static fn (array $row): array => [
            'withdraw_id' => (int) $row['withdraw_id'],
            'vendor_user_id' => (int) $row['vendor_user_id'],
            'amount' => (string) ($row['amount'] ?? '0.0000'),
            'status' => (string) ($row['status'] ?? ''),
            'method' => (string) ($row['method'] ?? ''),
            'note' => (string) ($row['note'] ?? ''),
            'requested_at' => (string) ($row['requested_at'] ?? ''),
        ]);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param callable(array<string,mixed>):array<string,mixed> $shape
     * @return list<array<string,mixed>>
     */
    private function pageOf(array $rows, string $key, int $afterId, int $limit, callable $shape): array
    {
        $out = [];
        foreach ($rows as $row) {
            if ((int) ($row[$key] ?? 0) <= $afterId) {
                continue;
            }
            $out[] = $shape($row);
            if (count($out) >= max(1, $limit)) {
                break;
            }
        }
        return $out;
    }

    public function counts(): array
    {
        return [
            'vendors' => count($this->vendors()),
            'products' => count($this->products()),
            'orders' => count($this->orders()),
            'staff' => count($this->staffRows),
            'balance' => count($this->balanceRows),
            'withdrawals' => count($this->withdrawRows),
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function page(array $rows, string $idKey, int $afterId, int $limit): array
    {
        $after = array_values(array_filter($rows, static fn (array $r): bool => (int) $r[$idKey] > $afterId));
        usort($after, static fn (array $a, array $b): int => (int) $a[$idKey] <=> (int) $b[$idKey]);
        return array_slice($after, 0, max(1, $limit));
    }
}
