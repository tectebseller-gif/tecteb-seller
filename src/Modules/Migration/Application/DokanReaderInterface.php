<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

/**
 * Everything this plugin reads from Dokan — and it is only ever reading.
 *
 * There is no write method on this interface, and that absence is the point.
 * The standing rule is «هیچ افزونه موجودی (از جمله دکان) حذف/غیرفعال/ویرایش
 * نمی‌شود»; a migration that could modify Dokan's rows would be one bug away
 * from breaking a live shop, so the capability does not exist at all.
 */
interface DokanReaderInterface
{
    /** False when Dokan is not installed; every other method then answers empty. */
    public function isAvailable(): bool;

    /**
     * Dokan's sellers.
     *
     * @return list<array{user_id:int, store_name:string, email:string, enabled:bool}>
     */
    public function vendors(): array;

    /**
     * The products Dokan attributes to a seller.
     *
     * @return list<array{wc_product_id:int, vendor_user_id:int, title:string, sku:string, price_minor:int, stock:int}>
     */
    public function products(): array;

    /**
     * Orders Dokan recorded against a seller.
     *
     * @return list<array{wc_order_id:int, vendor_user_id:int, status:string, total_minor:int}>
     */
    public function orders(): array;
}
