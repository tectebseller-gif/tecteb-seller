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
     * The same answer as `read()`, taken from THE ROWS THEMSELVES.
     *
     * `read()` goes through `get_post_meta()`, and `get_metadata_raw()` always
     * answers from the object cache — read from core: it calls
     * `wp_cache_get($id, 'post_meta')` first and only fills the cache when it
     * is cold. So a value another request, or an earlier call in THIS request,
     * put in the cache is what a comparison gets, however carefully the rows
     * were locked a statement earlier. With a persistent object cache that
     * stale copy can even predate another process's committed write.
     *
     * That is why a compare-and-write asks this instead: one statement on the
     * same connection as the lock, inside the same transaction, so what is
     * compared is what is locked.
     *
     * Three things it must answer and `read()` cannot:
     *
     *  - **`read`** — whether the READ ITSELF worked. `getResults()` answers
     *    `[]` both for «no rows» and for «the statement failed» (`alpha.8`,
     *    `alpha.54`), and here the two are opposites: a failed read taken for
     *    «absent» would make a rollback DELETE three rows that are there. So
     *    the count is asked separately — `COUNT(*)` always returns a row, so
     *    `null` from it is a failure and `'0'` is an absence — and the caller
     *    refuses by name when this is false.
     *  - **`duplicates`** — how many extra rows each key has. `wp_postmeta`
     *    has NO unique index on `(post_id, meta_key)`: measured on WordPress
     *    7.1, its indexes are `PRIMARY(meta_id)`, `KEY post_id` and
     *    `KEY meta_key`. Two rows for one key are therefore possible, core
     *    reads the LOWEST `meta_id` (`update_meta_cache()` orders by
     *    `meta_id ASC` and `get_metadata_raw()` takes `[0]`), and this reads
     *    the same one so that the comparison is about the value WordPress
     *    would use. The extras are reported rather than silently collapsed,
     *    because a restore's `update_post_meta()` rewrites every duplicate
     *    and a delete removes all of them.
     *  - and it reads the ownership record from the row too, unserialised the
     *    way WordPress would, so the blob a rollback puts back is the blob the
     *    migration recorded.
     *
     * @return array{min:?string, max:?string, step:?string, held:bool, record:?string, exists:bool, read:bool, duplicates:array<string,int>}
     */
    public function readRows(int $postId): array;

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

    /**
     * Write — or remove — the manager's grant that this marketplace enforces
     * this product's purchase-quantity rule.
     *
     * One meta key, read back and compared, because `update_post_meta()`
     * answers the same thing for «failed» and «was already that»
     * (`alpha.44`). A grant that did not land would be a sentence on a screen
     * and nothing in the cart.
     *
     * It changes NOTHING else about the product: not its vendor, not its
     * ownership, not its approval cycle. `PurchasePolicy` still answers
     * `NOT_OURS` about it, which is what keeps the storefront stop, the
     * projector and the vendor's catalogue out of somebody else's product.
     *
     * `$rule` is the three values the caller read UNDER THE LOCK, keyed
     * `min`/`max`/`step` as `readRows()` returns them. The grant is stored as
     * a fingerprint of them, not as a flag, so it says «enforce 4/10/2» and
     * not «enforce whatever these rows hold next week» — `alpha.49`'s rule,
     * one object down. A rule that drifts afterwards is simply not enforced,
     * which is where such a product was before any grant existed.
     *
     * @param array{min:?string, max:?string, step:?string} $rule
     */
    public function grantEnforcement(int $postId, array $rule): bool;

    public function revokeEnforcement(int $postId): bool;

    /**
     * Whether the grant on this post is LIVE — present and still describing
     * the rule the rows now hold. Asked for reports, not for hooks; the cart
     * asks `QuantityGuard` the same question off the product object it was
     * handed.
     */
    public function enforcementGranted(int $postId): bool;

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
