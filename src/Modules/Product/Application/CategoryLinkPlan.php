<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Modules\Product\Domain\CategoryLink;

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

    /** Ready to write: one real term, and no key stored yet. */
    public const APPLY = 'apply';

    /** Already right — the stored key IS the product's single real term. */
    public const SETTLED = 'settled';

    public const MULTIPLE = 'multiple_categories';
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

            $entry = [
                'product_id' => (int) $row['id'],
                'wc_product_id' => $wcId,
                'vendor_user_id' => (int) $row['vendor_user_id'],
                'title' => (string) $row['title'],
                'stored_key' => $stored,
                'term_ids' => $link === null ? [] : $link->validIds,
                'paths' => $link === null ? [] : $link->pathList(),
            ];

            $reason = $this->classify($link, $stored);
            if ($reason === self::APPLY) {
                $entry['proposed_key'] = $link->definiteKey();
                $entry['proposed_path'] = $link->paths[(int) $link->definiteKey()] ?? '';
                $apply[] = $entry;
            } elseif ($reason === self::SETTLED) {
                $settled++;
            } else {
                $held[$reason][] = $entry;
            }

            if ($link !== null && in_array($reason, [self::MULTIPLE, self::NARROWED], true)) {
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
    private function classify(?CategoryLink $link, string $stored): string
    {
        if ($link === null) {
            return self::NO_PRODUCT;
        }
        return match ($link->state) {
            CategoryLink::UNREADABLE => self::UNREADABLE,
            CategoryLink::DEFAULT_ONLY => self::DEFAULT_TERM,
            CategoryLink::NONE => self::NO_CATEGORY,
            CategoryLink::INVALID => self::INVALID,
            CategoryLink::MULTIPLE => $stored === '' ? self::MULTIPLE : self::NARROWED,
            CategoryLink::DEFINITE => match (true) {
                $stored === '' => self::APPLY,
                $stored === $link->definiteKey() => self::SETTLED,
                default => self::DECIDED,
            },
            default => self::UNREADABLE,
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
