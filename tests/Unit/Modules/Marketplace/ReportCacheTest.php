<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Marketplace;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Modules\Marketplace\Application\ReportCacheKey;
use Tecteb\Marketplace\Modules\Marketplace\Application\Reports;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Tests\Support\InMemoryCache;

/**
 * Two questions about a cached financial figure, and they are the only two
 * that matter: can it be stale, and can it belong to somebody else.
 *
 * The reports themselves are built from repositories that are not loaded
 * here, so every card comes back empty — which is exactly right for this
 * test. What is under examination is the KEY and the VERSION, and those are
 * the same whether the figures are zero or a billion.
 */
final class ReportCacheTest extends TestCase
{
    public function testAVendorsEntryIsNotReachableWithAnotherVendorsId(): void
    {
        self::assertNotSame(
            ReportCacheKey::forVendor(7),
            ReportCacheKey::forVendor(8),
            'two shops must not share a namespace'
        );
        // And no shop may collide with the marketplace-wide pair either: the
        // manager's totals are a different thing from any one shop's.
        self::assertNotSame(ReportCacheKey::MARKETPLACE, ReportCacheKey::forVendor(0));
    }

    public function testBumpingMakesEveryKeyOfThatShopUnreachableAtOnce(): void
    {
        $cache = new InMemoryCache();
        $ns = ReportCacheKey::forVendor(7);

        $cache->put($ns . ':v' . $cache->version($ns), ['finance' => ['share' => 100]], 90);
        self::assertSame([$ns . ':v1'], $cache->liveKeys());

        $cache->bump($ns);
        self::assertSame([], $cache->liveKeys(), 'the old entry must be unreachable');
        self::assertNull($cache->get($ns . ':v' . $cache->version($ns)));
    }

    public function testBumpingOneShopLeavesAnotherShopsEntryAlone(): void
    {
        $cache = new InMemoryCache();
        $seven = ReportCacheKey::forVendor(7);
        $eight = ReportCacheKey::forVendor(8);
        $cache->put($seven . ':v1', ['seven'], 90);
        $cache->put($eight . ':v1', ['eight'], 90);

        $cache->bump($seven);

        self::assertSame([$eight . ':v1'], $cache->liveKeys());
        self::assertSame(['eight'], $cache->get($eight . ':v' . $cache->version($eight)));
    }

    public function testAVersionNeverGoesBackwards(): void
    {
        $cache = new InMemoryCache();
        $ns = ReportCacheKey::forVendor(7);
        $first = $cache->version($ns);
        for ($i = 0; $i < 5; $i++) {
            self::assertGreaterThan($first, $cache->bump($ns));
            $first = $cache->version($ns);
        }
    }

    public function testAShopThatIsNotYoursGetsNothing(): void
    {
        // The access check is before the cache, so a wrong asker never even
        // reaches a key — the cached entry cannot leak by being asked for.
        $cache = new InMemoryCache();
        $reports = new Reports($this->containerWithStoreFor(7), $cache);

        self::assertSame([], $reports->forVendor(999, 7), 'a stranger gets nothing');
        self::assertSame([], $cache->entries, 'and nothing is cached for them');
    }

    public function testForgettingAShopBumpsOnlyThatShop(): void
    {
        $cache = new InMemoryCache();
        $reports = new Reports($this->containerWithStoreFor(7), $cache);

        $reports->forgetVendor(7);

        self::assertSame(2, $cache->version(ReportCacheKey::forVendor(7)));
        self::assertSame(1, $cache->version(ReportCacheKey::forVendor(8)));
    }

    public function testForgettingRefusesAnImpossibleShopId(): void
    {
        $cache = new InMemoryCache();
        $reports = new Reports($this->containerWithStoreFor(7), $cache);

        $reports->forgetVendor(0);
        $reports->forgetVendor(-3);

        self::assertSame([], $cache->versions, 'no namespace is created for a shop that cannot exist');
    }

    public function testTheMarketplacePairIsKeyedByTheSelectionItSummed(): void
    {
        $cache = new InMemoryCache();
        $reports = new Reports($this->containerWithStoreFor(7), $cache);

        $reports->forManager([7, 8]);
        $reports->forManager([9]);
        self::assertCount(2, $cache->entries, 'two different selections are two entries');

        // The same selection in a different order is the same selection.
        $before = array_keys($cache->entries);
        $reports->forManager([8, 7]);
        self::assertSame($before, array_keys($cache->entries));
    }

    /** A container that knows only «who owns this shop», which is all the cache path needs. */
    private function containerWithStoreFor(int $vendorUserId): ContainerInterface
    {
        $access = new class($vendorUserId) {
            public function __construct(private int $vendorUserId)
            {
            }

            public function storeFor(int $userId): ?int
            {
                return $userId === $this->vendorUserId ? $this->vendorUserId : null;
            }
        };

        return new class($access) implements ContainerInterface {
            public function __construct(private object $access)
            {
            }

            public function has(string $id): bool
            {
                return $id === StaffAccess::class;
            }

            public function get(string $id): mixed
            {
                return $id === StaffAccess::class ? $this->access : null;
            }

            public function bind(string $id, callable $factory): void
            {
            }

            public function instance(string $id, object $instance): void
            {
            }
        };
    }
}
