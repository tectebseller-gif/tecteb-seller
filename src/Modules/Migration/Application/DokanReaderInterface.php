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
     * One page of sellers, strictly after `$afterUserId`, ascending.
     *
     * Paged for the same reason products and orders are: the resumable import
     * asks for one page per batch, and reading every seller on a shop with
     * thousands of them — on every batch — is how a job that exists to avoid a
     * timeout causes one.
     *
     * @return list<array{user_id:int, store_name:string, email:string, enabled:bool}>
     */
    public function vendorsAfter(int $afterUserId, int $limit = self::PAGE): array;

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
     * Every figure is DOKAN's own, copied rather than computed. `net_minor` is
     * what Dokan recorded as the seller's share and `commission_minor` is the
     * difference it recorded — no rate from this marketplace is ever applied to
     * a historical order.
     *
     * @return list<array{wc_order_id:int, vendor_user_id:int, status:string, total_minor:int, net_minor:int, commission_minor:int, refunded:bool}>
     */
    public function orders(): array;

    /**
     * One page of order rows, strictly after `$afterId`, ascending.
     *
     * @return list<array{wc_order_id:int, vendor_user_id:int, status:string, total_minor:int, net_minor:int, commission_minor:int, refunded:bool}>
     */
    public function ordersAfter(int $afterId, int $limit = self::PAGE): array;

    /**
     * A shop's staff, as Dokan records them.
     *
     * Dokan Lite has no vendor staff at all — the feature is Pro's — so this
     * answers `[]` on a Lite install. That is «none found here», not «this
     * shop has none», and the report says which. Guessing the difference is
     * how a migration quietly drops four people's accounts.
     *
     * @return list<array{staff_user_id:int, vendor_user_id:int, display_name:string, user_email:string, dokan_role:string}>
     */
    public function staffFor(int $vendorUserId): array;

    /**
     * One page of a shop's balance ledger, strictly after `$afterId`.
     *
     * The amounts come back as STRINGS in Dokan's own `DECIMAL(19,4)` form.
     * Not floats, and not «minor units» — a float would round somebody's
     * balance and a conversion would be the first step towards recomputing it.
     * The rule is that this marketplace never restates what Dokan recorded.
     *
     * @return list<array{trn_id:int, vendor_user_id:int, trn_type:string, particulars:string, debit:string, credit:string, status:string, trn_date:string}>
     */
    public function balanceRowsAfter(int $afterId, int $limit = self::PAGE): array;

    /**
     * One page of a shop's withdrawal requests, strictly after `$afterId`.
     *
     * @return list<array{withdraw_id:int, vendor_user_id:int, amount:string, status:string, method:string, note:string, requested_at:string}>
     */
    public function withdrawalsAfter(int $afterId, int $limit = self::PAGE): array;

    /**
     * How many rows there are in total, per kind, so a caller can say «۴ از
     * ۳٬۲۰۰» instead of «در حال کار».
     *
     * @return array{vendors:int, products:int, orders:int, staff:int, balance:int, withdrawals:int}
     */
    public function counts(): array;
}
