<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\GuardedOptionStoreInterface;
use Tecteb\Marketplace\Contracts\LockStoreInterface;

/**
 * Atomic lock primitives on wp_options via direct, prepared SQL:
 *   insert           → INSERT IGNORE (0 rows when the name exists)
 *   compareAndSwap   → UPDATE ... WHERE option_name = ? AND option_value = ?
 *   compareAndDelete → DELETE ... WHERE option_name = ? AND option_value = ?
 * Bypasses the option cache on purpose (locks must never be served stale)
 * and invalidates it after each write. Same pattern WordPress core uses for
 * WP_Upgrader::create_lock().
 */
final class WpLockStore implements LockStoreInterface, GuardedOptionStoreInterface
{
    public function __construct(private \wpdb $wpdb)
    {
    }

    public function insert(string $key, string $value): bool
    {
        $sql = $this->wpdb->prepare(
            "INSERT IGNORE INTO {$this->wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $key,
            $value
        );
        $result = $this->wpdb->query($sql);
        $this->forgetCache($key);
        return $result === 1;
    }

    public function read(string $key): ?string
    {
        $value = $this->wpdb->get_var(
            $this->wpdb->prepare("SELECT option_value FROM {$this->wpdb->options} WHERE option_name = %s LIMIT 1", $key)
        );
        return $value === null ? null : (string) $value;
    }

    public function compareAndSwap(string $key, string $expected, string $new): bool
    {
        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                $new,
                $key,
                $expected
            )
        );
        $this->forgetCache($key);
        return $result === 1;
    }

    public function compareAndDelete(string $key, string $expected): bool
    {
        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->wpdb->options} WHERE option_name = %s AND option_value = %s",
                $key,
                $expected
            )
        );
        $this->forgetCache($key);
        return $result === 1;
    }

    /**
     * Single-statement upsert guarded by the lock row.
     *
     * INSERT ... SELECT produces a row only when the guard matches, so a
     * mismatched guard writes nothing at all; ON DUPLICATE KEY UPDATE covers
     * the case where $key already exists. There is no window between the
     * comparison and the write because MySQL evaluates both inside one
     * statement.
     */
    public function setGuarded(string $key, mixed $value, string $guardKey, string $guardValue): bool
    {
        $stored = maybe_serialize($value);
        $sql = $this->wpdb->prepare(
            "INSERT INTO {$this->wpdb->options} (option_name, option_value, autoload)
             SELECT %s, %s, 'no' FROM {$this->wpdb->options} AS guard
             WHERE guard.option_name = %s AND guard.option_value = %s
             ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
            $key,
            $stored,
            $guardKey,
            $guardValue
        );
        $result = $this->wpdb->query($sql);
        $this->forgetCache($key);
        return is_int($result) && $result > 0;
    }

    /**
     * Single-statement delete guarded by the lock row: the join yields no
     * rows when the guard does not match, so nothing is removed.
     */
    public function deleteGuarded(string $key, string $guardKey, string $guardValue): bool
    {
        $sql = $this->wpdb->prepare(
            "DELETE target FROM {$this->wpdb->options} AS target
             INNER JOIN {$this->wpdb->options} AS guard
                     ON guard.option_name = %s AND guard.option_value = %s
             WHERE target.option_name = %s",
            $guardKey,
            $guardValue,
            $key
        );
        $result = $this->wpdb->query($sql);
        $this->forgetCache($key);
        return is_int($result) && $result > 0;
    }

    private function forgetCache(string $key): void
    {
        if (!function_exists('wp_cache_delete')) {
            return;
        }
        wp_cache_delete($key, 'options');
        $notoptions = wp_cache_get('notoptions', 'options');
        if (is_array($notoptions) && isset($notoptions[$key])) {
            unset($notoptions[$key]);
            wp_cache_set('notoptions', $notoptions, 'options');
        }
    }
}
