<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Contracts\CacheInterface;

/**
 * A cache in memory, with the one property the real one must have: a bumped
 * version can never be un-bumped, so nothing cached under an old version is
 * reachable again.
 *
 * It keeps the raw store public so a test can assert what a key actually
 * holds — «shop 7's entry does not contain shop 8's figures» is only a real
 * assertion if the test can look.
 */
final class InMemoryCache implements CacheInterface
{
    /** @var array<string,array{value:mixed, ttl:int}> */
    public array $entries = [];
    /** @var array<string,int> */
    public array $versions = [];
    public int $hits = 0;
    public int $misses = 0;

    public function get(string $key): mixed
    {
        if (!array_key_exists($key, $this->entries)) {
            $this->misses++;
            return null;
        }
        $this->hits++;
        return $this->entries[$key]['value'];
    }

    public function put(string $key, mixed $value, int $ttl): bool
    {
        if ($ttl <= 0) {
            return false;
        }
        $this->entries[$key] = ['value' => $value, 'ttl' => $ttl];
        return true;
    }

    public function forget(string $key): bool
    {
        $had = array_key_exists($key, $this->entries);
        unset($this->entries[$key]);
        return $had;
    }

    public function version(string $namespace): int
    {
        return $this->versions[$namespace] ??= 1;
    }

    public function bump(string $namespace): int
    {
        return $this->versions[$namespace] = $this->version($namespace) + 1;
    }

    /**
     * Keys still reachable at the CURRENT version — what a reader could hit.
     *
     * The namespace is read back OUT of the key rather than looked up in
     * `$this->versions`, because a namespace that has only ever been written
     * to has no entry there: the first version of this walked the versions
     * map and silently reported an untouched shop's live entry as gone,
     * which is the opposite of the fact under test.
     */
    public function liveKeys(): array
    {
        $live = [];
        foreach (array_keys($this->entries) as $key) {
            $at = strrpos($key, ':v');
            if ($at === false) {
                continue;
            }
            $namespace = substr($key, 0, $at);
            $version = (int) strtok(substr($key, $at + 2), ':');
            if ($version === $this->version($namespace)) {
                $live[] = $key;
            }
        }
        return $live;
    }
}
