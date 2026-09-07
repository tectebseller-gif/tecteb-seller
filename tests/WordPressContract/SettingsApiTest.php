<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Modules\Admin\Infrastructure\SettingsRegistrar;
use TmcWpStubs\State;
use TmcWpStubs\WpDieException;

/** CORE-03/07: nonce AND capability; reject keeps previous; audit; no double conversion. */
final class SettingsApiTest extends ContractTestCase
{
    private function submit(array $values): void
    {
        $_REQUEST['_wpnonce'] = wp_create_nonce(SettingsRegistrar::GROUP . '-options');
        tmc_stub_submit_options(SettingsRegistrar::GROUP, SettingsRegistrar::OPTION, $values);
    }

    private function stored(): array
    {
        return State::$options[SettingsRegistrar::OPTION]['values'];
    }

    private function errorCodes(): array
    {
        return array_map(static fn ($e) => $e['code'], get_settings_errors(SettingsRegistrar::ERROR_SETTING));
    }

    public function testSettingIsRegisteredWithCapabilityFilter(): void
    {
        $this->bootPlugin(false);
        do_action('admin_init');
        self::assertSame(SettingsRegistrar::GROUP, State::$registeredSettings[SettingsRegistrar::OPTION]['group']);
        self::assertSame('tmc_manage_settings', apply_filters('option_page_capability_' . SettingsRegistrar::GROUP, 'manage_options'));
    }

    public function testValidSubmissionStoresCanonicalValuesAndAudits(): void
    {
        $this->bootPlugin(false);
        do_action('admin_init');
        $this->loginAdmin();
        $this->submit(['default_commission_rate' => '۱۲٫۵', 'settlement_delay_days' => '7', 'max_staff' => '12', 'environment_override' => 'staging']);
        self::assertSame(['default_commission_rate_bp' => 1250, 'settlement_delay_days' => 7, 'max_staff' => 12, 'environment_override' => 'staging'], $this->stored());
        self::assertContains('tmc_saved', $this->errorCodes());
        self::assertCount(1, $this->audit->records, 'one audit row even though WordPress sanitises twice for a new option');
        $rec = $this->audit->records[0];
        self::assertSame('settings.updated', $rec->eventType);
        self::assertSame(1, $rec->actorId);
        self::assertSame(['default_commission_rate_bp', 'settlement_delay_days', 'max_staff', 'environment_override'], $rec->payload['changed']);
        self::assertSame(1250, $rec->payload['new']['default_commission_rate_bp']);
        self::assertNull($rec->payload['old']['default_commission_rate_bp']);
    }

    public function testInvalidFieldKeepsPreviousValueWithPersianMessageWhileOthersSave(): void
    {
        $this->bootPlugin(false);
        do_action('admin_init');
        $this->loginAdmin();
        $this->submit(['default_commission_rate' => '5']);
        State::$settingsErrors = [];
        $this->submit(['default_commission_rate' => '100.01', 'max_staff' => '20']);
        self::assertSame(500, $this->stored()['default_commission_rate_bp'], 'previous value kept');
        self::assertSame(20, $this->stored()['max_staff']);
        $errors = get_settings_errors(SettingsRegistrar::ERROR_SETTING);
        self::assertSame('tmc_field_default_commission_rate', $errors[0]['code']);
        self::assertStringContainsString('مقدار قبلی حفظ شد', $errors[0]['message']);
        self::assertStringContainsString('بین ۰ و ۱۰۰', $errors[0]['message']);
        self::assertNotContains('tmc_saved', $this->errorCodes());
    }

    public function testInvalidNonceIsRejectedBeforeAnythingChanges(): void
    {
        $this->bootPlugin(false);
        do_action('admin_init');
        $this->loginAdmin();
        $_REQUEST['_wpnonce'] = 'forged';
        try {
            tmc_stub_submit_options(SettingsRegistrar::GROUP, SettingsRegistrar::OPTION, ['max_staff' => '99']);
            self::fail('expected wp_die');
        } catch (WpDieException $e) {
            self::assertSame(403, $e->args['response']);
        }
        self::assertArrayNotHasKey(SettingsRegistrar::OPTION, State::$options);
        self::assertSame([], $this->audit->records);
    }

    public function testValidNonceWithoutCapabilityChangesNothing(): void
    {
        $this->bootPlugin(false);
        do_action('admin_init');
        State::loginAs(3, ['manage_options']); // can reach options.php in vanilla WP, but lacks tmc_manage_settings
        try {
            $this->submit(['max_staff' => '99']);
            self::fail('options.php capability filter must refuse');
        } catch (WpDieException $e) {
            self::assertSame(403, $e->args['response']);
        }
        // Direct call to the sanitize callback (nonce already "valid"): capability is re-checked inside.
        $result = apply_filters('sanitize_option_' . SettingsRegistrar::OPTION, ['max_staff' => '99'], SettingsRegistrar::OPTION);
        self::assertSame(10, $result['values']['max_staff'], 'sanitize returns current values, not the submission');
        self::assertContains('tmc_forbidden', $this->errorCodes());
        self::assertSame([], $this->audit->records);
    }

    public function testAuditFailureIsReportedNotSilent(): void
    {
        $this->bootPlugin(false);
        do_action('admin_init');
        $this->loginAdmin();
        $this->audit->fail = true;
        $this->submit(['max_staff' => '15']);
        self::assertSame(15, $this->stored()['max_staff'], 'the setting itself is saved');
        self::assertContains('tmc_audit_failed', $this->errorCodes());
        self::assertNotContains('tmc_saved', $this->errorCodes());
        $warning = array_values(array_filter(get_settings_errors(SettingsRegistrar::ERROR_SETTING), static fn ($e) => $e['code'] === 'tmc_audit_failed'))[0];
        self::assertSame('warning', $warning['type']);
        self::assertStringContainsString('ثبت رویداد ممیزی ناموفق', $warning['message']);
    }

    public function testReadingAndResavingNeverReconverts(): void
    {
        $this->bootPlugin(false);
        do_action('admin_init');
        $this->loginAdmin();
        $this->submit(['default_commission_rate' => '12.34']);
        self::assertSame(1234, $this->stored()['default_commission_rate_bp']);
        State::$settingsErrors = [];
        $this->submit(['default_commission_rate' => '12.34']); // exactly what the page displays
        self::assertSame(1234, $this->stored()['default_commission_rate_bp'], 'would be 123400 if the stored value were re-parsed as percent');
        self::assertContains('tmc_saved', $this->errorCodes());
        self::assertCount(1, $this->audit->records, 'no change → no second audit row');
    }

    public function testEmptyAndZeroCommissionAreDistinctAfterSave(): void
    {
        $this->bootPlugin(false);
        do_action('admin_init');
        $this->loginAdmin();
        $this->submit(['default_commission_rate' => '0']);
        self::assertSame(0, $this->stored()['default_commission_rate_bp']);
        $this->submit(['default_commission_rate' => '']);
        self::assertNull($this->stored()['default_commission_rate_bp']);
        self::assertArrayHasKey('default_commission_rate_bp', $this->stored());
    }

    public function testSubmittedStoredKeyAndArraysAreRejectedSafely(): void
    {
        $this->bootPlugin(false);
        do_action('admin_init');
        $this->loginAdmin();
        $this->submit(['default_commission_rate_bp' => 9999, 'settlement_delay_days' => ['7'], 'max_staff' => '-3']);
        self::assertNull($this->stored()['default_commission_rate_bp']);
        self::assertSame(4, $this->stored()['settlement_delay_days']);
        self::assertSame(10, $this->stored()['max_staff']);
        self::assertContains('tmc_field_settlement_delay_days', $this->errorCodes());
        self::assertContains('tmc_field_max_staff', $this->errorCodes());
    }

    public function testReactivationMergeKeepsUserValues(): void
    {
        $this->bootPlugin(false);
        do_action('admin_init');
        $this->loginAdmin();
        $this->submit(['default_commission_rate' => '2.5', 'max_staff' => '33']);
        $service = \Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::container()->get(\Tecteb\Marketplace\Core\Config\SettingsService::class);
        self::assertTrue($service->ensureStored());
        self::assertSame(250, $this->stored()['default_commission_rate_bp']);
        self::assertSame(33, $this->stored()['max_staff']);
    }
}
