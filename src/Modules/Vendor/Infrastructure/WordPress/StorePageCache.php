<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress;

/**
 * The public store page, cached — and invalidated by bumping a number rather
 * than by hunting down keys.
 *
 * The page is the one screen a crawler asks for repeatedly and a customer
 * opens on a phone, and it costs a `countForVendor`, a paged `forVendor`, a
 * rating standing and a page of thumbnails. None of that changes between two
 * visits a second apart.
 *
 * **Invalidation is a version counter per shop, not a key sweep.** A cache
 * whose invalidation has to enumerate every key it wrote is a cache that
 * misses one: page 7 of a catalogue nobody has visited since is exactly the
 * key a sweep forgets, and it then serves a withdrawn product for as long as
 * the TTL allows. Bumping `tmc_store_v_<id>` makes every key that shop ever
 * wrote unreachable in one write, and the orphans expire on their own.
 *
 * **Closure is deliberately not cached.** A shop that reopens must serve a
 * buyable page immediately, and «up to ten minutes stale» is the wrong answer
 * to «why does my shop still say closed». `StorePage` therefore asks for a
 * cached body only while the shop's state is steady, and skips the cache
 * entirely on the closed path.
 */
final class StorePageCache
{
    /** Ten minutes: long enough to absorb a crawl, short enough to forgive. */
    public const TTL = 600;

    private const VERSION_PREFIX = 'tmc_store_v_';
    private const BODY_PREFIX = 'tmc_store_page_';

    public static function get(int $vendorUserId, int $page): ?string
    {
        if (!self::enabled()) {
            return null;
        }
        $body = get_transient(self::key($vendorUserId, $page));
        return is_string($body) && $body !== '' ? $body : null;
    }

    public static function put(int $vendorUserId, int $page, string $html): void
    {
        if (!self::enabled() || $html === '') {
            return;
        }
        set_transient(self::key($vendorUserId, $page), $html, self::TTL);
    }

    /**
     * Everything this shop has cached becomes unreachable, in one write.
     *
     * Called from every place that can change what the page says — a product
     * published or withdrawn, the store settings saved, a closure toggled, a
     * rating recorded. The list lives at the call sites and not here, so a new
     * writer is a new `forget()` rather than a new branch in a switch nobody
     * remembers to update.
     */
    public static function forget(int $vendorUserId): void
    {
        if ($vendorUserId <= 0) {
            return;
        }
        $next = self::version($vendorUserId) + 1;
        // No expiry on the version itself: a version that expired would let
        // the counter fall back to 1 and resurrect a body cached under the
        // very first version, which is the one bug this design exists to
        // avoid. It is one small option per shop.
        update_option(self::VERSION_PREFIX . $vendorUserId, $next, false);
    }

    /** Off by default is wrong here, but a manager must be able to turn it off. */
    private static function enabled(): bool
    {
        return (string) get_option('tmc_store_page_cache', '1') === '1';
    }

    private static function version(int $vendorUserId): int
    {
        return max(1, (int) get_option(self::VERSION_PREFIX . $vendorUserId, 1));
    }

    private static function key(int $vendorUserId, int $page): string
    {
        return self::BODY_PREFIX . $vendorUserId . '_' . max(1, $page) . '_v' . self::version($vendorUserId);
    }
}
