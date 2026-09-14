<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Vendor;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Vendor\Domain\StoreSettings;

/**
 * What a vendor may put in their own settings, and what is refused.
 *
 * The refusals matter more than the acceptances: a social field is a URL a
 * stranger will click, a carrier is a choice from the manager's list, and the
 * store name is not editable here at all.
 */
final class StoreSettingsTest extends TestCase
{
    private const NETWORKS = ['instagram', 'linkedin'];
    private const CARRIERS = ['post', 'tipax'];

    public function testTheStoreNameIsNeverChangedByASave(): void
    {
        $current = new StoreSettings('داروخانه اصلی');

        ['settings' => $saved] = StoreSettings::fromInput(
            ['store_name' => 'یک نام دیگر', 'city' => 'تهران'],
            self::NETWORKS,
            self::CARRIERS,
            $current
        );

        self::assertSame('داروخانه اصلی', $saved->storeName, 'renaming is a change request, not a save');
        self::assertSame('تهران', $saved->city);
    }

    public function testJavascriptAndDataUrlsAreRefused(): void
    {
        ['settings' => $saved, 'problems' => $problems] = StoreSettings::fromInput(
            ['social' => ['instagram' => 'javascript:alert(1)', 'linkedin' => 'https://linkedin.com/company/x']],
            self::NETWORKS,
            self::CARRIERS,
            new StoreSettings()
        );

        self::assertArrayNotHasKey('instagram', $saved->social);
        self::assertSame('https://linkedin.com/company/x', $saved->social['linkedin']);
        self::assertContains('url:instagram', $problems);
    }

    public function testANetworkTheManagerDidNotAllowIsRefused(): void
    {
        ['settings' => $saved, 'problems' => $problems] = StoreSettings::fromInput(
            ['social' => ['telegram' => 'https://t.me/x']],
            self::NETWORKS,
            self::CARRIERS,
            new StoreSettings()
        );

        self::assertSame([], $saved->social);
        self::assertContains('network:telegram', $problems);
    }

    public function testACarrierOutsideTheManagersListIsRefused(): void
    {
        ['settings' => $saved, 'problems' => $problems] = StoreSettings::fromInput(
            ['carriers' => ['post', 'my-own-courier']],
            self::NETWORKS,
            self::CARRIERS,
            new StoreSettings()
        );

        self::assertSame(['post'], $saved->carriers);
        self::assertContains('carrier:my-own-courier', $problems);
    }

    public function testAnAbsurdPreparationTimeKeepsTheOldValue(): void
    {
        $current = new StoreSettings(preparationDays: 3);

        ['settings' => $saved, 'problems' => $problems] = StoreSettings::fromInput(
            ['preparation_days' => 900],
            self::NETWORKS,
            self::CARRIERS,
            $current
        );

        self::assertSame(3, $saved->preparationDays);
        self::assertContains('preparation_days', $problems);
    }

    public function testAClosureThatEndsBeforeItStartsIsRefusedWhole(): void
    {
        ['settings' => $saved, 'problems' => $problems] = StoreSettings::fromInput(
            ['closed' => true, 'closed_from' => '2026-10-10', 'closed_to' => '2026-10-01'],
            self::NETWORKS,
            self::CARRIERS,
            new StoreSettings()
        );

        self::assertNull($saved->closedFrom);
        self::assertNull($saved->closedTo);
        self::assertContains('closure_range', $problems);
    }

    public function testClosureAppliesOnlyInsideItsRange(): void
    {
        $closed = new StoreSettings(closed: true, closedFrom: '2026-10-01', closedTo: '2026-10-05');

        self::assertFalse($closed->isClosedOn('2026-09-30'));
        self::assertTrue($closed->isClosedOn('2026-10-03'));
        self::assertFalse($closed->isClosedOn('2026-10-06'));
    }

    public function testAnOpenEndedClosureStaysClosed(): void
    {
        $closed = new StoreSettings(closed: true);

        self::assertTrue($closed->isClosedOn('2030-01-01'));
    }

    public function testAShopThatIsNotClosedIsNeverClosedOnAnyDay(): void
    {
        $open = new StoreSettings(closed: false, closedFrom: '2026-10-01', closedTo: '2026-10-05');

        self::assertFalse($open->isClosedOn('2026-10-03'));
    }
}
