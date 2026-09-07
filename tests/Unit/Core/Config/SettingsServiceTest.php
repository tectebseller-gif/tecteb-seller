<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Core\Config\BasisPoints;
use Tecteb\Marketplace\Core\Config\Sanitizer\BoundedInteger;
use Tecteb\Marketplace\Core\Config\Sanitizer\PercentToBasisPoints;
use Tecteb\Marketplace\Core\Config\Settings;
use Tecteb\Marketplace\Core\Config\SettingsSchema;
use Tecteb\Marketplace\Core\Config\SettingsService;
use Tecteb\Marketplace\Tests\Support\InMemoryOptionStore;

/** CORE-07 behaviour: defaults, reject-keeps-previous, no double conversion, reactivation safety. */
final class SettingsServiceTest extends TestCase
{
    private InMemoryOptionStore $options;
    private SettingsService $service;

    protected function setUp(): void
    {
        $this->options = new InMemoryOptionStore();
        $this->service = new SettingsService($this->options);
    }

    public function testDefaultsWhenNothingStored(): void
    {
        $s = $this->service->load();
        self::assertNull($s->commissionRateBp, 'commission is "not set" by default, not zero');
        self::assertSame(4, $s->settlementDelayDays);
        self::assertSame(10, $s->maxStaff);
        self::assertSame('auto', $s->environmentOverride);
    }

    public function testValidSubmissionIsStoredInCanonicalFormWithChanges(): void
    {
        $out = $this->service->sanitizeSubmission([
            SettingsSchema::INPUT_COMMISSION_PERCENT => '12.34',
            SettingsSchema::INPUT_SETTLEMENT_DELAY_DAYS => '۷',
            SettingsSchema::INPUT_MAX_STAFF => '25',
            SettingsSchema::INPUT_ENVIRONMENT_OVERRIDE => 'staging',
        ], $this->service->load());
        self::assertFalse($out->hasErrors());
        self::assertSame(1234, $out->settings->commissionRateBp);
        self::assertSame(7, $out->settings->settlementDelayDays);
        self::assertSame(25, $out->settings->maxStaff);
        self::assertSame('staging', $out->settings->environmentOverride);
        self::assertSame(['old' => null, 'new' => 1234], $out->changes[SettingsSchema::KEY_COMMISSION_BP]);
        self::assertTrue($this->service->save($out->settings));
        self::assertSame(['schema_version' => 1, 'values' => [
            'default_commission_rate_bp' => 1234,
            'settlement_delay_days' => 7,
            'max_staff' => 25,
            'environment_override' => 'staging',
        ]], $this->options->data[SettingsSchema::OPTION_KEY]);
    }

    public function testInvalidFieldIsRejectedAndKeepsPreviousValueWhileOthersApply(): void
    {
        $current = Settings::defaults()->withCommissionRateBp(500);
        $out = $this->service->sanitizeSubmission([
            SettingsSchema::INPUT_COMMISSION_PERCENT => '100.01',
            SettingsSchema::INPUT_MAX_STAFF => '12',
        ], $current);
        self::assertTrue($out->hasErrors());
        self::assertSame(PercentToBasisPoints::ERR_RANGE, $out->errors[SettingsSchema::INPUT_COMMISSION_PERCENT]);
        self::assertSame(500, $out->settings->commissionRateBp, 'previous value kept');
        self::assertSame(12, $out->settings->maxStaff, 'valid sibling still applied');
        self::assertArrayNotHasKey(SettingsSchema::KEY_COMMISSION_BP, $out->changes);
    }

    public function testNullAndZeroCommissionAreStoredDistinctly(): void
    {
        $unset = $this->service->sanitizeSubmission([SettingsSchema::INPUT_COMMISSION_PERCENT => ''], Settings::defaults()->withCommissionRateBp(100));
        $zero = $this->service->sanitizeSubmission([SettingsSchema::INPUT_COMMISSION_PERCENT => '0'], Settings::defaults()->withCommissionRateBp(100));
        self::assertNull($unset->settings->commissionRateBp);
        self::assertSame(0, $zero->settings->commissionRateBp);
        self::assertSame(['old' => 100, 'new' => null], $unset->changes[SettingsSchema::KEY_COMMISSION_BP]);
        self::assertSame(['old' => 100, 'new' => 0], $zero->changes[SettingsSchema::KEY_COMMISSION_BP]);
        $this->service->save($unset->settings);
        self::assertNull($this->service->load()->commissionRateBp);
        $this->service->save($zero->settings);
        self::assertSame(0, $this->service->load()->commissionRateBp);
    }

    public function testReadingAndResavingNeverReconverts(): void
    {
        $first = $this->service->sanitizeSubmission([SettingsSchema::INPUT_COMMISSION_PERCENT => '12.34'], $this->service->load());
        $this->service->save($first->settings);
        $loaded = $this->service->load();
        self::assertSame(1234, $loaded->commissionRateBp);
        $displayed = BasisPoints::toPercentString($loaded->commissionRateBp);
        self::assertSame('12.34', $displayed);
        // The form re-submits exactly what it displayed:
        $second = $this->service->sanitizeSubmission([SettingsSchema::INPUT_COMMISSION_PERCENT => $displayed], $loaded);
        self::assertSame(1234, $second->settings->commissionRateBp, 'no double conversion (would be 123400)');
        self::assertFalse($second->hasChanges());
        $this->service->save($second->settings);
        self::assertSame(1234, $this->service->load()->commissionRateBp);
    }

    public function testSubmittedStoredKeyIsIgnored(): void
    {
        $out = $this->service->sanitizeSubmission([SettingsSchema::KEY_COMMISSION_BP => 9999], Settings::defaults());
        self::assertFalse($out->hasErrors());
        self::assertNull($out->settings->commissionRateBp, 'stored key is not an input; never read from the form');
    }

    public function testSettlementDelayRangeIncludesZeroAndRejectsAboveProposedBound(): void
    {
        $zero = $this->service->sanitizeSubmission([SettingsSchema::INPUT_SETTLEMENT_DELAY_DAYS => '0'], Settings::defaults());
        self::assertSame(0, $zero->settings->settlementDelayDays);
        $tooBig = $this->service->sanitizeSubmission([SettingsSchema::INPUT_SETTLEMENT_DELAY_DAYS => '366'], Settings::defaults());
        self::assertSame(BoundedInteger::ERR_RANGE, $tooBig->errors[SettingsSchema::INPUT_SETTLEMENT_DELAY_DAYS]);
        self::assertSame(4, $tooBig->settings->settlementDelayDays);
        $neg = $this->service->sanitizeSubmission([SettingsSchema::INPUT_SETTLEMENT_DELAY_DAYS => '-1'], Settings::defaults());
        self::assertSame(BoundedInteger::ERR_RANGE, $neg->errors[SettingsSchema::INPUT_SETTLEMENT_DELAY_DAYS]);
    }

    public function testMaxStaffRange(): void
    {
        self::assertSame(BoundedInteger::ERR_RANGE, $this->service->sanitizeSubmission([SettingsSchema::INPUT_MAX_STAFF => '0'], Settings::defaults())->errors[SettingsSchema::INPUT_MAX_STAFF]);
        self::assertSame(100, $this->service->sanitizeSubmission([SettingsSchema::INPUT_MAX_STAFF => '100'], Settings::defaults())->settings->maxStaff);
        self::assertSame(BoundedInteger::ERR_RANGE, $this->service->sanitizeSubmission([SettingsSchema::INPUT_MAX_STAFF => '101'], Settings::defaults())->errors[SettingsSchema::INPUT_MAX_STAFF]);
    }

    public function testEnvironmentOverrideValues(): void
    {
        self::assertSame('production', $this->service->sanitizeSubmission([SettingsSchema::INPUT_ENVIRONMENT_OVERRIDE => 'Production'], Settings::defaults())->settings->environmentOverride);
        $bad = $this->service->sanitizeSubmission([SettingsSchema::INPUT_ENVIRONMENT_OVERRIDE => 'development'], Settings::defaults());
        self::assertSame('environment_value', $bad->errors[SettingsSchema::INPUT_ENVIRONMENT_OVERRIDE]);
        self::assertSame('auto', $bad->settings->environmentOverride);
    }

    public function testReactivationDoesNotResetUserValues(): void
    {
        $this->options->data[SettingsSchema::OPTION_KEY] = ['schema_version' => 1, 'values' => ['default_commission_rate_bp' => 250, 'settlement_delay_days' => 9]];
        self::assertTrue($this->service->ensureStored());
        $s = $this->service->load();
        self::assertSame(250, $s->commissionRateBp);
        self::assertSame(9, $s->settlementDelayDays);
        self::assertSame(10, $s->maxStaff, 'missing key filled with default');
        $writes = $this->options->writes;
        self::assertTrue($this->service->ensureStored());
        self::assertSame($writes, $this->options->writes, 'second activation writes nothing');
    }

    public function testFreshActivationStoresDefaultsOnce(): void
    {
        self::assertTrue($this->service->ensureStored());
        self::assertSame(Settings::defaults()->toStored(), $this->options->data[SettingsSchema::OPTION_KEY]);
    }

    public function testCorruptedStoredValuesFallBackPerKey(): void
    {
        $s = Settings::fromStored(['values' => ['default_commission_rate_bp' => '12.34', 'settlement_delay_days' => 4000, 'max_staff' => 'ten', 'environment_override' => 'mars']]);
        self::assertNull($s->commissionRateBp, 'a percent string in the stored slot is never reinterpreted');
        self::assertSame(4, $s->settlementDelayDays);
        self::assertSame(10, $s->maxStaff);
        self::assertSame('auto', $s->environmentOverride);
        self::assertSame(Settings::defaults()->toStored(), Settings::fromStored('garbage')->toStored());
    }
}
