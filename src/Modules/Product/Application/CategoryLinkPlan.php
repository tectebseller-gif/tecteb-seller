<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Modules\Product\Domain\CategoryLink;
use Tecteb\Marketplace\Modules\Product\Domain\CategorySet;

/**
 * What the existing `product_cat` relationships ARE, and what writing them
 * down would do — read only.
 *
 * The owner's instruction asks for this before anything is applied: «ابتدا
 * پیش‌نمایش گروهی بساز: تعداد محصولات، دسته‌های فعلی با شناسه و مسیر کامل،
 * اتصال پیشنهادی و موارد مبهم.» So this class has **no write method of any
 * kind** — `ApplyCategoryLinks` is the one writer, and
 * `CategoryLinkPlanHasOneWriterTest` asserts both halves from these files'
 * bytes. «First look at what would change» is then a property of the class
 * rather than a rule a page has to keep.
 *
 * **Every row gets a name, and there is no silent bucket.** A product is
 * either in `apply` or in exactly one `held` reason, and the reasons are
 * separate because the way out of each one differs:
 *
 *   - `multiple_categories` — several real terms. `tmc_products.category_key`
 *     is one column, so no key can be written without choosing for somebody;
 *     and measured on `alpha.80`, the first accepted write then DELETES the
 *     others from WooCommerce. The group carries EVERY id with its full path,
 *     which is also the short list a human picks from — two or three options
 *     rather than the 1,070-term picker, per «انتخاب دستی فقط برای استثناهای
 *     واقعی باقی بماند».
 *   - `narrowed_by_intake` — the same shape, except a key is ALREADY stored
 *     and it is one of the product's terms. This is the dangerous group and
 *     the reason the preview covers rows that are not «waiting»: that key
 *     came from `ImportExistingProducts`' `category_ids[0]`, not from a
 *     person, and it is the value an accepted proposal would write back.
 *   - `decided_elsewhere` — a key is stored and it is NOT one of the
 *     product's terms. Somebody chose it; «دسته‌ای که مدیر قبلاً صریحاً
 *     تعیین کرده بدون تأیید بازنویسی نشود», so it is reported and never
 *     replaced.
 *   - `default_term` — only WooCommerce's own `default_product_cat`. That is
 *     «بدون دسته» wearing a category's clothes, and `alpha.80` stored it as a
 *     real key (`row_none_category_key=15`).
 *   - `no_category`, `invalid_term`, `no_storefront_product`, `unreadable` —
 *     each a different fact, and none of them a reason to write. ⛔ In
 *     particular `unreadable` is not `no_category`: `getResults()` answers
 *     «no rows» and «the read failed» identically (`alpha.8`), and treating
 *     the second as the first is how a preview offers a decision about a
 *     product nobody could read.
 *
 * `settled` is counted and NOT held: the stored key already is the product's
 * one real term, so there is nothing to decide and nothing to do.
 */
final class CategoryLinkPlan
{
    /** How many imported rows one page covers. */
    public const ROW_LIMIT = 500;

    /**
     * Ready to write: at least one real term, and nothing recorded yet.
     *
     * ⛔ **It used to mean «ONE real term».** A product in two categories went
     * to `MULTIPLE` and was held, which was right while the model could only
     * hold one key — and from `alpha.82` the whole relationship is recordable,
     * so holding it would be asking a manager to decide something WooCommerce
     * has already recorded: «مدیر نباید مجبور به انتخاب مجدد دسته‌های موجود
     * شود». The single key is still only written when there is exactly one
     * ({@see CategorySet::soleKey()}); the set is written whatever its size.
     */
    public const APPLY = 'apply';

    /** Already right — what is recorded IS the product's own relationship. */
    public const SETTLED = 'settled';

    /**
     * Kept as a NAME, and no longer produced.
     *
     * `alpha.81` put every multi-category product here and the screen said
     * «نیازمند تصمیم انسان» — the sentence the owner rejected. The rows are in
     * `apply` now, and the constant stays so an older audit row or a stored
     * count still reads.
     */
    public const MULTIPLE = 'multiple_categories';

    /**
     * Also kept as a NAME only. It meant «several categories and the intake
     * already stored one of them» — a state this round records in full
     * instead. The entry carries `stored_key_unchosen` so the page can still
     * say that the single key in the row was nobody's decision.
     */
    public const NARROWED = 'narrowed_by_intake';
    public const DECIDED = 'decided_elsewhere';
    public const DEFAULT_TERM = 'default_term';
    public const NO_CATEGORY = 'no_category';
    public const INVALID = 'invalid_term';
    public const NO_PRODUCT = 'no_storefront_product';
    public const UNREADABLE = 'unreadable';

    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly ProductCategoryLinkReaderInterface $links
    ) {
    }

    /** Whether the relationships can be resolved on this site at all. */
    public function available(): bool
    {
        return $this->links->isAvailable();
    }

    /**
     * One page of imported rows, classified and grouped.
     *
     * @return array{
     *     available:bool, scanned:int, total:int, finished:bool, cursor:int,
     *     unrecorded:int,
     *     apply:list<array<string,mixed>>,
     *     held:array<string,list<array<string,mixed>>>,
     *     groups:list<array<string,mixed>>,
     *     counts:array<string,int>
     * }
     */
    public function plan(int $limit = self::ROW_LIMIT, int $afterId = 0): array
    {
        $limit = max(1, min(1000, $limit));
        $rows = $this->products->importedWithCategoryKeys($limit, $afterId);
        $total = $this->products->countImported();
        $available = $this->available();

        $wcIds = [];
        foreach ($rows as $row) {
            if ((int) $row['wc_product_id'] > 0) {
                $wcIds[] = (int) $row['wc_product_id'];
            }
        }
        // One batched read for the page, never one per product.
        $links = $available ? $this->links->linksFor($wcIds) : [];
        // And one more for what is already recorded. `null` means the question
        // could not be asked (schema 34, or a failed read) — and then every
        // row falls back to its single key, which is exactly `alpha.81`.
        $productIds = [];
        foreach ($rows as $row) {
            $productIds[] = (int) $row['id'];
        }
        $recorded = $this->products->categorySetsFor($productIds);
        $setsReadable = $recorded !== null;

        $apply = [];
        $held = [];
        $settled = 0;
        $groups = [];
        $cursor = $afterId;

        foreach ($rows as $row) {
            $cursor = max($cursor, (int) $row['id']);
            $wcId = (int) $row['wc_product_id'];
            $stored = trim((string) $row['category_key']);

            $link = match (true) {
                !$available => CategoryLink::unreadable($wcId),
                $wcId === 0 => null,
                default => $links[$wcId] ?? CategoryLink::unreadable($wcId),
            };

            $set = $setsReadable
                ? ($recorded[(int) $row['id']] ?? [])
                : CategorySet::of([$stored]);

            $entry = [
                'product_id' => (int) $row['id'],
                'wc_product_id' => $wcId,
                'vendor_user_id' => (int) $row['vendor_user_id'],
                'title' => (string) $row['title'],
                'stored_key' => $stored,
                'recorded_ids' => $set,
                'term_ids' => $link === null ? [] : $link->validIds,
                'paths' => $link === null ? [] : $link->pathList(),
                // The single key in the row that NOBODY chose: the intake of
                // `alpha.81` and earlier stored `category_ids[0]`. Named here
                // so the page can say so instead of presenting it as a
                // decision — and it is not a reason to refuse the row.
                'stored_key_unchosen' => $stored !== ''
                    && $link !== null
                    && count($link->validIds) > 1,
            ];

            $reason = $this->classify($link, $set, $setsReadable);
            if ($reason === self::APPLY) {
                $entry['proposed_ids'] = $link->validIds;
                $entry['proposed_paths'] = $link->pathList();
                // Whether this row is being recorded for the first time or
                // COMPLETED, and which ids would be added. Named so the page
                // can say «two of these three are already recorded» instead
                // of presenting a completion as a fresh write — and so the
                // number «how many relationships would change» is readable
                // rather than inferred from a count (`alpha.82`).
                $entry['completes'] = $set !== [];
                $entry['adds_ids'] = CategorySet::gained($set, $link->validIds);
                // The one key this would store, and `''` whenever there is
                // more than one real term — «اولین دسته را خودسرانه انتخاب
                // نکن». The path is only meaningful for the single case.
                $entry['proposed_key'] = CategorySet::soleKey($link->validIds);
                $entry['proposed_path'] = $entry['proposed_key'] === ''
                    ? ''
                    : ($link->paths[(int) $entry['proposed_key']] ?? '');
                $apply[] = $entry;
            } elseif ($reason === self::SETTLED) {
                $settled++;
            } else {
                $held[$reason][] = $entry;
            }

            // GROUPED while there is more than one category to look at,
            // whichever bucket the row is in: that grouping is how «۱۸۴
            // محصول» becomes a few readable rows, and it is now the `apply`
            // rows that carry the sets.
            if ($link !== null && count($link->validIds) > 1) {
                $this->group($groups, $link, $entry);
            }
        }

        $counts = [self::APPLY => count($apply), self::SETTLED => $settled];
        foreach ($held as $key => $list) {
            $counts[$key] = count($list);
        }
        ksort($held);
        usort($groups, static fn (array $a, array $b): int => $b['products'] <=> $a['products']);

        return [
            'available' => $available,
            'scanned' => count($rows),
            'total' => $total,
            // ⛔ «This page ran out of rows before its limit», which is a fact
            // about the read. NOT «I have seen everything»: with keyset paging
            // that would be a claim about pages nobody fetched, and a count
            // that could not be read (`-1`) must never make it true
            // (`alpha.70`).
            'finished' => $total >= 0 && count($rows) < $limit,
            'cursor' => $cursor,
            // ⛔ THE WHOLE CATALOGUE, not this page: how many imported rows
            // have no recorded relationship at all. `-1` means the question
            // could not be asked, and the screen must then print no number
            // (`alpha.67`). Carried here so the page has ONE service to ask
            // and the figure beside the per-page counts cannot come from a
            // second, differently-scoped query.
            'unrecorded' => $this->products->countImportedWithoutCategorySet(),
            'apply' => $apply,
            'held' => $held,
            'groups' => $groups,
            'counts' => $counts,
        ];
    }

    /**
     * One rule, one name — and the only `apply` is one real term with no key.
     *
     * Written as a single `match` on purpose: the classification is the whole
     * of the decision, and a second place that decided it differently is how
     * a preview and the action it feeds come to disagree (`alpha.79`).
     */
    /**
     * @param list<int> $recorded what this product's set already says
     * @param bool $setsReadable false when the link table could not be asked,
     *        in which case `$recorded` is the single key and the answers are
     *        exactly `alpha.81`'s
     */
    private function classify(?CategoryLink $link, array $recorded, bool $setsReadable): string
    {
        if ($link === null) {
            return self::NO_PRODUCT;
        }
        // The four states with nothing to write keep their own names, so the
        // screen says WHICH one it is. None of them is a candidate: a default
        // term is not a category, an unreadable link is not an empty one
        // (`alpha.8`), and an id the directory cannot resolve is not an id.
        $empty = match ($link->state) {
            CategoryLink::UNREADABLE => self::UNREADABLE,
            CategoryLink::DEFAULT_ONLY => self::DEFAULT_TERM,
            CategoryLink::NONE => self::NO_CATEGORY,
            CategoryLink::INVALID => self::INVALID,
            default => '',
        };
        if ($empty !== '') {
            return $empty;
        }
        if ($link->validIds === []) {
            return self::UNREADABLE;
        }
        if (!$setsReadable) {
            // Schema 34, or a failed read. Only a single term can be written
            // at all here, so the old answers hold — and a multi-category
            // product is HELD rather than narrowed.
            return match (true) {
                !$link->isDefinite() => $recorded === [] ? self::MULTIPLE : self::NARROWED,
                $recorded === [] => self::APPLY,
                $recorded === [(int) $link->definiteKey()] => self::SETTLED,
                default => self::DECIDED,
            };
        }
        return match (true) {
            $recorded === [] => self::APPLY,
            CategorySet::same($recorded, $link->validIds) => self::SETTLED,
            // ⛔ RECORDED, AND A STRICT SUBSET OF WHAT THE PRODUCT CARRIES.
            // Nothing was decided here — something is missing — and this is
            // the state migration 35 creates on staging: the backfill writes
            // ONE row from `category_key`, which the pre-`alpha.82` intake
            // filled with `category_ids[0]`. Refusing it as «already decided»
            // would leave the owner's nine products unrecordable, and
            // «مدیر نباید مجبور به انتخاب مجدد دسته‌های موجود شود». Completing
            // it only ADDS: `lost()` being empty is exactly the statement
            // that no recorded relationship would be removed.
            CategorySet::lost($recorded, $link->validIds) === [] => self::APPLY,
            // Recorded, and carrying an id this product does NOT have.
            // Somebody filed it there — a manager, or a later edit — and it is
            // not overwritten without being asked (`alpha.50`).
            default => self::DECIDED,
        };
    }

    /**
     * Group the ambiguous rows by the SET of categories they share.
     *
     * Grouped so the manager answers once for «these eleven products are in
     * both چسب branches» instead of eleven times — and by the set rather than
     * by one leading id, because two products in the same two categories are
     * the same question however the rows happen to be ordered.
     *
     * @param list<array<string,mixed>> $groups
     * @param array<string,mixed> $entry
     */
    private function group(array &$groups, CategoryLink $link, array $entry): void
    {
        $signature = $link->signature();
        foreach ($groups as $i => $group) {
            if ($group['signature'] === $signature) {
                $groups[$i]['products']++;
                if (count($groups[$i]['sample']) < 5) {
                    $groups[$i]['sample'][] = $entry['product_id'];
                }
                return;
            }
        }
        $groups[] = [
            'signature' => $signature,
            'state' => $link->state,
            'term_ids' => $link->validIds,
            'paths' => $link->pathList(),
            'invalid_ids' => $link->invalidIds,
            'default_attached' => $link->defaultAttached,
            'products' => 1,
            'sample' => [$entry['product_id']],
        ];
    }
}
