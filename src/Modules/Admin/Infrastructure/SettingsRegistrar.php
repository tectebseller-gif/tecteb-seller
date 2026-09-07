<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Admin\Infrastructure;

use Tecteb\Marketplace\Core\Audit\AuditResult;
use Tecteb\Marketplace\Core\Config\Settings;
use Tecteb\Marketplace\Core\Config\SettingsSchema;
use Tecteb\Marketplace\Core\Config\SettingsService;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Admin\Application\PendingSettingsChange;
use Tecteb\Marketplace\Modules\Admin\Application\SettingsSubmission;
use Tecteb\Marketplace\Modules\Admin\Presentation\Messages;

/**
 * Settings API glue (CORE-07 / CORE-03), split along the real event boundary.
 *
 *  sanitize()            validation only. Runs BEFORE the write and therefore
 *                        never claims success and never audits.
 *  onOptionUpdated/Added WordPress confirms the value reached the database.
 *                        The audit row is written HERE, from the REAL old and
 *                        new option values — not later. A settings change
 *                        that reaches storage is audited even when nothing
 *                        else in the request runs: options.php is only one of
 *                        the ways the option can be written.
 *  finalizeOutcome()     runs after options.php has finished all writes and
 *                        turns the observed facts into exactly one message:
 *                        saved / nothing changed / SAVE FAILED / audit failed.
 *                        It REPORTS; it does not decide whether to audit.
 *
 * The four outcomes are distinguishable by design: a failed write must not
 * look like a save, and an unchanged submission must not look like one either.
 *
 * Authorisation: options.php checks the page capability (which the filter
 * below sets to tmc_manage_settings) after verifying the nonce; sanitize()
 * re-checks it, so a valid nonce without the capability still changes nothing.
 */
final class SettingsRegistrar
{
    public const GROUP = 'tmc_settings_group';
    public const OPTION = SettingsSchema::OPTION_KEY;
    public const ERROR_SETTING = 'tmc_settings';

    private ?PendingSettingsChange $pending = null;

    /** Exact output of the last sanitize() call; a ONE-SHOT replay marker. */
    private ?string $replayToken = null;

    private ?Settings $persistedBefore = null;
    private ?Settings $persistedAfter = null;
    private bool $outcomeReported = false;

    /** Result of the audit performed at persistence time, if any. */
    private ?AuditResult $auditResult = null;
    private bool $audited = false;

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
        // Fired by WordPress only when the value actually reached the database.
        add_action('update_option_' . self::OPTION, [$this, 'onOptionUpdated'], 10, 3);
        add_action('add_option_' . self::OPTION, [$this, 'onOptionAdded'], 10, 2);
        // Fired once, after options.php has written every option of the page.
        add_filter('pre_set_transient_settings_errors', [$this, 'finalizeOutcome'], 10, 1);
    }

    /**
     * Validation only. Returns the array WordPress should store.
     *
     * @return array<string,mixed> stored shape
     */
    public function sanitize(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];

        // WordPress sanitises a brand-new option TWICE: update_option() runs
        // this callback and hands the result to add_option(), which runs it
        // again. The second pass receives EXACTLY this method's own output.
        //
        // That replay is recognised by comparing against a one-shot token
        // captured when we produced it — never by guessing from the array's
        // shape. Shape alone is forgeable: a request could POST the canonical
        // wrapper and skip validation and auditing entirely. Anything that is
        // not the exact value we just returned goes through full validation,
        // where unknown keys are ignored and the previous values are kept.
        if ($this->replayToken !== null && hash_equals($this->replayToken, self::fingerprint($raw))) {
            $this->replayToken = null; // consume: only one replay is honoured
            return $raw;
        }
        $this->replayToken = null;

        // A new submission starts a new outcome. WordPress gives each request
        // a fresh instance, but nothing guarantees one save per request, and
        // a stale latch would silently swallow the second outcome.
        $this->pending = null;
        $this->persistedBefore = null;
        $this->persistedAfter = null;
        $this->outcomeReported = false;
        $this->auditResult = null;
        $this->audited = false;

        $pending = $this->submission->validate($raw);
        $this->pending = $pending;

        if (!$pending->authorized) {
            add_settings_error(self::ERROR_SETTING, 'tmc_forbidden', Messages::forbidden(), 'error');
            return $this->settings->load()->toStored();
        }
        foreach ($pending->errors as $field => $code) {
            add_settings_error(self::ERROR_SETTING, 'tmc_field_' . $field, Messages::fieldError($field, $code), 'error');
        }

        $stored = $pending->candidate->toStored();
        $this->replayToken = self::fingerprint($stored);
        return $stored;
    }

    /** @param mixed $old @param mixed $new */
    public function onOptionUpdated($old, $new, string $option = self::OPTION): void
    {
        $this->recordPersisted(Settings::fromStored($old), Settings::fromStored($new));
    }

    /** @param mixed $new */
    public function onOptionAdded(string $option, $new): void
    {
        // The option did not exist, so the effective previous state was the
        // defaults the plugin behaves as when nothing is stored.
        $this->recordPersisted(Settings::fromStored(null), Settings::fromStored($new));
    }

    /**
     * Turns what actually happened into one message, and audits only a change
     * that reached the database.
     *
     * @param array<int,array<string,mixed>> $errors
     * @return array<int,array<string,mixed>>
     */
    public function finalizeOutcome(array $errors): array
    {
        $entry = $this->resolveOutcome();
        if ($entry !== null) {
            $errors[] = $entry;
        }
        return $errors;
    }

    /** @return array<string,mixed>|null a settings-error entry, or null when there is nothing to say */
    public function resolveOutcome(): ?array
    {
        if ($this->outcomeReported || $this->pending === null) {
            return null;
        }
        $this->outcomeReported = true;
        $pending = $this->pending;

        if (!$pending->authorized) {
            return null; // the forbidden message was already added during sanitize
        }

        $persisted = $this->persistedAfter !== null;

        if ($persisted) {
            // Already attempted at persistence time; here we only report it.
            $audit = $this->auditResult;
            if ($audit !== null && !$audit->ok) {
                // The values ARE saved; the trail is not. Say both.
                return self::entry('tmc_audit_failed', Messages::auditFailed(), 'warning');
            }
            if ($pending->hasErrors()) {
                // Some fields were rejected and kept their previous values,
                // while the accepted ones were saved. The field errors are
                // already on screen; do not add a success message on top.
                return null;
            }
            return self::entry('tmc_saved', Messages::saved(), 'success');
        }

        if ($pending->hasErrors() && !$pending->intendsChange()) {
            return null; // nothing valid to save; the field errors say why
        }
        if (!$pending->intendsChange()) {
            return self::entry('tmc_no_change', Messages::savedNoChange(), 'info');
        }
        // A change was intended, validation accepted it, and WordPress never
        // confirmed the write: the option was NOT saved.
        return self::entry('tmc_save_failed', Messages::saveFailed(), 'error');
    }

    /**
     * Called the moment WordPress confirms the option was stored. Auditing
     * happens right here, not in finalizeOutcome(): the outcome filter only
     * runs on the options.php path, and a change that reached the database
     * by any other route must still leave a trail.
     */
    private function recordPersisted(Settings $before, Settings $after): void
    {
        $this->persistedBefore = $before;
        $this->persistedAfter = $after;
        if ($this->audited) {
            return; // one write, one audit row
        }
        $this->audited = true;
        $this->auditResult = $this->submission->auditPersisted($before, $after);
    }

    /** @return array<string,mixed> */
    private static function entry(string $code, string $message, string $type): array
    {
        add_settings_error(self::ERROR_SETTING, $code, $message, $type);
        return ['setting' => self::ERROR_SETTING, 'code' => $code, 'message' => $message, 'type' => $type];
    }

    /** @param array<string,mixed> $value */
    private static function fingerprint(array $value): string
    {
        return hash('sha256', (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
}
