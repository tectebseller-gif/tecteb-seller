<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Migration\Application\ShopQuantityPortInterface;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\ProjectedQuantity;

/**
 * The three purchase-quantity meta rows, read and written through WordPress.
 *
 * **«Absent» and «empty» are kept apart, and that is the whole job.**
 * `get_post_meta($id, $key, true)` answers `''` for both (`alpha.28`), so this
 * asks `metadata_exists()` first and only then reads — which is what lets a
 * rollback delete a row that was never there instead of restoring an empty
 * string that every later reader would take for «minimum one».
 *
 * **Ownership is judged here**, because `ProjectedQuantity`'s record stores a
 * sha256 of each value rather than the value, and because Application may not
 * hash WooCommerce meta (`alpha.9`). The judgement is `ProjectedQuantity`'s
 * own `merge()` rule applied to the three keys: a present row whose
 * fingerprint the record accounts for is ours, and anything else is somebody
 * else's.
 */
final class WpShopQuantityPort implements ShopQuantityPortInterface
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public function read(int $postId): array
    {
        $out = ['min' => null, 'max' => null, 'step' => null, 'held' => false, 'record' => null, 'exists' => false];
        if ($postId <= 0 || !function_exists('get_post_meta')) {
            return $out;
        }
        $out['exists'] = $this->exists($postId);
        $present = [];
        foreach ([
            'min' => ProjectedQuantity::META_MIN,
            'max' => ProjectedQuantity::META_MAX,
            'step' => ProjectedQuantity::META_STEP,
        ] as $part => $key) {
            if (!function_exists('metadata_exists') || !metadata_exists('post', $postId, $key)) {
                continue;               // absent stays null
            }
            $value = (string) get_post_meta($postId, $key, true);
            $out[$part] = $value;
            if (trim($value) !== '') {
                $present[$key] = $value;
            }
        }
        $raw = get_post_meta($postId, ProjectedQuantity::OWNED_META, true);
        $record = ProjectedQuantity::normalise($raw);
        $out['record'] = $raw === '' || $raw === false || $raw === null
            ? null
            : (string) json_encode($raw);
        $out['held'] = $this->heldElsewhere($present, $record);
        return $out;
    }

    /**
     * `read()`'s answer, taken from the rows instead of the object cache.
     *
     * One statement on the same connection as `lockQuantityRows()`, so the
     * value compared is the value locked. See the interface for why the cache
     * cannot be used here, why `read` is a separate field, and why duplicates
     * are counted rather than collapsed.
     */
    public function readRows(int $postId): array
    {
        $out = [
            'min' => null, 'max' => null, 'step' => null, 'held' => false,
            'record' => null, 'exists' => false, 'read' => false, 'duplicates' => [],
        ];
        if ($postId <= 0) {
            return $out;
        }
        $parts = [
            ProjectedQuantity::META_MIN => 'min',
            ProjectedQuantity::META_MAX => 'max',
            ProjectedQuantity::META_STEP => 'step',
        ];
        $keys = array_merge(array_keys($parts), [ProjectedQuantity::OWNED_META]);
        $placeholders = implode(', ', array_fill(0, count($keys), '%s'));
        $table = '`' . $this->db->prefix() . 'postmeta`';
        $params = array_merge([$postId], $keys);

        // The count first, and on its own: `COUNT(*)` always returns a row, so
        // `null` here is «the statement failed» and `'0'` is «there are no
        // rows». `getResults()` cannot tell those apart, and this is the one
        // place where mistaking one for the other deletes data.
        $counted = $this->db->getVar(
            'SELECT COUNT(*) FROM ' . $table . '
              WHERE post_id = %d AND meta_key IN (' . $placeholders . ')',
            $params
        );
        if ($counted === null) {
            return $out;
        }
        $rows = $this->db->getResults(
            'SELECT meta_id, meta_key, meta_value FROM ' . $table . '
              WHERE post_id = %d AND meta_key IN (' . $placeholders . ')
              ORDER BY meta_id ASC',
            $params
        );
        if (count($rows) !== (int) $counted) {
            // Inside the transaction both statements read one snapshot, so a
            // disagreement is the second read failing — not a race.
            return $out;
        }
        $out['read'] = true;
        $out['exists'] = $this->exists($postId);

        $first = [];
        foreach ($rows as $row) {
            $key = (string) ($row['meta_key'] ?? '');
            if ($key === '') {
                continue;
            }
            if (array_key_exists($key, $first)) {
                // A second row for the same key. WordPress reads the lowest
                // `meta_id`, which is the one already kept, so this is
                // counted and not used.
                $out['duplicates'][$key] = ($out['duplicates'][$key] ?? 0) + 1;
                continue;
            }
            $first[$key] = (string) ($row['meta_value'] ?? '');
        }

        $present = [];
        foreach ($parts as $key => $part) {
            if (!array_key_exists($key, $first)) {
                continue;               // absent stays null
            }
            $out[$part] = $first[$key];
            if (trim($first[$key]) !== '') {
                $present[$key] = $first[$key];
            }
        }
        if (array_key_exists(ProjectedQuantity::OWNED_META, $first)) {
            // Unserialised the way WordPress would, so the blob matches the
            // one `read()` recorded at migration time byte for byte.
            $raw = function_exists('maybe_unserialize')
                ? maybe_unserialize($first[ProjectedQuantity::OWNED_META])
                : $first[ProjectedQuantity::OWNED_META];
            $out['record'] = $raw === '' || $raw === null
                ? null
                : (string) json_encode($raw);
            $out['held'] = $this->heldElsewhere($present, ProjectedQuantity::normalise($raw));
        } else {
            $out['held'] = $this->heldElsewhere($present, []);
        }
        return $out;
    }

    /**
     * @param array<string,string> $present key => value, absent keys left out
     * @param array<string,string> $record  key => fingerprint
     */
    private function heldElsewhere(array $present, array $record): bool
    {
        if ($present === []) {
            return false;               // nobody has claimed anything
        }
        foreach ($present as $key => $value) {
            if (!array_key_exists($key, $record)) {
                return true;            // a rule with no record is not ours
            }
            if (!hash_equals($record[$key], ProjectedQuantity::fingerprint($value))) {
                return true;            // recorded, and changed since
            }
        }
        return false;
    }

    public function write(int $postId, string $min, string $max, string $step): array
    {
        $out = ['min' => '', 'max' => '', 'step' => '', 'stored' => false];
        if ($postId <= 0 || !function_exists('update_post_meta')) {
            return $out;
        }
        $wanted = [
            ProjectedQuantity::META_MIN => $min,
            ProjectedQuantity::META_MAX => $max,
            ProjectedQuantity::META_STEP => $step,
        ];
        foreach ($wanted as $key => $value) {
            update_post_meta($postId, $key, $value);
        }
        // READ BACK, and the ownership record is taken from what came back.
        // `update_post_meta()` answers the same thing for «failed» and «was
        // already that» (`alpha.44`), and WooCommerce filters on the way in
        // (`alpha.26`) — so a record built from what was SENT would claim a
        // value the shop does not hold, and the first rollback would refuse.
        $after = [];
        $record = [];
        foreach ($wanted as $key => $value) {
            $stored = (string) get_post_meta($postId, $key, true);
            $after[$key] = $stored;
            $record[$key] = ProjectedQuantity::fingerprint($stored);
        }
        $out['min'] = $after[ProjectedQuantity::META_MIN];
        $out['max'] = $after[ProjectedQuantity::META_MAX];
        $out['step'] = $after[ProjectedQuantity::META_STEP];
        // Only the keys that came back as asked are claimed. A key the shop
        // changed on the way in is left out of the record, which makes the
        // next projection treat it as somebody else's — conservative, and the
        // same rule `ProjectedQuantity` already applies.
        foreach ($wanted as $key => $value) {
            if ($after[$key] !== $value) {
                unset($record[$key]);
            }
        }
        update_post_meta($postId, ProjectedQuantity::OWNED_META, $record);
        $storedRecord = ProjectedQuantity::normalise(get_post_meta($postId, ProjectedQuantity::OWNED_META, true));
        // «Stored» means the three values are what was asked for AND the
        // record on disk is EXACTLY the one just built for them. A write with
        // no usable record would be a rule nothing can roll back, which is
        // worse than no write at all.
        //
        // **Counting the entries is not that test, and `alpha.53` shipped the
        // count.** A product projected earlier already carries a three-entry
        // record; refuse only the record write and the OLD one survives, three
        // keys and all, pointing at values that are no longer there. Measured
        // on `alpha.53`'s bytes: `port_says_stored=yes record_entries=3
        // record_entries_matching_shop=1 held_elsewhere_now=yes` — the
        // migration reported success and, from the very next read, the
        // marketplace believed a manager owned two of the three rows. A
        // vendor's purchase-limit edit stops being applied on a product this
        // plugin wrote itself, silently. So the comparison is key-for-key and
        // fingerprint-for-fingerprint, with `hash_equals`.
        $out['stored'] = $out['min'] === $min
            && $out['max'] === $max
            && $out['step'] === $step
            && $this->recordIs($storedRecord, $record);
        return $out;
    }

    /**
     * Whether the record on disk is exactly the expected `key => fingerprint`
     * map — same keys, same fingerprints, nothing extra.
     *
     * @param array<string,string> $stored
     * @param array<string,string> $expected
     */
    private function recordIs(array $stored, array $expected): bool
    {
        if (count($stored) !== count($expected)) {
            return false;
        }
        foreach ($expected as $key => $fingerprint) {
            if (!isset($stored[$key]) || !hash_equals($stored[$key], $fingerprint)) {
                return false;
            }
        }
        return true;
    }

    public function restore(int $postId, ?string $min, ?string $max, ?string $step, ?string $record): string
    {
        if ($postId <= 0 || !function_exists('update_post_meta')) {
            return 'unavailable';
        }
        $wanted = [
            ProjectedQuantity::META_MIN => $min,
            ProjectedQuantity::META_MAX => $max,
            ProjectedQuantity::META_STEP => $step,
        ];
        foreach ($wanted as $key => $value) {
            if ($value === null) {
                // There was no row. Deleting is the restore — writing `''`
                // would leave a row that reads as a rule for ever.
                delete_post_meta($postId, $key);
                if (metadata_exists('post', $postId, $key)) {
                    return $key;
                }
                continue;
            }
            update_post_meta($postId, $key, $value);
            if ((string) get_post_meta($postId, $key, true) !== $value) {
                return $key;
            }
        }
        // The record is the fourth value and it is verified like the other
        // three. `alpha.53` wrote it and never looked: a restore whose record
        // did not land reported success and left the destination owned by a
        // map describing values that are no longer there — the same hole the
        // count in `write()` left, at the other end of the round trip.
        if ($record === null) {
            delete_post_meta($postId, ProjectedQuantity::OWNED_META);
            if (metadata_exists('post', $postId, ProjectedQuantity::OWNED_META)) {
                return ProjectedQuantity::OWNED_META;
            }
            return '';
        }
        $decoded = json_decode($record, true);
        if (!is_array($decoded)) {
            // The stored record is not a map. Restoring it would put a value
            // nothing can read back where ownership is decided, so this is a
            // refusal rather than a best effort.
            return ProjectedQuantity::OWNED_META;
        }
        update_post_meta($postId, ProjectedQuantity::OWNED_META, $decoded);
        if (!$this->recordIs(
            ProjectedQuantity::normalise(get_post_meta($postId, ProjectedQuantity::OWNED_META, true)),
            ProjectedQuantity::normalise($decoded)
        )) {
            return ProjectedQuantity::OWNED_META;
        }
        return '';
    }

    /**
     * Take the row locks the compare-and-restore needs, inside the caller's
     * transaction.
     *
     * The three meta rows plus the ownership record, `FOR UPDATE`, so a second
     * writer landing between «the shop still holds what we wrote» and the
     * restore itself is serialised behind this request rather than silently
     * overwritten. Measured on `alpha.53`'s bytes with a second writer on its
     * own statement inside that window: `injected_inside_window=yes
     * rollback_code=minmax_rolled_back max_now=__absent__` — the manager's
     * brand-new value was deleted and the rollback reported success.
     *
     * A lock without a transaction is nothing (`alpha.50`), so this answers
     * false when there is none and the caller refuses rather than pretending.
     */
    public function lockQuantityRows(int $postId): bool
    {
        if ($postId <= 0) {
            return false;
        }
        $keys = array_merge(ProjectedQuantity::keys(), [ProjectedQuantity::OWNED_META]);
        $placeholders = implode(', ', array_fill(0, count($keys), '%s'));
        // **`execute()`, NOT `getResults()`, and that is the whole point of
        // this line.** `getResults()` answers `[]` both for «no rows» and for
        // «the statement failed», so a lock that TIMED OUT behind another
        // writer would read as a lock taken — and the first version of this
        // method did exactly that, which would have made the guard decorative
        // on the one path it exists for. `execute()` keeps the two apart:
        // `null` is a refusal and an integer is a result, `0` included
        // (`alpha.8`).
        //
        // Zero rows IS success here, and «why» had to be measured rather
        // than reasoned about. An earlier version of this comment said a
        // concurrent INSERT of the same `(post_id, meta_key)` would collide
        // on `postmeta`'s own unique index — THERE IS NO SUCH INDEX. Read off
        // WordPress 7.1's own table: `PRIMARY(meta_id)`, `KEY post_id`,
        // `KEY meta_key`, and nothing unique about the pair.
        //
        // What does protect an absent row is the next-key gap lock this
        // statement takes. Measured with two independent connections on
        // MariaDB 10.11 under REPEATABLE READ: with `locked_rows=0`, a second
        // connection's INSERT of that very `(post_id, meta_key)` answered
        // `blocked` (lock wait timeout) rather than landing. The cost, also
        // measured, is that the gap is wider than the one key — an insert of
        // a NEIGHBOURING key, and of the same key on another post, blocked
        // too. That is contention during a migration batch, not a
        // correctness problem, and it is written down so the next reader does
        // not mistake the breadth for a bug.
        $locked = $this->db->execute(
            'SELECT meta_id FROM `' . $this->db->prefix() . 'postmeta`
              WHERE post_id = %d AND meta_key IN (' . $placeholders . ') FOR UPDATE',
            array_merge([$postId], $keys)
        );
        return $locked !== null;
    }

    /**
     * Drop WordPress's cached copy of this post's meta.
     *
     * **The object cache is not transactional.** `update_post_meta()` writes
     * through `$wpdb` — the same connection `WpDatabase::begin()` started the
     * transaction on, so the WRITES do take part in it — and then updates the
     * cache, which a `ROLLBACK` cannot undo. So a restore that fails half way
     * leaves the database correct and the cache holding values that were never
     * committed, and every read in the rest of that request answers from the
     * cache.
     *
     * Measured both ways on `alpha.53`: after a SUCCESSFUL rollback the app
     * read and a raw `SELECT` already agreed, in the same request and in a
     * fresh one (`agree_same_request=yes agree_fresh=yes`), because
     * `update_post_meta`/`delete_post_meta` invalidate the cache themselves.
     * It is the FAILURE path that needs this, and that path is the one the
     * instruction asks about.
     */
    public function forgetCachedQuantities(int $postId): void
    {
        if ($postId > 0 && function_exists('wp_cache_delete')) {
            // Cast because this repository's WordPress stub types the key as
            // `string` while core leaves it untyped and passes the int id.
            // The two reach the same entry either way: PHP normalises a
            // numeric string array key to an integer.
            wp_cache_delete((string) $postId, 'post_meta');
        }
    }

    /** @param array{min:?string, max:?string, step:?string} $rule */
    public function grantEnforcement(int $postId, array $rule): bool
    {
        return $this->setEnforcement($postId, self::fingerprintOf($rule));
    }

    public function revokeEnforcement(int $postId): bool
    {
        return $this->setEnforcement($postId, '');
    }

    public function enforcementGranted(int $postId): bool
    {
        if ($postId <= 0 || !function_exists('get_post_meta')) {
            return false;
        }
        $stored = (string) get_post_meta($postId, ProjectedQuantity::ENFORCE_META, true);
        if ($stored === '') {
            return false;
        }
        // Live, not merely present: the grant is about the rule it was
        // granted for, so a report that read it as a flag would print
        // «enforced» about numbers the cart is ignoring.
        $now = $this->readRows($postId);
        if (!$now['read']) {
            return false;
        }
        return hash_equals($stored, self::fingerprintOf($now));
    }

    /**
     * The fingerprint of a rule as `readRows()` returns it.
     *
     * `null` and `''` fold to the same input, because an absent row and an
     * empty row are the same rule — and because that is what the cart sees:
     * `WC_Data::get_meta()` answers `''` for a key that has no row.
     *
     * @param array{min:?string, max:?string, step:?string} $rule
     */
    private static function fingerprintOf(array $rule): string
    {
        return ProjectedQuantity::ruleFingerprint([
            ProjectedQuantity::META_MIN => (string) ($rule['min'] ?? ''),
            ProjectedQuantity::META_MAX => (string) ($rule['max'] ?? ''),
            ProjectedQuantity::META_STEP => (string) ($rule['step'] ?? ''),
        ]);
    }

    /**
     * The grant written and READ BACK, because `update_post_meta()` cannot
     * tell «it failed» from «it was already that» (`alpha.44`), and this one
     * decides what a shopper may put in a basket.
     *
     * Removing it DELETES the row rather than writing `'0'`: a row holding
     * zero is a row every later reader has to interpret, and absence is the
     * state the rest of the catalogue is already in.
     *
     * `$fingerprint` empty means revoke.
     */
    private function setEnforcement(int $postId, string $fingerprint): bool
    {
        if ($postId <= 0 || !function_exists('update_post_meta')) {
            return false;
        }
        if ($fingerprint !== '') {
            update_post_meta($postId, ProjectedQuantity::ENFORCE_META, $fingerprint);
            return hash_equals(
                $fingerprint,
                (string) get_post_meta($postId, ProjectedQuantity::ENFORCE_META, true)
            );
        }
        delete_post_meta($postId, ProjectedQuantity::ENFORCE_META);
        return !function_exists('metadata_exists')
            || !metadata_exists('post', $postId, ProjectedQuantity::ENFORCE_META);
    }

    public function exists(int $postId): bool
    {
        if ($postId <= 0) {
            return false;
        }
        $type = (string) ($this->db->getVar(
            'SELECT post_type FROM `' . $this->db->prefix() . 'posts` WHERE ID = %d',
            [$postId]
        ) ?? '');
        return $type === 'product' || $type === 'product_variation';
    }

    /**
     * Whether the marketplace owns this product — asked of the marketplace
     * table, not of a meta stamp.
     *
     * A variation is answered by its parent, because that is how the rest of
     * the plugin answers it: `tmc_products.wc_product_id` holds the parent and
     * `QuantityGuard` asks the same question through `PurchaseGuard::ask()`.
     *
     * Only `marketplace` ownership counts. A row linked as `observed` is a
     * Dokan product this plugin has merely SEEN (`alpha.10`), and seeing a
     * product is not enforcing a rule on it.
     */
    public function marketplaceOwns(int $postId): bool
    {
        if ($postId <= 0) {
            return false;
        }
        $parent = (int) ($this->db->getVar(
            'SELECT post_parent FROM `' . $this->db->prefix() . 'posts` WHERE ID = %d',
            [$postId]
        ) ?? 0);
        $ids = $parent > 0 ? [$postId, $parent] : [$postId];
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $found = $this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->db->prefix() . 'tmc_products`
              WHERE wc_product_id IN (' . $placeholders . ') AND link_ownership = %s',
            array_merge($ids, ['marketplace'])
        );
        return $found !== null && (int) $found > 0;
    }
}
