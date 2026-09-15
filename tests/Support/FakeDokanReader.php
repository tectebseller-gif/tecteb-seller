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
}
