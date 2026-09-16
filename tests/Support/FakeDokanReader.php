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

    /** @var list<array{wc_order_id:int, vendor_user_id:int, status:string, total_minor:int}> */
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
        return $this->available ? $this->orderRows : [];
    }

    /**
     * Keyset paging over the fixture rows, with the SAME semantics as the SQL:
     * strictly after the id, ascending, at most `$limit`.
     *
     * A fake that ignored the cursor and returned everything would make a
     * resumable-import test pass while the real reader re-imported page one for
     * ever — which is precisely the class of bug the cursor exists to prevent.
     */
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
