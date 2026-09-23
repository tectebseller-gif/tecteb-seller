<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * A product's pictures as an identity that can be compared.
 *
 * The version this replaces compared images the same way it compared
 * categories: sort the ids, join them with commas. For a set of terms that is
 * right — a product is in category 19 and 23, and «19,23» and «23,19» are the
 * same fact. For pictures it is wrong twice over, and both are things a
 * manager does on purpose:
 *
 *   * **Swapping the main picture.** `main=10, gallery=[20,30]` and
 *     `main=20, gallery=[10,30]` sort to the identical string. So a manager
 *     who promoted a better photo to the top of the product page had that
 *     undone by the vendor's next save, and the comparison that was supposed
 *     to protect them reported no change at all.
 *   * **Reordering the gallery.** `[20,30]` and `[30,20]` likewise. The order
 *     IS the content here: it is the order a buyer scrolls through.
 *
 * So the main picture is named separately and the gallery keeps the order it
 * was given. Nothing is sorted. Duplicates are dropped because WooCommerce
 * cannot show the same attachment twice, and the main picture never appears
 * in the gallery because WooCommerce stores it in its own column.
 *
 * `main:0` is a real state, not a missing one: a product with pictures in the
 * gallery and no featured image is something WooCommerce allows and a manager
 * may have arranged deliberately.
 */
final class StorefrontImages
{
    private const PREFIX_MAIN = 'main:';
    private const PREFIX_GALLERY = '|gallery:';

    /** @param list<int> $gallery ordered, main excluded */
    private function __construct(
        public readonly int $main,
        public readonly array $gallery
    ) {
    }

    /**
     * @param list<int>|array<int|string,int|string> $galleryIds in display
     *        order; the main id is removed from it if present, because the
     *        two sides of a projection disagree about whether it belongs
     *        there — WooCommerce keeps them apart, the marketplace row keeps
     *        one ordered list — and a comparison that depended on which
     *        convention the caller used would report a change on every run.
     */
    public static function of(int $mainId, array $galleryIds): self
    {
        $main = max(0, $mainId);
        $gallery = [];
        foreach ($galleryIds as $raw) {
            $id = (int) $raw;
            if ($id <= 0 || $id === $main || in_array($id, $gallery, true)) {
                continue;
            }
            $gallery[] = $id;
        }
        return new self($main, $gallery);
    }

    /** @param list<int>|array<int|string,int|string> $galleryIds */
    public static function encode(int $mainId, array $galleryIds): string
    {
        return self::of($mainId, $galleryIds)->value();
    }

    /**
     * Reads back what `encode()` wrote — and also the bare comma list that
     * `alpha.26` stored.
     *
     * An old value decodes to «first id is the main», which is what that
     * version meant by it. It will not fingerprint the same as the new form,
     * so a field carrying one reads as «somebody else's» after the upgrade
     * and waits for a decision instead of being overwritten. That is the safe
     * direction, and `docs/upgrade-and-rollback.md` says so out loud.
     */
    public static function decode(string $value): self
    {
        $value = trim($value);
        if ($value === '') {
            return new self(0, []);
        }
        if (!str_starts_with($value, self::PREFIX_MAIN)) {
            $ids = self::parseIds($value);
            $main = array_shift($ids) ?? 0;
            return self::of($main, $ids);
        }
        $parts = explode(self::PREFIX_GALLERY, substr($value, strlen(self::PREFIX_MAIN)), 2);
        return self::of((int) $parts[0], self::parseIds($parts[1] ?? ''));
    }

    public function value(): string
    {
        return self::PREFIX_MAIN . $this->main . self::PREFIX_GALLERY . implode(',', $this->gallery);
    }

    /**
     * One ordered list, main first — the shape the marketplace row keeps.
     *
     * Main first because WooCommerce does not record where in the vendor's
     * list the featured picture sat, so there is nothing else to reconstruct
     * it from. Only reached when a manager has explicitly chosen to keep the
     * storefront's arrangement, and stable afterwards: encoding that list
     * again produces the same string.
     *
     * @return list<int>
     */
    public function ids(): array
    {
        return $this->main > 0 ? array_merge([$this->main], $this->gallery) : $this->gallery;
    }

    public function isEmpty(): bool
    {
        return $this->main === 0 && $this->gallery === [];
    }

    /** @return list<int> */
    private static function parseIds(string $csv): array
    {
        $out = [];
        foreach (explode(',', $csv) as $raw) {
            $id = (int) trim($raw);
            if ($id > 0) {
                $out[] = $id;
            }
        }
        return $out;
    }
}
