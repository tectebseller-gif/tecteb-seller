<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

/**
 * The twelve steps of a site migration, judged from what is actually stored.
 *
 * ### It is a checklist, not a wizard that owns anything
 *
 * Every step points at the page that already does that work, and this class
 * writes NOTHING — it has no repository, no option store and no setter. The
 * reason is the one `SetupChecklist` gives: a wizard with its own storage is a
 * second place for the same fact to live, and the two drift. So a step is done
 * because the underlying service says so, and a step that was done and then
 * undone becomes incomplete again. Progress that can only move forwards is
 * progress that lies eventually.
 *
 * ⛔ **And «نمی‌دانم» is a fourth answer, not a kind of «no».** A read that
 * failed, a plugin whose version could not be determined, a shop whose scan
 * hit its ceiling — each answers `UNKNOWN`, and `UNKNOWN` never counts towards
 * «آماده». A wizard that reported «نشده» for something it could not measure
 * would send a manager to fix a step that may already be finished; one that
 * reported «شده» would be worse. This is the `alpha.68` rule with twelve
 * steps hanging off it.
 *
 * ### Pure
 *
 * No WordPress, no database, no reader. The state arrives as one snapshot and
 * the Persian wording is chosen in Presentation, so every branch here is
 * reachable from a unit test with an array.
 */
final class MigrationPlanChecklist
{
    public const DONE = 'done';
    public const TODO = 'todo';
    public const BLOCKED = 'blocked';
    public const UNKNOWN = 'unknown';

    public const STEP_ENVIRONMENT = 'environment';
    public const STEP_PLUGINS = 'plugins';
    public const STEP_BACKUP = 'backup';
    public const STEP_INVENTORY = 'inventory';
    public const STEP_DRY_RUN = 'dry_run';
    public const STEP_DIFFERENCES = 'differences';
    public const STEP_SYNC = 'sync';
    public const STEP_PLAN = 'plan';
    public const STEP_CONSENT = 'consent';
    public const STEP_TRANSFER = 'transfer';
    public const STEP_VALIDATE = 'validate';
    public const STEP_EXIT_READY = 'exit_ready';

    /** @var list<string> the order a manager works through them */
    public const ORDER = [
        self::STEP_ENVIRONMENT,
        self::STEP_PLUGINS,
        self::STEP_BACKUP,
        self::STEP_INVENTORY,
        self::STEP_DRY_RUN,
        self::STEP_DIFFERENCES,
        self::STEP_SYNC,
        self::STEP_PLAN,
        self::STEP_CONSENT,
        self::STEP_TRANSFER,
        self::STEP_VALIDATE,
        self::STEP_EXIT_READY,
    ];

    /** The lowest PHP this package declares it runs on. */
    public const PHP_FLOOR = '8.1';

    /**
     * @param array{
     *   php_version?:string,
     *   platform_version?:?string,
     *   woocommerce_active?:bool,
     *   woocommerce_version?:?string,
     *   dokan_running?:?bool,
     *   schema_version?:int,
     *   schema_target?:int,
     *   rollback?:string,
     *   shops?:?int,
     *   observed?:?int,
     *   marketplace?:?int,
     *   scan_finished?:bool,
     *   ready?:?int,
     *   drift_fixable?:?int,
     *   risk_real?:?int,
     *   status_repairable?:?int,
     *   transfer_runs?:?int,
     *   gates_missing?:?list<string>
     * } $state
     * @return list<array{key:string, status:string, blocker:string, detail:string}>
     */
    public static function steps(array $state): array
    {
        $steps = [];
        foreach (self::ORDER as $key) {
            [$status, $blocker, $detail] = self::judge($key, $state);
            $steps[] = ['key' => $key, 'status' => $status, 'blocker' => $blocker, 'detail' => $detail];
        }
        return $steps;
    }

    /**
     * How many of the twelve are finished, and whether anything is unknown.
     *
     * ⛔ `ready` is true only when every step is `DONE`. Not «no step is
     * blocked» — an `UNKNOWN` step is a step nobody measured, and a migration
     * declared ready on an unmeasured step is the sentence this whole package
     * refuses to print.
     *
     * @param list<array{key:string, status:string, blocker:string, detail:string}> $steps
     * @return array{done:int, todo:int, blocked:int, unknown:int, total:int, ready:bool}
     */
    public static function tally(array $steps): array
    {
        $counts = [self::DONE => 0, self::TODO => 0, self::BLOCKED => 0, self::UNKNOWN => 0];
        foreach ($steps as $step) {
            $status = (string) $step['status'];
            if (array_key_exists($status, $counts)) {
                $counts[$status]++;
            }
        }
        return [
            'done' => $counts[self::DONE],
            'todo' => $counts[self::TODO],
            'blocked' => $counts[self::BLOCKED],
            'unknown' => $counts[self::UNKNOWN],
            'total' => count($steps),
            'ready' => count($steps) > 0 && $counts[self::DONE] === count($steps),
        ];
    }

    /**
     * @param array<string,mixed> $state
     * @return array{0:string, 1:string, 2:string}
     */
    private static function judge(string $key, array $state): array
    {
        return match ($key) {
            self::STEP_ENVIRONMENT => self::environment($state),
            self::STEP_PLUGINS => self::plugins($state),
            self::STEP_BACKUP => self::backup($state),
            self::STEP_INVENTORY => self::inventory($state),
            self::STEP_DRY_RUN => self::dryRun($state),
            self::STEP_DIFFERENCES => self::differences($state),
            self::STEP_SYNC => self::sync($state),
            self::STEP_PLAN => self::plan($state),
            self::STEP_CONSENT => self::consent($state),
            self::STEP_TRANSFER => self::transfer($state),
            self::STEP_VALIDATE => self::validate($state),
            self::STEP_EXIT_READY => self::exitReady($state),
            default => [self::UNKNOWN, 'step_not_listed', ''],
        };
    }

    /** @param array<string,mixed> $state @return array{0:string, 1:string, 2:string} */
    private static function environment(array $state): array
    {
        $php = trim((string) ($state['php_version'] ?? ''));
        $platform = $state['platform_version'] ?? null;
        if ($php === '' || $platform === null || trim((string) $platform) === '') {
            return [self::UNKNOWN, 'version_unreadable', $php];
        }
        if (version_compare($php, self::PHP_FLOOR, '<')) {
            return [self::BLOCKED, 'php_too_old', $php];
        }
        // ⛔ The schema has to be AT the target, and «ahead» is not «at».
        // A site whose structure is ahead of this package is a site somebody
        // rolled back, and reading it as ready would plan a migration against
        // columns this code does not know.
        $version = (int) ($state['schema_version'] ?? 0);
        $target = (int) ($state['schema_target'] ?? 0);
        if ($target <= 0) {
            return [self::UNKNOWN, 'schema_unreadable', $php];
        }
        if ($version !== $target) {
            return [self::BLOCKED, 'schema_behind', $version . '/' . $target];
        }
        return [self::DONE, '', $php];
    }

    /** @param array<string,mixed> $state @return array{0:string, 1:string, 2:string} */
    private static function plugins(array $state): array
    {
        if (($state['woocommerce_active'] ?? false) !== true) {
            return [self::BLOCKED, 'woocommerce_missing', ''];
        }
        $version = $state['woocommerce_version'] ?? null;
        if ($version === null || trim((string) $version) === '') {
            return [self::UNKNOWN, 'woocommerce_version_unreadable', ''];
        }
        $dokan = $state['dokan_running'] ?? null;
        if ($dokan === null) {
            return [self::UNKNOWN, 'dokan_state_unreadable', (string) $version];
        }
        // Dokan RUNNING is the healthy state for this step, and that is not a
        // typo: the records path, the finance archive and the category source
        // can only be read while it answers. A migration planned after Dokan
        // is gone is a migration whose source has already left.
        return $dokan === true
            ? [self::DONE, '', (string) $version]
            : [self::BLOCKED, 'dokan_not_running', (string) $version];
    }

    /** @param array<string,mixed> $state @return array{0:string, 1:string, 2:string} */
    private static function backup(array $state): array
    {
        return match ((string) ($state['rollback'] ?? '')) {
            'safe' => [self::DONE, '', 'safe'],
            'blocked' => [self::BLOCKED, 'rollback_would_lose_data', 'blocked'],
            // Anything else — including the gate's own `unknown` — is not
            // «safe». A backup whose restorability nobody measured is not a
            // backup anybody should migrate behind.
            default => [self::UNKNOWN, 'rollback_unknown', ''],
        };
    }

    /** @param array<string,mixed> $state @return array{0:string, 1:string, 2:string} */
    private static function inventory(array $state): array
    {
        $shops = $state['shops'] ?? null;
        if ($shops === null || (int) $shops < 0) {
            return [self::UNKNOWN, 'inventory_unreadable', ''];
        }
        return (int) $shops > 0
            ? [self::DONE, '', (string) (int) $shops]
            : [self::TODO, 'no_shop_identified', '0'];
    }

    /** @param array<string,mixed> $state @return array{0:string, 1:string, 2:string} */
    private static function dryRun(array $state): array
    {
        $runs = $state['transfer_runs'] ?? null;
        if ($runs === null || (int) $runs < 0) {
            return [self::UNKNOWN, 'runs_unreadable', ''];
        }
        // A recorded import run IS the dry run having been taken and acted on:
        // `ImportFromDokan` cannot write a run without a plan.
        return (int) $runs > 0
            ? [self::DONE, '', (string) (int) $runs]
            : [self::TODO, 'no_run_recorded', '0'];
    }

    /** @param array<string,mixed> $state @return array{0:string, 1:string, 2:string} */
    private static function differences(array $state): array
    {
        if (($state['scan_finished'] ?? null) !== true) {
            return [self::UNKNOWN, 'scan_did_not_finish', ''];
        }
        foreach (['ready', 'drift_fixable', 'risk_real'] as $figure) {
            $value = $state[$figure] ?? null;
            if ($value === null || (int) $value < 0) {
                return [self::UNKNOWN, 'figures_unreadable', ''];
            }
        }
        // The differences have BEEN SHOWN once the scan reached the end and
        // every figure could be read. This step asks whether the manager can
        // see the picture, not whether the picture is empty — that is the next
        // two steps' question.
        return [self::DONE, '', implode('/', [
            (string) (int) $state['ready'],
            (string) (int) $state['drift_fixable'],
            (string) (int) $state['risk_real'],
        ])];
    }

    /** @param array<string,mixed> $state @return array{0:string, 1:string, 2:string} */
    private static function sync(array $state): array
    {
        $drift = $state['drift_fixable'] ?? null;
        $status = $state['status_repairable'] ?? null;
        if ($drift === null || (int) $drift < 0 || $status === null || (int) $status < 0) {
            return [self::UNKNOWN, 'sync_figures_unreadable', ''];
        }
        $left = (int) $drift + (int) $status;
        return $left === 0
            ? [self::DONE, '', '0']
            : [self::TODO, 'fixable_differences_left', (string) $left];
    }

    /** @param array<string,mixed> $state @return array{0:string, 1:string, 2:string} */
    private static function plan(array $state): array
    {
        if (($state['scan_finished'] ?? null) !== true) {
            return [self::UNKNOWN, 'scan_did_not_finish', ''];
        }
        $observed = $state['observed'] ?? null;
        if ($observed === null || (int) $observed < 0) {
            return [self::UNKNOWN, 'figures_unreadable', ''];
        }
        $risk = (int) ($state['risk_real'] ?? 0);
        // The plan is showable when nothing is left that the plan cannot
        // express: a real risk has no row in the transfer plan, it has a
        // refusal.
        return $risk === 0
            ? [self::DONE, '', (string) (int) $observed]
            : [self::TODO, 'real_risks_left', (string) $risk];
    }

    /** @param array<string,mixed> $state @return array{0:string, 1:string, 2:string} */
    private static function consent(array $state): array
    {
        $observed = $state['observed'] ?? null;
        $marketplace = $state['marketplace'] ?? null;
        if ($observed === null || $marketplace === null) {
            return [self::UNKNOWN, 'ownership_unreadable', ''];
        }
        // ⛔ This step is NEVER `DONE` by itself and that is deliberate: the
        // consent is a press on the transfer screen, carried by a nonce and a
        // digest of the very list that was shown. Nothing stored anywhere can
        // stand in for it, so the checklist reports whether there is still
        // something to consent to and the button stays where it is.
        return (int) $observed === 0 && (int) $marketplace > 0
            ? [self::DONE, '', (string) (int) $marketplace]
            : [self::TODO, 'consent_is_a_press', (string) (int) $observed];
    }

    /** @param array<string,mixed> $state @return array{0:string, 1:string, 2:string} */
    private static function transfer(array $state): array
    {
        $observed = $state['observed'] ?? null;
        $marketplace = $state['marketplace'] ?? null;
        if ($observed === null || $marketplace === null || (int) $observed < 0 || (int) $marketplace < 0) {
            return [self::UNKNOWN, 'ownership_unreadable', ''];
        }
        if ((int) $observed === 0 && (int) $marketplace > 0) {
            return [self::DONE, '', (string) (int) $marketplace];
        }
        return [self::TODO, 'rows_still_observed', (string) (int) $observed];
    }

    /** @param array<string,mixed> $state @return array{0:string, 1:string, 2:string} */
    private static function validate(array $state): array
    {
        $missing = $state['gates_missing'] ?? null;
        if (!is_array($missing)) {
            return [self::UNKNOWN, 'completeness_unreadable', ''];
        }
        return $missing === []
            ? [self::DONE, '', '0']
            : [self::TODO, 'gates_open', implode('، ', array_map('strval', $missing))];
    }

    /** @param array<string,mixed> $state @return array{0:string, 1:string, 2:string} */
    private static function exitReady(array $state): array
    {
        $missing = $state['gates_missing'] ?? null;
        if (!is_array($missing)) {
            return [self::UNKNOWN, 'completeness_unreadable', ''];
        }
        if ($missing !== []) {
            return [self::TODO, 'gates_open', implode('، ', array_map('strval', $missing))];
        }
        // ⛔ And the last step is a REPORT, not a switch. Nothing in this
        // package deactivates Dokan, deletes its data or flushes its rewrite
        // rules; those are the owner's actions on the server, and the
        // checklist's job ends at saying they may be taken.
        return [self::DONE, '', 'report'];
    }
}
