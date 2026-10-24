<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Modules\Migration\Domain\RecordSource;

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
     * @return list<array{wc_product_id:int, vendor_user_id:int, title:string, sku:string, price_minor:int, stock:int, post_status?:string, price_recorded?:bool, price_not_exact?:bool, source_category_key?:string, source_category_label?:string, source_image_id?:int}>
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
     * @return list<array{wc_product_id:int, vendor_user_id:int, title:string, sku:string, price_minor:int, stock:int, post_status?:string, price_recorded?:bool, price_not_exact?:bool, source_category_key?:string, source_category_label?:string, source_image_id?:int}>
     */
    /**
     * The three keys `alpha.66` added, and why `price_minor` alone was not
     * enough.
     *
     *  * `post_status` — WooCommerce's own status, carried so a plan can SAY
     *    which one it refused. Until this round the reader did not read
     *    `pending` at all, so the owner's thirteen pending products were
     *    absent from a plan that reported itself complete.
     *  * `price_recorded` — whether the shop holds a price AT ALL. «قیمت خالی
     *    با صفر متفاوت است», and `(int)` makes them one number.
     *  * `price_not_exact` — the price carries more precision than this shop's
     *    currency can hold. `alpha.65` rounded it (`450000.50` → `450001`),
     *    which is the one thing `ordersAfter()` in the same class has refused
     *    to do since `alpha.53`.
     *
     * Optional in the shape, deliberately: a reader that supplies no status is
     * a shape mismatch rather than a product in conflict, and
     * `judgeProduct()` tells those two apart with `array_key_exists`.
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
     * `amount_not_exact` is true when Dokan's stored figure has more
     * precision than the shop's currency can express — the row is handed back
     * with zeroes and that flag, never with a rounded number, because a
     * rounded share is a changed share.
     *
     * @return list<array{wc_order_id:int, vendor_user_id:int, status:string, total_minor:int, net_minor:int, commission_minor:int, refunded:bool, amount_not_exact:bool}>
     */
    public function orders(): array;

    /**
     * One page of order rows, strictly after `$afterId`, ascending.
     *
     * @return list<array{wc_order_id:int, vendor_user_id:int, status:string, total_minor:int, net_minor:int, commission_minor:int, refunded:bool, amount_not_exact:bool}>
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
     * What one shop's three record sources actually say — including «I could
     * not look», which every other method here cannot express.
     *
     * ⛔ **Why this is a separate method rather than a count.** `counts()`
     * returns ints, and `staffFor()`, `balanceRowsAfter()` and
     * `withdrawalsAfter()` all return `[]` for three different facts: Dokan
     * is not running, the table is not on this install, or the statement
     * failed (`alpha.8`). A caller counting those arrays reports **nought
     * records** in all three cases — a claim about the shop, made when the
     * only true claim was about us. `RecordSource` carries the difference,
     * and it carries no number at all unless a read succeeded, so an
     * unreadable source cannot be added into a total by accident.
     *
     * Scoped to one vendor because that is the question a manager asks — «این
     * فروشگاه چند ردیف دارد» — and because the balance and withdrawal tables
     * hold every shop at once, so a global figure answers nobody's question.
     *
     * @return array{staff:RecordSource, balance:RecordSource, withdrawals:RecordSource}
     */
    public function recordSourceFor(int $vendorUserId): array;

    /**
     * Every vendor id Dokan's own record sources NAME as an owner.
     *
     * ⛔ **Why a scope method exists at all.** The record import walks these
     * tables and nothing else: `vendorsAfter()` for staff, and
     * `balanceRowsAfter()`/`withdrawalsAfter()` for the other two kinds —
     * both of which hold every shop at once. So the set of shops it can
     * write a record for is this one, and it has never been the set the
     * preview asked about. Measured on staging: the preview listed the two
     * IMPORTED shops (40 and 102) and the run then wrote fourteen rows owned
     * by 2, 4, 6, 8, 16 and 102, whose owners appeared on the page only
     * AFTER the import. Both halves were right about their own question and
     * the page had two scopes.
     *
     * `readable` is the `alpha.8` half: `getResults()` answers `[]` both for
     * «no owners» and for «the statement failed», and an enumeration that
     * silently came back short would hide exactly the shops this method
     * exists to reveal. Each source is counted first and the ids are then
     * compared against that count, so a short read is reported rather than
     * believed.
     *
     * @return array{ids:list<int>, readable:bool, sources:array<string, array{ids:list<int>, readable:bool, present:bool}>}
     */
    public function recordOwnerIds(): array;

    /**
     * How many rows there are in total, per kind, so a caller can say «۴ از
     * ۳٬۲۰۰» instead of «در حال کار».
     *
     * @return array{vendors:int, products:int, orders:int, staff:int, balance:int, withdrawals:int}
     */
    public function counts(): array;
}
