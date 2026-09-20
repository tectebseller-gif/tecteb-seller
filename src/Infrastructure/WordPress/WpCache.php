<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\CacheInterface;

/**
 * The cache, on WordPress: transients for the values, options for the
 * versions.
 *
 * **Why the split.** A transient can vanish at any moment — an object cache
 * flush, an eviction, a `DELETE FROM options` in somebody's cleanup plugin —
 * and that is fine for a value, which is only ever an optimisation. It is not
 * fine for a version counter: a counter that falls back to its starting value
 * makes every body cached under the ORIGINAL version reachable again, which
 * would resurrect precisely the stale figures the counter exists to retire.
 * So versions are autoload-false options, which persist, and values are
 * transients, which do not have to.
 *
 * **Why no `clear()`.** Enumerating keys to delete them is how page 7 of a
 * catalogue keeps serving a withdrawn product (`alpha.16`). Bumping a version
 * is one write and retires everything.
 */
final class WpCache implements CacheInterface
{
    private const VERSION_PREFIX = 'tmc_cache_v_';

    /**
     * Per-request memo for the version numbers.
     *
     * A report page asks for the same shop's version once per card, and every
     * ask is an option read. The container keeps one instance per request, so
     * this lives exactly as long as the request does — and `bump()` writes
     * through it rather than around it, so a read after a bump in the same
     * request sees the new number.
     *
     * @var array<string,int>
     */
    private array $versions = [];

    public function get(string $key): mixed
    {
        $value = get_transient($this->prefixed($key));
        return $value === false ? null : $value;
    }

    public function put(string $key, mixed $value, int $ttl): bool
    {
        if ($ttl <= 0) {
            return false;
        }
        return (bool) set_transient($this->prefixed($key), $value, $ttl);
    }

    public function forget(string $key): bool
    {
        return (bool) delete_transient($this->prefixed($key));
    }

    public function version(string $namespace): int
    {
        if (isset($this->versions[$namespace])) {
            return $this->versions[$namespace];
        }
        $stored = (int) get_option(self::VERSION_PREFIX . $this->slug($namespace), 1);
        return $this->versions[$namespace] = max(1, $stored);
    }

    public function bump(string $namespace): int
    {
        $next = $this->version($namespace) + 1;
        // Autoload false: this is read on the pages that need it, not on every
        // request. No expiry, for the reason in the class docblock.
        update_option(self::VERSION_PREFIX . $this->slug($namespace), $next, false);
        $this->versions[$namespace] = $next;
        return $next;
    }

    /**
     * Transient names are limited to 172 characters and ours already carry a
     * prefix, a namespace and a version. Anything long is hashed rather than
     * truncated: two keys truncated to the same name would serve one shop's
     * figures to another, which is the exact failure this whole file must not
     * have.
     */
    private function prefixed(string $key): string
    {
        $name = 'tmc_c_' . $this->slug($key);
        return strlen($name) <= 160 ? $name : 'tmc_c_' . md5($key);
    }

    private function slug(string $value): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_\-]/', '_', $value);
    }
}
