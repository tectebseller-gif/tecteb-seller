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

    public function counts(): array
    {
        return [
            'vendors' => count($this->vendors()),
            'products' => count($this->products()),
            'orders' => count($this->orders()),
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
