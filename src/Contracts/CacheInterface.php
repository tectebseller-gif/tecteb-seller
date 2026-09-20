<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts;

/**
 * A short-lived store for something that was expensive to work out.
 *
 * Deliberately small. There is no `clear()` and no key enumeration, because a
 * cache whose invalidation has to name every key it wrote is a cache that
 * misses one — the lesson the public store page learned in `alpha.16`: page 7
 * of a catalogue nobody has visited since is exactly the key a sweep forgets.
 * Callers invalidate by bumping a version that is part of the key, which makes
 * every key ever written under the old version unreachable in one write and
 * lets the orphans expire on their own.
 *
 * `get()` answering `null` must always be survivable: a cache is an
 * optimisation, and a caller that cannot compute the answer without it has a
 * bug rather than a cache.
 */
interface CacheInterface
{
    /** The value, or null when it is absent, expired or unreadable. */
    public function get(string $key): mixed;

    /** @param positive-int $ttl seconds */
    public function put(string $key, mixed $value, int $ttl): bool;

    /** Removes one key. Not a sweep: exactly the key given, or nothing. */
    public function forget(string $key): bool;

    /**
     * A monotonic counter, per namespace, that callers fold into their keys.
     *
     * `bump()` is the invalidation. It never decreases and it never expires:
     * a counter that could fall back to its starting value would resurrect
     * everything cached under it, which is the one failure this design exists
     * to prevent.
     */
    public function version(string $namespace): int;

    public function bump(string $namespace): int;
}
