<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\OptionStoreInterface;

/**
 * wp_options adapter. Options are stored with autoload disabled: none of
 * them is needed on the front end. Handles update_option()'s "false when
 * unchanged" quirk so set() only reports real persistence failures.
 */
final class WpOptionStore implements OptionStoreInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        $sentinel = new \stdClass();
        $value = get_option($key, $sentinel);
        if ($value === $sentinel) {
            return $default;
        }
        // A value written by the guarded single-statement path bypasses
        // WordPress's own write helpers, so it can still be in its serialised
        // form here. get_option() unserialises what IT wrote; normalise the
        // rest so both paths read back identically.
        return is_string($value) ? maybe_unserialize($value) : $value;
    }

    public function set(string $key, mixed $value): bool
    {
        $sentinel = new \stdClass();
        $existing = get_option($key, $sentinel);
        if ($existing === $sentinel) {
            return (bool) add_option($key, $value, '', false);
        }
        if ($existing === $value) {
            return true;
        }
        return (bool) update_option($key, $value, false);
    }

    public function delete(string $key): bool
    {
        $sentinel = new \stdClass();
        if (get_option($key, $sentinel) === $sentinel) {
            return true;
        }
        return (bool) delete_option($key);
    }
}
