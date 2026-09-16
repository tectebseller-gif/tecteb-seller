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
    /**
     * How many rows one page asks for. Not a limit on the data: a caller that
     * wants all of it keeps asking until a page comes back short.
     */
    public const PAGE = 200;

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
     * One page of products, strictly after `$afterId`, ascending.
     *
     * Keyset rather than OFFSET, for the reason the unpaid-order guard learned
     * the hard way in `alpha.11`: an OFFSET walk over a set somebody else is
     * changing skips rows silently. Here the changing party is Dokan itself —
     * a seller publishing a product mid-import — and a skipped row would be a
     * product that never arrived, with nothing anywhere saying so.
     *
     * @return list<array{wc_product_id:int, vendor_user_id:int, title:string, sku:string, price_minor:int, stock:int}>
     */
    public function productsAfter(int $afterId, int $limit = self::PAGE): array;

    /**
     * Orders Dokan recorded against a seller.
     *
     * @return list<array{wc_order_id:int, vendor_user_id:int, status:string, total_minor:int}>
     */
    public function orders(): array;

    /**
     * One page of order rows, strictly after `$afterId`, ascending.
     *
     * @return list<array{wc_order_id:int, vendor_user_id:int, status:string, total_minor:int}>
     */
    public function ordersAfter(int $afterId, int $limit = self::PAGE): array;

    /**
     * How many rows there are in total, per kind, so a caller can say «۴ از
     * ۳٬۲۰۰» instead of «در حال کار».
     *
     * @return array{vendors:int, products:int, orders:int}
     */
    public function counts(): array;
}
