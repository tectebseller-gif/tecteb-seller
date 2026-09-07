<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Admin\Presentation\Pages;

use Tecteb\Marketplace\Core\Config\BasisPoints;
use Tecteb\Marketplace\Core\Config\SettingsSchema;
use Tecteb\Marketplace\Core\Config\SettingsService;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Admin\Infrastructure\SettingsRegistrar;
use Tecteb\Marketplace\Modules\Admin\Presentation\View;

final class SettingsPage extends AbstractPage
{
    public const SLUG = 'tmc-settings';
    public const CAPABILITY = Capabilities::MANAGE_SETTINGS;

    public static function menuLabel(): string
    {
        return __('تنظیمات', 'tecteb-marketplace-core');
    }

    public static function pageTitle(): string
    {
        return __('تنظیمات بازارگاه', 'tecteb-marketplace-core');
    }

    protected function renderAuthorized(): void
    {
        /** @var SettingsService $service */
        $service = $this->container->get(SettingsService::class);
        $settings = $service->load();

        $errors = [];
        $successes = [];
        $warnings = [];
        foreach (get_settings_errors(SettingsRegistrar::ERROR_SETTING) as $e) {
            $field = str_starts_with($e['code'], 'tmc_field_') ? substr($e['code'], strlen('tmc_field_')) : null;
            if ($e['type'] === 'success') {
                $successes[] = $e['message'];
            } elseif ($e['type'] === 'warning' || $e['type'] === 'info') {
                $warnings[] = $e['message'];
            } else {
                $errors[] = ['field' => $field, 'message' => $e['message'], 'href' => $field ? '#tmc-field-' . $field : '#tmc-error-summary'];
            }
        }
        $invalidFields = array_values(array_filter(array_map(static fn ($e) => $e['field'], $errors)));

        View::render('settings', [
            'group' => SettingsRegistrar::GROUP,
            'option' => SettingsRegistrar::OPTION,
            'action' => admin_url('options.php'),
            'errors' => $errors,
            'successes' => $successes,
            'warnings' => $warnings,
            'invalid' => $invalidFields,
            'commission_input' => $settings->commissionRateBp === null ? '' : BasisPoints::toPercentString($settings->commissionRateBp),
            'commission_state' => $settings->commissionRateBp === null ? 'unset' : ($settings->commissionRateBp === 0 ? 'zero' : 'set'),
            'settlement_delay_days' => $settings->settlementDelayDays,
            'max_staff' => $settings->maxStaff,
            'environment_override' => $settings->environmentOverride,
            'bounds' => [
                'delay_min' => SettingsSchema::SETTLEMENT_DELAY_MIN,
                'delay_max' => SettingsSchema::SETTLEMENT_DELAY_PROPOSED_TECHNICAL_MAX,
                'staff_min' => SettingsSchema::MAX_STAFF_MIN,
                'staff_max' => SettingsSchema::MAX_STAFF_PROPOSED_TECHNICAL_MAX,
            ],
            'fields' => [
                'commission' => SettingsSchema::INPUT_COMMISSION_PERCENT,
                'delay' => SettingsSchema::INPUT_SETTLEMENT_DELAY_DAYS,
                'staff' => SettingsSchema::INPUT_MAX_STAFF,
                'environment' => SettingsSchema::INPUT_ENVIRONMENT_OVERRIDE,
            ],
            'environment_values' => SettingsSchema::ENVIRONMENT_OVERRIDE_VALUES,
        ]);
    }
}
