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
     * **Every count has the SAME SCOPE: posts of type `product`.** That is
     * what `alpha.55` got wrong and the owner caught on staging — 189
     * products across four statuses printed beside `simple=262 variable=32`,
     * because the type count was asked of the `product_type` taxonomy with no
     * join to `posts` at all. Two questions in one table, and the narrower
     * one had the smaller number.
     *
     * Where the types STILL do not add up to the statuses, the reason is
     * counted rather than left as a difference: `without_type` (a product
     * with no type term, which WooCommerce reads as simple) and
     * `with_two_types` (a product counted twice). `strays` is what the old
     * query was counting — `product_type` rows belonging to a variation,
     * another post type, or no post at all — reported because that is where
     * the difference came from.
     *
     * @return array{
     *     by_status: array<string,int>,
     *     by_type: array<string,int>,
     *     variations: int,
     *     unsupported: array<string,int>,
     *     total: int,
     *     without_type: int,
     *     with_two_types: int,
     *     strays: array{on_variations:int, on_other_post_types:int, no_post_at_all:int}
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
     * ONE page of the WHOLE catalogue — every WooCommerce product and every
     * marketplace row, each related product exactly once.
     *
     * This is what `productsAfter()` cannot answer. That one walks WooCommerce
     * posts by id, which is right for a read-only inventory and wrong for a
     * list a manager works in: it cannot show a marketplace draft that has no
     * WooCommerce product yet, it cannot be filtered or sorted, and it cannot
     * be paged to «صفحهٔ ۴ از ۱۰». Both exist, on purpose — the keyset walk is
     * what a batched intake resumes from, and this is what a screen reads.
     *
     * The filters are three INDEPENDENT facts, never merged: where the row
     * comes from, WooCommerce's publish status, and whether this plugin
     * manages the product («وضعیت انتشار با وضعیت ورود/مالکیت قاتی نشود»).
     * Their allowed values live in `CatalogueFacets`.
     *
     * `marketplace_status` and `needs_review` narrow the list the way the
     * chips above it have since `alpha.32`, and `needs_review` is the ONE
     * definition from `ReviewQueueSql::waiting()` rather than a second
     * `WHERE` written here: a list and a badge that define «waiting» twice
     * stop describing the same set the day one of them learns a new state.
     *
     * Each row also carries `submission`, the identity of the submission on
     * it, selected IN THE SAME STATEMENT as the row — because a page that
     * records what it showed as seen must read the token from the row above
     * it, not from a second query (`alpha.37`).
     *
     * @param array{
     *     search?:string, source?:string, wc_status?:string, management?:string,
     *     marketplace_status?:string, needs_review?:bool
     * } $filters
     * @param string $sort a `ProductSort` value; anything else is the default order
     * @return array{
     *     rows: list<array<string,mixed>>,
     *     total: int,
     *     facets: array{source:array<string,int>, wc_status:array<string,int>, management:array<string,int>}
     * }
     */
    public function unifiedPage(array $filters, int $limit, int $offset, string $sort = 'updated'): array;

    /**
     * The vendor evidence about a set of users, asked once per user.
     *
     * The bucket a row belongs to is decided from its AUTHOR, and a page of
     * products has at most as many authors as rows — usually two or three. Per
     * row it would be twenty user reads and twenty application lookups for one
     * screen; per author it is what the page actually costs.
     *
     * @param list<int> $authorIds
     * @return array<int,array{author_id:int, author_exists:bool, author_roles:list<string>, author_is_admin:bool, dokan_seller:bool, vendor_profile:string}>
     */
    public function authorSignals(array $authorIds): array;

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

    /**
     * Products that are PUBLISHED in WooCommerce and whose marketplace row
     * carries no price — reported, and nothing else.
     *
     * §1-هـ asks for exactly this and for exactly nothing more: «محصول
     * منتشرشدهٔ بی‌قیمت را جداگانه گزارش کن، بدون تغییر وضعیت عمومی یا
     * قابلیت خریدش». So this is a SELECT and there is no companion that
     * repairs, hides or unpublishes anything — the state is a question for a
     * person, and the two halves of it are genuinely different facts: the
     * shop may have a price the marketplace row does not (an intake of a
     * product priced after the row was made) or neither may have one.
     *
     * `shop_price` is the row the shop holds, told apart from emptiness, so
     * the screen can say which of the two cases each product is.
     *
     * @return list<array{wc_product_id:int, product_id:int, title:string, shop_price:string, ownership:string}>
     */
    public function publishedWithoutPrice(int $limit = 100): array;
}
