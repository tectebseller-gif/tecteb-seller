<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

/**
 * The three purchase-quantity meta rows on a shop product, read and written as
 * the DESTINATION of a Min Max handover.
 *
 * A port rather than a direct call because Application may not call WordPress
 * — the `alpha.9` rule, which the architecture suite enforces — and because
 * the one thing this migration must get exactly right is the difference
 * between «the row is not there» and «the row is there and empty». A port can
 * promise `null` for the first and `''` for the second; `get_post_meta()`
 * returns `''` for both (`alpha.28`), so the promise has to be made somewhere
 * and kept by the implementation.
 */
interface ShopQuantityPortInterface
{
    /**
     * What the destination holds now.
     *
     * `min`, `max` and `step` are `null` when THE ROW IS ABSENT and a string
     * — possibly empty — when it is present.
     *
     * `held` answers «are the present rows somebody else's», and it is
     * answered HERE rather than by the caller because the ownership record
     * stores FINGERPRINTS rather than values (`ProjectedQuantity`), and a
     * caller comparing a record entry against a meta value would find every
     * owned row unowned and hold back the whole catalogue. Application also
     * has no business hashing WooCommerce meta.
     *
     * `record` is the ownership record as an opaque blob, carried only so a
     * rollback can put the same blob back.
     *
     * @return array{min:?string, max:?string, step:?string, held:bool, record:?string, exists:bool}
     */
    public function read(int $postId): array;

    /**
     * Write the three rows and take ownership of what was written.
     *
     * Returns what the shop holds AFTER the write, read back rather than
     * assumed: WooCommerce and other plugins filter on the way in
     * (`alpha.26`), and an ownership record taken from what was SENT would
     * claim a value that is not there.
     *
     * @return array{min:string, max:string, step:string, stored:bool}
     */
    public function write(int $postId, string $min, string $max, string $step): array;

    /**
     * Put the destination back to the three values given, where `null` means
     * «delete the row, there was none».
     *
     * @return bool false when any part of it would not store
     */
    public function restore(int $postId, ?string $min, ?string $max, ?string $step, ?string $record): bool;

    /** Whether this post id is a product or variation the shop actually has. */
    public function exists(int $postId): bool;

    /**
     * True when the marketplace owns this product, so a rule written here is
     * one the marketplace's own guard will enforce.
     *
     * The honest half of the coverage question: `QuantityGuard::ruleFor()`
     * returns before reading anything when the product is not ours, so three
     * rows written onto a WooCommerce-native or Dokan product are rows NOTHING
     * READS. The migration records that per product rather than discovering it
     * in production.
     */
    public function marketplaceOwns(int $postId): bool;
}
