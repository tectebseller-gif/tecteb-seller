<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

/**
 * The WooCommerce catalogue THAT IS ALREADY THERE, read and never written.
 *
 * The Dokan import has had a reader like this since `alpha.9`
 * (`DokanReaderInterface`) and it answers a narrower question: «what does
 * Dokan say about its sellers' products». This one answers «what is in this
 * shop at all», which is what §1 asks for — the main shop's own products,
 * Dokan's, the ones this marketplace already owns, and the ones an earlier run
 * merely mapped.
 *
 * Three promises, and each is a constraint the instruction states:
 *
 *  - **it has no write method.** «ورود بدون ساخت محصول جدید ووکامرس» and
 *    «post_author و نقش کاربران را خودکار تغییر نده» are both satisfied by the
 *    capability being absent rather than guarded. An intake built on this
 *    reader cannot create a product, cannot move an author and cannot touch a
 *    role, because there is nothing here to call.
 *  - **it is paginated, by id, with no total scan.** A catalogue of tens of
 *    thousands of products must be readable a page at a time or the preview
 *    is a timeout. The counts come from `COUNT(*)` with `GROUP BY`, not from
 *    walking the rows.
 *  - **it returns facts, never verdicts.** Who owns a product is decided by
 *    `CatalogueInventory` from the signals this returns, because «the
 *    WordPress author alone is not proof of being a vendor» is a rule, and a
 *    rule belongs where it can be read and tested rather than inside a SQL
 *    statement.
 *
 * And what it deliberately does not return: no customer data, no order
 * contents, no user emails, no whole-table dump. «اطلاعات حساس یا خروجی کامل
 * دیتابیس نخواه» — so the per-product row carries ids, a title, a status and
 * the handful of fields the intake actually writes, and the plugin-dependency
 * answer is a tally of META KEY PREFIXES rather than meta values.
 */
interface CatalogueReaderInterface
{
    /** Whether WooCommerce is here at all. Without it there is no catalogue. */
    public function isAvailable(): bool;

    /**
     * How many products there are, by post type, status and product type.
     *
     * @return array{
     *     by_status: array<string,int>,
     *     by_type: array<string,int>,
     *     variations: int,
     *     unsupported: array<string,int>,
     *     total: int
     * } `by_type` is WooCommerce's own `product_type` taxonomy — `simple`,
     *   `variable`, and whatever else the site has — and `unsupported` is the
     *   subset this marketplace has no form for, named rather than hidden.
     */
    public function counts(): array;

    /**
     * One page of products, strictly after `$afterId`, ordered by id.
     *
     * Parents only: a variation is read with its parent, because a variation
     * is not a product a vendor lists. `variations` says how many each parent
     * has.
     *
     * @return array{rows: list<array<string,mixed>>, cursor: int, done: bool}
     */
    public function productsAfter(int $afterId, int $limit): array;

    /**
     * Everything the intake would copy for one product, read from
     * WooCommerce — which is the source of the current values on first intake.
     *
     * @return array<string,mixed> `[]` when the product is not there
     */
    public function detail(int $wcProductId): array;

    /**
     * The purchase-quantity rule the product's own meta rows express.
     *
     * Asked because a Min Max handover may already have written those three
     * rows onto this very product, and a marketplace row that disagreed with
     * them would make the first projection change what shoppers may buy. The
     * answer is the rule as `ProjectedQuantity` reads it, so «no rows» comes
     * back as the platform's own defaults rather than as a restriction.
     *
     * @return array{min:int, max:?int, step:int}
     */
    public function quantityRule(int $wcProductId): array;

    /**
     * The evidence about who, if anybody, sells this product.
     *
     * Facts only, and all of them, because the one thing this must not do is
     * pick a vendor: «نویسندهٔ وردپرس به‌تنهایی اثبات فروشنده‌بودن نیست» and
     * «محصول فروشگاه اصلی را به فروشندهٔ تصادفی یا مدیر واردشده نسبت نده».
     *
     * @return array{
     *     author_id: int,
     *     author_exists: bool,
     *     author_roles: list<string>,
     *     author_is_admin: bool,
     *     vendor_profile: string,
     *     vendor_profile_user: int,
     *     dokan_seller: bool,
     *     marketplace_vendor_meta: int,
     *     marketplace_rows: list<array{product_id:int, vendor_user_id:int, ownership:string, status:string}>
     * } `vendor_profile` is the marketplace application's status for the
     *   author — `''` when the author has none at all.
     */
    public function vendorSignals(int $wcProductId): array;

    /**
     * WooCommerce products that more than one marketplace row points at.
     *
     * A duplicate link is an ambiguity, not a failure to be repaired here:
     * which row is the real one is a decision about somebody's catalogue.
     *
     * @return list<array{wc_product_id:int, rows:int}>
     */
    public function duplicateLinks(int $limit = 50): array;

    /**
     * Which other plugins have written meta on this product, as PREFIXES.
     *
     * Prefixes and counts, never values: the question is «does this product
     * depend on another plugin», and the answer must not become an export of
     * whatever those plugins store.
     *
     * @return array<string,int> prefix => how many keys
     */
    public function pluginKeys(int $wcProductId): array;

    /**
     * The fields a projection would overwrite or cannot express for this
     * product, each named.
     *
     * This is the honest half of «ورود محصولات موجود»: the marketplace row
     * holds what the four-step form holds, and a WooCommerce product can be
     * in states that form has no field for. Writing them away silently is the
     * one thing the instruction forbids twice — «حذف یا تبدیل ضمنی نکن» —
     * so they are listed, the acceptance refuses until they are
     * acknowledged, and nothing is converted.
     *
     * @return array<string,string> field key => what would happen to it
     */
    public function preservationRisks(int $wcProductId): array;
}
