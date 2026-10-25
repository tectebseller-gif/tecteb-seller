<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Product\Domain\CategoryLink;

/**
 * Writes the category a product's OWN WooCommerce relationship already names
 * — on an explicit action, for the products named, and for nothing else.
 *
 * «سپس با یک اقدام صریح مدیر، اتصال‌های قطعی را روی محصولات واردشده اعمال
 * کن.» So this is the only writer on the path, it takes a list of product ids
 * rather than «everything that looked clean», and the single column it writes
 * is `category_key`. `CategoryLinkPlanHasOneWriterTest` asserts from these
 * files' bytes that the preview has no writer and that this one cannot reach
 * a status, an ownership flag, a price or a ledger line.
 *
 * **Four guards, and each one is a refusal with a name.**
 *
 * 1. **The link is read again, now.** The preview is a snapshot; a term can
 *    have been detached between the page rendering and the button being
 *    pressed. Writing from the snapshot is `alpha.49`'s mistake — a decision
 *    applied to a value nobody looked at.
 * 2. **Only `DEFINITE` is written.** Several real terms yield no key at all,
 *    because `definiteKey()` returns `''` for them: «اولین دسته را خودسرانه
 *    انتخاب نکن». The empty key is then refused by the repository as
 *    `bad_category`, so even a caller that ignored this guard could not
 *    narrow a product.
 * 3. **The key the page showed travels with the form.** If the product's one
 *    term is no longer the one the manager saw, the answer is
 *    `link_moved` with both values — never a silent write of the new one
 *    (`alpha.55`'s `storefront_moved`, one column over).
 * 4. **A stored category is never replaced.** `setCategoryWhenEmpty()` keeps
 *    `category_key = ''` inside its own `UPDATE` (`alpha.50`), so «دسته‌ای که
 *    مدیر قبلاً صریحاً تعیین کرده بدون تأیید بازنویسی نشود» holds even
 *    against a second request racing this one, and the answer is the named
 *    `already_set` rather than a success.
 *
 * ⛔ **And nothing in WooCommerce is touched.** No term is created, deleted
 * or renamed, no relationship is added or removed, and no product is
 * re-imported, re-owned, published or priced. Applying this twice writes
 * nothing the second time: the condition lives in the statement, so the
 * second pass answers `already_set`.
 */
final class ApplyCategoryLinks
{
    /** How many products one explicit action may cover. */
    public const BATCH_LIMIT = 200;

    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly ProductCategoryLinkReaderInterface $links,
        private readonly ?AuditLogger $audit = null
    ) {
    }

    /**
     * Apply the definite links for exactly these products.
     *
     * @param list<int> $productIds
     * @param array<int,string> $seen product id => the key the page printed.
     *        A product absent from this map is applied without the freshness
     *        check; a product present with a different key is refused. The
     *        caller decides, because a page that cannot show the value has no
     *        business claiming the manager saw it.
     * @return array{
     *     ok:bool, code:string, counts:array<string,int>,
     *     results:array<int,array{outcome:string, key:string, was:string}>
     * }
     */
    public function apply(array $productIds, array $seen = [], int $actorId = 0): array
    {
        $ids = [];
        foreach ($productIds as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return ['ok' => false, 'code' => 'nothing_selected', 'counts' => [], 'results' => []];
        }
        if (count($ids) > self::BATCH_LIMIT) {
            return ['ok' => false, 'code' => 'batch_too_large', 'counts' => [], 'results' => []];
        }
        if (!$this->links->isAvailable()) {
            // ⛔ Refused as a whole rather than per product. With no
            // directory, every id would read as `invalid` — and a screen that
            // reported 200 invalid products would be describing this plugin's
            // own blindness as a fact about the catalogue.
            return ['ok' => false, 'code' => 'categories_unreadable', 'counts' => [], 'results' => []];
        }

        $rows = $this->products->findMany($ids);
        $wcIds = [];
        foreach ($rows as $row) {
            if ($row->wcProductId > 0) {
                $wcIds[] = $row->wcProductId;
            }
        }
        // Read again, batched, at apply time — not from the preview.
        $links = $this->links->linksFor($wcIds);

        $results = [];
        foreach ($ids as $id) {
            $results[$id] = $this->applyOne($id, $rows[$id] ?? null, $links, $seen, $actorId);
        }

        $counts = [];
        foreach ($results as $result) {
            $counts[$result['outcome']] = ($counts[$result['outcome']] ?? 0) + 1;
        }
        ksort($counts);

        return [
            'ok' => true,
            'code' => 'apply_done',
            'counts' => $counts,
            'results' => $results,
        ];
    }

    /**
     * @param array<int,CategoryLink> $links
     * @param array<int,string> $seen
     * @return array{outcome:string, key:string, was:string}
     */
    private function applyOne(int $productId, mixed $row, array $links, array $seen, int $actorId): array
    {
        if ($row === null) {
            return ['outcome' => 'not_found', 'key' => '', 'was' => ''];
        }
        $wcId = (int) $row->wcProductId;
        if ($wcId === 0) {
            return ['outcome' => CategoryLinkPlan::NO_PRODUCT, 'key' => '', 'was' => ''];
        }
        $link = $links[$wcId] ?? CategoryLink::unreadable($wcId);
        $was = trim((string) $row->details->categoryKey);

        if (!$link->isDefinite()) {
            // Every non-definite state keeps its own name so the screen can
            // say which one it is. `MULTIPLE` in particular is the state the
            // owner forbade guessing at, and it leaves here untouched.
            return [
                'outcome' => match ($link->state) {
                    CategoryLink::MULTIPLE => $was === ''
                        ? CategoryLinkPlan::MULTIPLE
                        : CategoryLinkPlan::NARROWED,
                    CategoryLink::DEFAULT_ONLY => CategoryLinkPlan::DEFAULT_TERM,
                    CategoryLink::NONE => CategoryLinkPlan::NO_CATEGORY,
                    CategoryLink::INVALID => CategoryLinkPlan::INVALID,
                    default => CategoryLinkPlan::UNREADABLE,
                },
                'key' => '',
                'was' => $was,
            ];
        }

        $key = $link->definiteKey();
        if (array_key_exists($productId, $seen) && trim($seen[$productId]) !== $key) {
            return ['outcome' => 'link_moved', 'key' => $key, 'was' => trim($seen[$productId])];
        }

        $outcome = $this->products->setCategoryWhenEmpty($productId, $key);
        if ($outcome !== '') {
            // `already_set`, `not_imported`, `not_found`, `storage_failed` —
            // reported as they come back. None of them is retried with a
            // different statement, because each means a different thing.
            return ['outcome' => $outcome, 'key' => $key, 'was' => $was];
        }

        $this->audit?->log(
            AuditEventCatalog::PRODUCT_CATEGORY_LINKED,
            $actorId,
            'product',
            (string) $productId,
            [
                'wc_product_id' => $wcId,
                'category_key' => $key,
                // The ancestry, so the trail is readable a year from now: on
                // a catalogue where «چسب» is a leaf in six branches, a bare
                // term id records which row moved and not what was decided.
                'path' => $link->paths[(int) $key] ?? '',
                // Where the value came from. Not a decision anybody took —
                // which is exactly what makes this event different from
                // `DOKAN_CATEGORY_APPLIED`.
                'source' => 'woocommerce_relationship',
            ]
        );

        return ['outcome' => 'linked', 'key' => $key, 'was' => $was];
    }
}
