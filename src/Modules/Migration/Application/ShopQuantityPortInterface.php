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
     * «delete the row, there was none», and the ownership record with them.
     *
     * The record is the FOURTH value and is verified like the other three: a
     * restore whose record did not land leaves the destination owned by a map
     * describing values that are no longer there, and `alpha.53` reported that
     * as a success.
     *
     * @return string `''` when every part stored; otherwise the meta key that
     *                would not, so the manager is told WHICH half is undone
     *                rather than «it failed» (`alpha.35`: a result that cannot
     *                carry where it stopped is not resumable)
     */
    public function restore(int $postId, ?string $min, ?string $max, ?string $step, ?string $record): string;

    /**
     * Take the row locks a compare-and-restore needs, inside the caller's
     * transaction, and answer whether they were taken.
     *
     * A lock the other side does not take is not a guard (`alpha.50`), and the
     * other side here is `update_post_meta()` on the same `$wpdb` connection —
     * which does take row locks, because it is ordinary SQL on `postmeta`. So
     * this one works, and a caller without a transaction must refuse rather
     * than carry on unprotected.
     */
    public function lockQuantityRows(int $postId): bool;

    /**
     * Drop WordPress's cached copy of this post's meta.
     *
     * The writes take part in the database transaction; the object cache does
     * not. A rolled-back restore therefore leaves the cache holding values
     * that were never committed, and the rest of that request reads them.
     */
    public function forgetCachedQuantities(int $postId): void;

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
