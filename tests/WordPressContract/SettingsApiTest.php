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
        self::assertNotContains('tmc_save_failed', $this->errorCodes(), 'a failed trail is not a failed save');
        $warning = array_values(array_filter(get_settings_errors(SettingsRegistrar::ERROR_SETTING), static fn ($e) => $e['code'] === 'tmc_audit_failed'))[0];
        self::assertSame('warning', $warning['type']);
        self::assertStringContainsString('ثبت رویداد ممیزی ناموفق', $warning['message']);
    }

    /**
     * Regression: the sanitize callback runs BEFORE the database write, so it
     * cannot know whether the save succeeded. The previous implementation
     * announced "saved" and wrote the audit row from inside sanitize, which
     * claimed success for a write that never happened.
     */
    public function testFailedWriteIsReportedAsFailureAndIsNeitherAuditedNorCalledSaved(): void
    {
        $this->bootPlugin(false);
        do_action('admin_init');
        $this->loginAdmin();
        $this->submit(['max_staff' => '15']);          // establishes a stored value
        State::$settingsErrors = [];
        $auditRows = count($this->audit->records);

        State::$failOptionWrites = true;               // the storage layer now refuses
        $this->submit(['max_staff' => '44']);

        self::assertSame(15, $this->stored()['max_staff'], 'the previous value is untouched');
        self::assertContains('tmc_save_failed', $this->errorCodes());
        self::assertNotContains('tmc_saved', $this->errorCodes());
        self::assertCount($auditRows, $this->audit->records, 'a change that never persisted is never audited');
        $failure = array_values(array_filter(get_settings_errors(SettingsRegistrar::ERROR_SETTING), static fn ($e) => $e['code'] === 'tmc_save_failed'))[0];
        self::assertSame('error', $failure['type']);
        self::assertStringContainsString('ناموفق', $failure['message']);
    }

    /** Regression: "nothing changed" must be its own visible outcome, not a save. */
    public function testUnchangedSubmissionIsReportedAsNoChangeAndIsNotAudited(): void
    {
        $this->bootPlugin(false);
        do_action('admin_init');
        $this->loginAdmin();
        $this->submit(['max_staff' => '15']);
        State::$settingsErrors = [];
        $auditRows = count($this->audit->records);

        $this->submit(['max_staff' => '15']);          // identical value

        self::assertContains('tmc_no_change', $this->errorCodes());
        self::assertNotContains('tmc_saved', $this->errorCodes());
        self::assertNotContains('tmc_save_failed', $this->errorCodes());
        self::assertCount($auditRows, $this->audit->records, 'no storage change, no audit row');
    }

    /** The audit row must describe the REAL persisted before/after, not the intent. */
    public function testAuditRecordsTheValuesThatWereActuallyStored(): void
    {
        $this->bootPlugin(false);
        do_action('admin_init');
        $this->loginAdmin();
        $this->submit(['default_commission_rate' => '5', 'max_staff' => '20']);
        $this->audit->records = [];
        State::$settingsErrors = [];

        // One valid field, one rejected: only the valid one reaches storage,
        // and only it may appear in the audit row.
        $this->submit(['default_commission_rate' => '999', 'max_staff' => '21']);

        self::assertSame(500, $this->stored()['default_commission_rate_bp'], 'rejected field keeps its previous value');
        self::assertSame(21, $this->stored()['max_staff']);
        self::assertCount(1, $this->audit->records);
        $payload = $this->audit->records[0]->payload;
        self::assertSame(['max_staff'], $payload['changed'], 'only the field that actually changed in storage');
        self::assertSame(['max_staff' => 20], $payload['old'], 'the real stored old value');
        self::assertSame(['max_staff' => 21], $payload['new']);
    }

    /**
     * Regression: recognising the WordPress double-sanitize pass by SHAPE
     * (schema_version + values) let a request POST that wrapper directly and
     * bypass validation and auditing. Recognition is now a one-shot token
     * bound to the exact value this callback just produced.
     */
    public function testForgedCanonicalPayloadCannotBypassValidationOrAudit(): void
    {
        $this->bootPlugin(false);
        do_action('admin_init');
        $this->loginAdmin();
        $this->submit(['max_staff' => '12']);
        $this->audit->records = [];
        State::$settingsErrors = [];

        // Exactly the internal wrapper, with values no validator would accept.
        $this->submit([
            'schema_version' => 1,
            'values' => [
                'default_commission_rate_bp' => 999999,
                'settlement_delay_days' => -5,
                'max_staff' => 100000,
                'environment_override' => 'production',
            ],
        ]);

        $stored = $this->stored();
        self::assertSame(12, $stored['max_staff'], 'forged wrapper must not reach storage');
        self::assertNotSame(999999, $stored['default_commission_rate_bp']);
        self::assertNotSame(-5, $stored['settlement_delay_days']);
        self::assertSame('auto', $stored['environment_override']);
        self::assertSame([], $this->audit->records, 'nothing changed, so nothing is audited');
    }

    /** The genuine WordPress double-sanitize on a NEW option still works. */
    public function testBrandNewOptionSurvivesWordPressDoubleSanitize(): void
    {
        $this->bootPlugin(false);
        do_action('admin_init');
        $this->loginAdmin();
        self::assertArrayNotHasKey(SettingsRegistrar::OPTION, State::$options, 'option does not exist yet');

        $this->submit(['default_commission_rate' => '12.34', 'max_staff' => '25']);

        self::assertSame(1234, $this->stored()['default_commission_rate_bp'], 'the second pass must not discard the first');
        self::assertSame(25, $this->stored()['max_staff']);
        self::assertContains('tmc_saved', $this->errorCodes());
        self::assertCount(1, $this->audit->records, 'exactly one audit row despite two sanitize passes');
    }

    /**
     * Regression: auditing used to happen inside resolveOutcome(), which only
     * runs on the options.php path (pre_set_transient_settings_errors). A
     * settings change written any other way — plugin code calling
     * update_option(), WP-CLI `wp option update` — reached the database with
     * no trail at all.
     *
     * register_setting() makes our sanitize callback the gate for EVERY write
     * to this option, so such a caller submits the same input field names the
     * form does; what it never does is run options.php, so finalizeOutcome()
     * is never called here.
     */
    public function testAuditHappensOnPersistenceEvenWhenFinalizeOutcomeNeverRuns(): void
    {
        $this->bootPlugin(false);
        do_action('admin_init');
        $this->loginAdmin();
        $this->submit(['max_staff' => '17']);
        $this->audit->records = [];
        State::$settingsErrors = [];
        State::$transients = [];

        // No options.php, no nonce, no settings-errors transient: a plain write.
        self::assertTrue(update_option(SettingsRegistrar::OPTION, ['max_staff' => '23']));

        self::assertSame(23, $this->stored()['max_staff'], 'the write reached storage');
        self::assertCount(1, $this->audit->records, 'the change is audited without finalizeOutcome()');
        $payload = $this->audit->records[0]->payload;
        self::assertSame(['max_staff'], $payload['changed']);
        self::assertSame(['max_staff' => 17], $payload['old']);
        self::assertSame(['max_staff' => 23], $payload['new']);
        self::assertArrayNotHasKey('settings_errors', State::$transients, 'options.php never ran');
        self::assertSame([], get_settings_errors(SettingsRegistrar::ERROR_SETTING), 'no outcome message on this path');
    }

    /** One persisted write produces exactly one audit row, not one per hook. */
    public function testASingleSaveProducesExactlyOneAuditRow(): void
    {
        $this->bootPlugin(false);
        do_action('admin_init');
        $this->loginAdmin();
        self::assertArrayNotHasKey(SettingsRegistrar::OPTION, State::$options);

        $this->submit(['max_staff' => '19']);   // new option: add + double sanitize

        self::assertCount(1, $this->audit->records);
        self::assertSame(19, $this->stored()['max_staff']);
    }

    /** A replayed wrapper is honoured at most once, and only right after we produced it. */
    public function testReplayTokenIsSingleUse(): void
    {
        $this->bootPlugin(false);
        do_action('admin_init');
        $this->loginAdmin();
        $this->submit(['max_staff' => '31']);
        $canonical = State::$options[SettingsRegistrar::OPTION];
        State::$settingsErrors = [];

        // Feeding our own previous output back later goes through validation:
        // it carries no form fields, so it is a no-op that keeps the values.
        $registrar = \Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::container()
            ->get(SettingsRegistrar::class);
        $this->audit->records = [];
        $out = $registrar->sanitize($canonical);
        self::assertSame(31, $out['values']['max_staff'], 'previous values preserved');
        self::assertSame([], $this->audit->records, 'validation no-op writes no audit row');
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
        self::assertContains('tmc_no_change', $this->errorCodes(), 'resubmitting the same value is not a save');
        self::assertNotContains('tmc_saved', $this->errorCodes());
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
