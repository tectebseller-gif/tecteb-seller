<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Admin\Infrastructure;

use Tecteb\Marketplace\Core\Config\Settings;
use Tecteb\Marketplace\Core\Config\SettingsSchema;
use Tecteb\Marketplace\Core\Config\SettingsService;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Admin\Application\SettingsSubmission;
use Tecteb\Marketplace\Modules\Admin\Presentation\Messages;

/**
 * Settings API glue (CORE-07 / CORE-03).
 *  - options.php verifies the nonce (check_admin_referer) and the page
 *    capability, which we set to tmc_manage_settings via the filter below
 *  - the sanitize callback re-checks the capability itself: a valid nonce
 *    without the capability changes nothing
 *  - invalid fields keep their previous value and get a Persian message
 *  - an audit failure after a successful save is reported, never silent
 */
final class SettingsRegistrar
{
    public const GROUP = 'tmc_settings_group';
    public const OPTION = SettingsSchema::OPTION_KEY;
    public const ERROR_SETTING = 'tmc_settings';

    public function __construct(private SettingsSubmission $submission, private SettingsService $settings)
    {
    }

    public function register(): void
    {
        register_setting(self::GROUP, self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'show_in_rest' => false,
            'default' => $this->settings->load()->toStored(),
        ]);
        add_filter('option_page_capability_' . self::GROUP, static fn () => Capabilities::MANAGE_SETTINGS);
    }

    /** @return array<string,mixed> stored shape */
    public function sanitize(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];

        // WordPress sanitises a brand-new option TWICE: update_option() runs
        // this callback, then hands the result to add_option(), which runs it
        // again. The second pass receives this method's own output, which
        // carries no form fields. Re-validate that shape and return it instead
        // of re-deriving from an option row that has not been written yet
        // (which would silently discard the values just submitted).
        if ($this->isCanonicalShape($raw)) {
            if (!$this->submission->canManage()) {
                add_settings_error(self::ERROR_SETTING, 'tmc_forbidden', Messages::forbidden(), 'error');
                return $this->settings->load()->toStored();
            }
            return Settings::fromStored($raw)->toStored();
        }

        $result = $this->submission->handle($raw);
        if (!$result->authorized) {
            add_settings_error(self::ERROR_SETTING, 'tmc_forbidden', Messages::forbidden(), 'error');
            return $this->settings->load()->toStored();
        }
        foreach ($result->errors as $field => $code) {
            add_settings_error(self::ERROR_SETTING, 'tmc_field_' . $field, Messages::fieldError($field, $code), 'error');
        }
        if ($result->auditFailed()) {
            add_settings_error(self::ERROR_SETTING, 'tmc_audit_failed', Messages::auditFailed(), 'warning');
        }
        if ($result->errors === [] && !$result->auditFailed()) {
            add_settings_error(
                self::ERROR_SETTING,
                'tmc_saved',
                $result->changes === [] ? Messages::savedNoChange() : Messages::saved(),
                'success'
            );
        }
        return $result->settings->toStored();
    }

    /**
     * True when the array is this callback's own output rather than a form
     * submission: the canonical wrapper, and no form field at the top level.
     * A forged POST of that shape still goes through Settings::fromStored(),
     * which range-checks every key and falls back to defaults.
     *
     * @param array<string,mixed> $raw
     */
    private function isCanonicalShape(array $raw): bool
    {
        if (!isset($raw['schema_version'], $raw['values']) || !is_array($raw['values'])) {
            return false;
        }
        foreach (SettingsSchema::inputKeys() as $key) {
            if (array_key_exists($key, $raw)) {
                return false;
            }
        }
        return true;
    }
}
