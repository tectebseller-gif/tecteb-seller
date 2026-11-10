<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Infrastructure\WordPress\Lifecycle\Activator;
use Tecteb\Marketplace\Infrastructure\WordPress\Lifecycle\Deactivator;
use Tecteb\Marketplace\Modules\Product\Application\DeactivationStopPolicy;
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
        // Ours and only ours. A scheduled hook whose callback is gone fires
        // for ever against nothing, so deactivation clears the queue tick —
        // and clears NOTHING else, which is the half of this assertion that
        // protects the other plugins on the site.
        self::assertSame(['tmc_run_jobs'], State::$clearedScheduledHooks);

        $events = array_map(static fn ($r): string => $r->eventType, $this->audit->records);
        self::assertContains('plugin.deactivated', $events);
        self::assertSame(1, $this->audit->records[array_search('plugin.deactivated', $events, true)]->actorId);

        // ⛔ REWRITTEN, NOT WEAKENED. This used to assert the opposite:
        // «whatever happened to the shelf, the marketplace is left STOPPED».
        // That was a true statement about `alpha.101`, where deactivation
        // stopped selling unconditionally — and it is the exact state §6 asked
        // to change: «توقف فروش … با غیرفعال‌سازی فنی افزونه یکی نباشد».
        //
        // So the contract measured here is the new one, and it is stronger
        // rather than looser: a plain deactivation leaves the shelf ALONE, and
        // says so in the row.
        self::assertArrayNotHasKey(
            'tmc_storefront_stop',
            State::$options,
            'a plain deactivation must not stop selling'
        );
        $row = $this->audit->records[array_search('plugin.deactivated', $events, true)];
        self::assertSame('no', $row->payload['stopped_selling'] ?? null, 'and the row says which way it went');
        self::assertArrayHasKey('left_on_sale', $row->payload, 'and what it left behind');
    }

    /**
     * And the control, which is the half that keeps the change honest: told to
     * stop, deactivation still stops — the `alpha.101` behaviour, now chosen
     * rather than assumed.
     */
    public function testDeactivationStillStopsSellingWhenTheOwnerAskedForIt(): void
    {
        $this->bootPlugin(false);
        $this->loginAdmin();
        State::$options[DeactivationStopPolicy::OPTION] = true;

        Deactivator::deactivate();

        self::assertArrayHasKey(
            'tmc_storefront_stop',
            State::$options,
            'the opt-in must still reach the stop'
        );
        self::assertSame('plugin_deactivated', State::$options['tmc_storefront_stop']['reason'] ?? '');
        $events = array_map(static fn ($r): string => $r->eventType, $this->audit->records);
        $row = $this->audit->records[array_search('plugin.deactivated', $events, true)];
        self::assertSame('yes', $row->payload['stopped_selling'] ?? null);
    }
}
