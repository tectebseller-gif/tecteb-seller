<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * The `product_cat` terms one WooCommerce product actually carries.
 *
 * The owner's instruction for this round makes the EXISTING relationship the
 * reference — «دسته‌های موجود ووکامرس و ارتباط فعلی محصولات با آن‌ها مرجع
 * هستند» — and forbids both re-classification and asking a person to pick
 * 1,070 categories again. So the question «which category is this product
 * in?» is answered by reading the relationship, by term id, and never by
 * matching names or guessing.
 *
 * Four states, not two, and the difference decides what may be written:
 *
 *   - **one real term** — a definite link. Writing it loses nothing, because
 *     the projection writes that same single id back.
 *   - **several real terms** — NOT a link. `tmc_products.category_key` is one
 *     column, so storing any one of them is a choice nobody made; and worse,
 *     measured on `alpha.80`'s bytes, the first accepted write then DELETES
 *     the others from WooCommerce (`set_category_ids()` replaces the set):
 *     `terms_lost=[214,215]`, `other_categories_silently_dropped=yes`.
 *     Details and numbers: `docs/category-model-limit-alpha81-fa.md`.
 *   - **only the shop's default term** — «بدون دسته» wearing a category's
 *     clothes. WooCommerce substitutes `default_product_cat` whenever a
 *     product is saved with no categories, and `alpha.80`'s intake stored it
 *     as though it were a real decision (`row_none_category_key=15`).
 *   - **nothing readable** — the query failed. That is not «no categories»
 *     (`alpha.8`), and it must never produce a write.
 *
 * This object only reports. It has no method that changes a term, a
 * relationship, or anything else — and `CategoryLinksAreReadOnlyTest` asserts
 * that from the file's own bytes rather than from this sentence.
 */
final class CategoryLink
{
    /** Exactly one real term: the only state from which a key may be written. */
    public const DEFINITE = 'definite';

    /** More than one real term. Held by name; nothing is written. */
    public const MULTIPLE = 'multiple';

    /** Only WooCommerce's own default term — a placeholder, not a category. */
    public const DEFAULT_ONLY = 'default_only';

    /** The product carries no `product_cat` term at all. */
    public const NONE = 'none';

    /** Terms are attached, but none of them resolves in the directory today. */
    public const INVALID = 'invalid';

    /** The relationship could not be read. Never a reason to write. */
    public const UNREADABLE = 'unreadable';

    /**
     * @param list<int> $termIds Every attached term, in the order read.
     * @param list<int> $validIds Those that resolve in the directory today.
     * @param list<int> $invalidIds Attached but unresolvable — deleted, or in
     *                              another taxonomy's numbering.
     * @param array<int,string> $paths Full ancestry per valid id, so a screen
     *                                 never has to show a bare leaf name on a
     *                                 catalogue where «لوازم جانبی» appears in
     *                                 six branches.
     */
    private function __construct(
        public readonly int $wcProductId,
        public readonly string $state,
        public readonly array $termIds,
        public readonly array $validIds,
        public readonly array $invalidIds,
        public readonly array $paths,
        public readonly bool $defaultAttached
    ) {
    }

    /**
     * Classify one product's attached terms.
     *
     * `$defaultTermId` is `default_product_cat`. It is excluded from the real
     * terms rather than filtered out of the attached list, because «this
     * product is only in Uncategorized» and «this product is in Uncategorized
     * and two real categories» are different facts and the second one is
     * still multiple.
     *
     * @param list<int> $termIds
     * @param array<int,string> $pathsById Ancestry for every id that resolves.
     */
    public static function of(
        int $wcProductId,
        array $termIds,
        array $pathsById,
        int $defaultTermId = 0
    ): self {
        $attached = [];
        foreach ($termIds as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $attached, true)) {
                $attached[] = $id;
            }
        }

        $valid = [];
        $invalid = [];
        $paths = [];
        $defaultAttached = false;
        foreach ($attached as $id) {
            if ($defaultTermId > 0 && $id === $defaultTermId) {
                $defaultAttached = true;
                // Still recorded in `termIds`, still not a category.
                continue;
            }
            if (array_key_exists($id, $pathsById)) {
                $valid[] = $id;
                $paths[$id] = (string) $pathsById[$id];
                continue;
            }
            $invalid[] = $id;
        }

        $state = match (true) {
            count($valid) === 1 => self::DEFINITE,
            count($valid) > 1 => self::MULTIPLE,
            $invalid !== [] => self::INVALID,
            $defaultAttached => self::DEFAULT_ONLY,
            default => self::NONE,
        };

        return new self($wcProductId, $state, $attached, $valid, $invalid, $paths, $defaultAttached);
    }

    /** The product's relationship could not be read on this request. */
    public static function unreadable(int $wcProductId): self
    {
        return new self($wcProductId, self::UNREADABLE, [], [], [], [], false);
    }

    /** True only for exactly one real term — the one state a key may come from. */
    public function isDefinite(): bool
    {
        return $this->state === self::DEFINITE;
    }

    /**
     * The key a definite link would store, or `''` when there is no such key.
     *
     * Deliberately empty for every other state, including `MULTIPLE`: this is
     * the method a writer calls, and «the first of several» is exactly the
     * answer the owner refused.
     */
    public function definiteKey(): string
    {
        return $this->isDefinite() ? (string) $this->validIds[0] : '';
    }

    /** A stable identity for «the same set of categories», for grouping. */
    public function signature(): string
    {
        if ($this->state === self::UNREADABLE) {
            return self::UNREADABLE;
        }
        $ids = $this->validIds;
        sort($ids);
        $extra = $this->invalidIds;
        sort($extra);
        return implode(',', $ids)
            . ($extra === [] ? '' : '|invalid:' . implode(',', $extra))
            . ($this->defaultAttached ? '|default' : '');
    }

    /** Full ancestry of each real term, for a screen that must not show bare leaves. */
    public function pathList(): array
    {
        $out = [];
        foreach ($this->validIds as $id) {
            $out[] = ['id' => $id, 'path' => $this->paths[$id] ?? ''];
        }
        return $out;
    }
}
