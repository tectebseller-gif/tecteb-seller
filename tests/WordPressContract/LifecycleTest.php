<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Infrastructure\WordPress\Lifecycle\Activator;
use Tecteb\Marketplace\Infrastructure\WordPress\Lifecycle\Deactivator;
use Tecteb\Marketplace\Infrastructure\WordPress\Lifecycle\MultisiteGuard;
use TmcWpStubs\State;
use TmcWpStubs\WpDieException;

/** CORE-01: multisite refusal without side effects; deactivation preserves data. Activation itself runs in the database suite. */
final class LifecycleTest extends ContractTestCase
{
    public function testNetworkActivationIsRefusedInPersianWithoutAnyChange(): void
    {
        $this->bootPlugin(false);
        State::$multisite = true;
        $rolesBefore = State::$roles;
        try {
            Activator::activate(true);
            self::fail('expected refusal');
        } catch (WpDieException $e) {
            self::assertStringContainsString('فعال‌سازی شبکه‌ای', $e->getMessage());
            self::assertStringContainsString('هیچ تغییری روی شبکه اعمال نشد', $e->getMessage());
            self::assertSame(400, $e->args['response']);
        }
        self::assertSame($rolesBefore, State::$roles);
        self::assertSame([], State::$options);
        self::assertSame([], State::$transients);
    }

    public function testPerSiteActivationOnMultisiteIsAlsoRefused(): void
    {
        State::$multisite = true;
        $this->expectException(WpDieException::class);
        $this->expectExceptionMessageMatches('/Multisite/');
        MultisiteGuard::assertAllowed(false);
    }

    public function testSingleSiteGuardIsSilent(): void
    {
        State::$multisite = false;
        MultisiteGuard::assertAllowed(false);
        MultisiteGuard::assertAllowed(true);
        self::assertSame([], State::$wpDieCalls);
    }

    public function testDeactivationPreservesDataAndOnlyClearsOwnHooks(): void
    {
        $this->bootPlugin(false);
        $this->loginAdmin();
        State::$options['tmc_settings'] = ['schema_version' => 1, 'values' => ['max_staff' => 42]];
        State::$options['tmc_schema_version'] = 1;
        State::$roles['administrator']['tmc_view_health'] = true;
        Deactivator::deactivate();
        self::assertSame(42, State::$options['tmc_settings']['values']['max_staff']);
        self::assertSame(1, State::$options['tmc_schema_version']);
        self::assertTrue(State::$roles['administrator']['tmc_view_health']);
        self::assertSame([], State::$clearedScheduledHooks, 'phase 1 owns no cron hooks; nothing else is touched');
        self::assertSame('plugin.deactivated', $this->audit->records[0]->eventType);
        self::assertSame(1, $this->audit->records[0]->actorId);
    }
}
