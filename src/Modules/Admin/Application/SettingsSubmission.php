<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Admin\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Config\SettingsService;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;

/**
 * Pure use case: authorise (capability, independent of any nonce) →
 * sanitise → audit the change. The WordPress Settings API glue lives in
 * Infrastructure\SettingsRegistrar.
 */
final class SettingsSubmission
{
    public function __construct(
        private SettingsService $settings,
        private AuditLogger $audit,
        private CapabilityCheckerInterface $caps
    ) {
    }

    public function canManage(): bool
    {
        return $this->caps->can(Capabilities::MANAGE_SETTINGS);
    }

    /** @param array<string,mixed> $raw */
    public function handle(array $raw): SubmissionResult
    {
        $current = $this->settings->load();
        if (!$this->caps->can(Capabilities::MANAGE_SETTINGS)) {
            return new SubmissionResult(false, $current, [], [], null);
        }
        $outcome = $this->settings->sanitizeSubmission($raw, $current);
        $audit = null;
        if ($outcome->hasChanges()) {
            $old = [];
            $new = [];
            foreach ($outcome->changes as $key => $pair) {
                $old[$key] = $pair['old'];
                $new[$key] = $pair['new'];
            }
            $audit = $this->audit->log(
                AuditEventCatalog::SETTINGS_UPDATED,
                $this->caps->currentUserId(),
                'settings',
                'tmc_settings',
                ['changed' => array_keys($outcome->changes), 'old' => $old, 'new' => $new]
            );
        }
        return new SubmissionResult(true, $outcome->settings, $outcome->errors, $outcome->changes, $audit);
    }
}
