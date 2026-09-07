<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Admin\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Audit\AuditResult;
use Tecteb\Marketplace\Core\Config\Settings;
use Tecteb\Marketplace\Core\Config\SettingsSchema;
use Tecteb\Marketplace\Core\Config\SettingsService;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;

/**
 * Two separate use cases, deliberately not one:
 *
 *  - validate(): authorise and sanitise a submission. Runs in the sanitize
 *    callback, BEFORE the database write. Records nothing.
 *  - auditPersisted(): write the audit row. Runs only after WordPress has
 *    confirmed the value was stored, and takes the REAL before/after values
 *    read back from the option, not the intended ones.
 *
 * Merging them would audit changes that were never saved and would report
 * success for a write that failed (CORE-07).
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

    /** @param array<string,mixed> $raw submitted form values */
    public function validate(array $raw): PendingSettingsChange
    {
        $current = $this->settings->load();
        if (!$this->canManage()) {
            return new PendingSettingsChange(false, $current, $current, [], []);
        }
        $outcome = $this->settings->sanitizeSubmission($raw, $current);
        return new PendingSettingsChange(true, $current, $outcome->settings, $outcome->errors, $outcome->changes);
    }

    /**
     * Audits an ALREADY PERSISTED change. $before and $after are the values
     * WordPress reported for the option itself, so the row can never describe
     * a change that did not reach the database.
     */
    public function auditPersisted(Settings $before, Settings $after): ?AuditResult
    {
        $changed = [];
        $old = [];
        $new = [];
        foreach (SettingsSchema::storedKeys() as $key) {
            $b = $before->values()[$key];
            $a = $after->values()[$key];
            if ($b !== $a) {
                $changed[] = $key;
                $old[$key] = $b;
                $new[$key] = $a;
            }
        }
        if ($changed === []) {
            return null; // nothing actually changed in storage: nothing to audit
        }
        return $this->audit->log(
            AuditEventCatalog::SETTINGS_UPDATED,
            $this->caps->currentUserId(),
            'settings',
            SettingsSchema::OPTION_KEY,
            ['changed' => $changed, 'old' => $old, 'new' => $new]
        );
    }
}
