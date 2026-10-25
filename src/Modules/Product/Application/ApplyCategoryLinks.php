<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Product\Domain\CategoryLink;
use Tecteb\Marketplace\Modules\Product\Domain\CategorySet;

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
 * 2. **The whole SET is written, and the single key only when there is
 *    exactly one.** Until `alpha.81` several real terms were refused
 *    outright, because the model held one key and storing one of them would
 *    have been a choice nobody made — and measured on that package,
 *    accepting such a key then DELETED the other categories from WooCommerce
 *    (`terms_lost=[245]`). From `alpha.82` the relationship itself is
 *    recordable, so it is recorded whole; `CategorySet::soleKey()` answers
 *    `''` for two or more, which keeps «اولین دسته را خودسرانه انتخاب نکن»
 *    true of the column while «مجموعهٔ کامل دسته‌ها باید حفظ شود» becomes
 *    true of the data.
 * 3. **The SET the page showed travels with the form.** If the product's
 *    categories are no longer the ones the manager saw, the answer is
 *    `link_moved` with both values — never a silent write of the new ones
 *    (`alpha.55`'s `storefront_moved`, one column over). Compared as a SET:
 *    order carries no meaning here, so a reordering is not a change.
 * 4. **A recorded set is never replaced.** `recordCategorySet()` keeps
 *    both conditions inside its own statements (`alpha.50`), so «دسته‌ای که
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
     * @param array<int,string> $seen product id => the SET the page printed,
     *        comma-joined as `CategorySet::toValue()` spells it. A product
     *        absent from this map is applied without the freshness check; a
     *        product present with a different set is refused. The caller
     *        decides, because a page that cannot show the value has no
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
     * @return array{outcome:string, key:string, was:string, ids:list<int>}
     */
    private function applyOne(int $productId, mixed $row, array $links, array $seen, int $actorId): array
    {
        if ($row === null) {
            return ['outcome' => 'not_found', 'key' => '', 'was' => '', 'ids' => []];
        }
        $wcId = (int) $row->wcProductId;
        if ($wcId === 0) {
            return ['outcome' => CategoryLinkPlan::NO_PRODUCT, 'key' => '', 'was' => '', 'ids' => []];
        }
        $link = $links[$wcId] ?? CategoryLink::unreadable($wcId);
        $was = trim((string) $row->details->categoryKey);

        // THE FOUR STATES WITH NOTHING TO WRITE, each keeping its own name so
        // the screen says which one it is. `MULTIPLE` is NOT among them any
        // more: several real terms are a relationship this round can record,
        // and refusing it is what left the owner's nine products with their
        // sets recorded nowhere.
        $refusal = match ($link->state) {
            CategoryLink::DEFAULT_ONLY => CategoryLinkPlan::DEFAULT_TERM,
            CategoryLink::NONE => CategoryLinkPlan::NO_CATEGORY,
            CategoryLink::INVALID => CategoryLinkPlan::INVALID,
            CategoryLink::UNREADABLE => CategoryLinkPlan::UNREADABLE,
            default => '',
        };
        if ($refusal !== '' || $link->validIds === []) {
            return [
                'outcome' => $refusal === '' ? CategoryLinkPlan::UNREADABLE : $refusal,
                'key' => '',
                'was' => $was,
                'ids' => [],
            ];
        }

        $ids = CategorySet::of($link->validIds);
        $key = CategorySet::soleKey($ids);
        if (array_key_exists($productId, $seen)) {
            $sawIds = CategorySet::fromValue((string) $seen[$productId]);
            if (!CategorySet::same($sawIds, $ids)) {
                return [
                    'outcome' => 'link_moved',
                    'key' => $key,
                    'was' => CategorySet::toValue($sawIds),
                    'ids' => $ids,
                ];
            }
        }

        $outcome = $this->products->recordCategorySet($productId, $ids);
        if ($outcome !== 'category_set_written') {
            // `already_set`, `not_imported`, `not_found`, `storage_failed`,
            // `category_set_unsupported` — reported as they come back. None is
            // retried with a different statement, because each means a
            // different thing.
            return ['outcome' => $outcome, 'key' => $key, 'was' => $was, 'ids' => $ids];
        }

        $this->audit?->log(
            AuditEventCatalog::PRODUCT_CATEGORY_LINKED,
            $actorId,
            'product',
            (string) $productId,
            [
                'wc_product_id' => $wcId,
                // The whole set that was recorded, and the single key only
                // when there was one to store.
                'category_ids' => CategorySet::toValue($ids),
                'category_key' => $key,
                // The ancestry of EVERY id, so the trail is readable a year
                // from now: on a catalogue where «چسب» is a leaf in six
                // branches, bare term ids record which row moved and not what
                // was decided.
                'path' => implode(' | ', array_map(
                    static fn (int $id): string => $link->paths[$id] ?? (string) $id,
                    $ids
                )),
                // Where the value came from. Not a decision anybody took —
                // which is exactly what makes this event different from
                // `DOKAN_CATEGORY_APPLIED`.
                'source' => 'woocommerce_relationship',
            ]
        );

        return ['outcome' => 'linked', 'key' => $key, 'was' => $was, 'ids' => $ids];
    }
}
