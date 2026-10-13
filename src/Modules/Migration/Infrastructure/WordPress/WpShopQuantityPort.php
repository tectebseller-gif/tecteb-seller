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
        // record for them is on disk. A write with no usable record would be a
        // rule nothing can roll back, which is worse than no write at all.
        $out['stored'] = $out['min'] === $min
            && $out['max'] === $max
            && $out['step'] === $step
            && count($storedRecord) === 3;
        return $out;
    }

    public function restore(int $postId, ?string $min, ?string $max, ?string $step, ?string $record): bool
    {
        if ($postId <= 0 || !function_exists('update_post_meta')) {
            return false;
        }
        $ok = true;
        foreach ([
            ProjectedQuantity::META_MIN => $min,
            ProjectedQuantity::META_MAX => $max,
            ProjectedQuantity::META_STEP => $step,
        ] as $key => $value) {
            if ($value === null) {
                // There was no row. Deleting is the restore — writing `''`
                // would leave a row that reads as a rule for ever.
                delete_post_meta($postId, $key);
                if (metadata_exists('post', $postId, $key)) {
                    $ok = false;
                }
                continue;
            }
            update_post_meta($postId, $key, $value);
            if ((string) get_post_meta($postId, $key, true) !== $value) {
                $ok = false;
            }
        }
        if ($record === null) {
            delete_post_meta($postId, ProjectedQuantity::OWNED_META);
        } else {
            $decoded = json_decode($record, true);
            update_post_meta($postId, ProjectedQuantity::OWNED_META, $decoded);
        }
        return $ok;
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
