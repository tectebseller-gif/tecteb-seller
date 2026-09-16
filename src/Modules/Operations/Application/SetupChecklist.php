<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Operations\Application;

/**
 * What a new installation still needs, decided from what is actually stored.
 *
 * **It is a checklist, not a wizard that owns settings.** Every step points at
 * the page that already writes that value, and nothing here writes anything
 * except which steps a manager chose to skip. A wizard with its own storage
 * would be a second place for the same setting to live — the mistake the coupon
 * made in `alpha.9`, where a «coupon» that was really a negative fee behaved
 * like a coupon nowhere it mattered.
 *
 * **A step is done because the value is there, not because somebody pressed
 * «بعدی».** So an installation configured entirely from the ordinary settings
 * pages shows a complete checklist without ever opening this one, and a value
 * later cleared makes its step incomplete again. Progress that can only move
 * forwards is progress that lies eventually.
 *
 * **Blocked is a third answer, and it is not «incomplete».** The commission
 * rate depends on DEC-01 and the document list on the owner's decision; those
 * steps say what they are waiting for and on whom, rather than nagging a
 * manager to supply a number nobody has approved.
 *
 * Pure: no WordPress. The state arrives as a snapshot and the Persian wording
 * is chosen in Presentation.
 */
final class SetupChecklist
{
    public const DONE = 'done';
    public const TODO = 'todo';
    public const BLOCKED = 'blocked';
    public const SKIPPED = 'skipped';

    public const SKIPPED_OPTION = 'tmc_setup_skipped_steps';

    public const STEP_COMMISSION = 'commission';
    public const STEP_DOCUMENTS = 'documents';
    public const STEP_SETTLEMENT = 'settlement';
    public const STEP_STAFF_LIMIT = 'staff_limit';
    public const STEP_WOOCOMMERCE = 'woocommerce';
    public const STEP_VENDOR_PAGE = 'vendor_page';
    public const STEP_QUEUE = 'queue';

    /** @var list<string> the order a manager works through them */
    public const ORDER = [
        self::STEP_WOOCOMMERCE,
        self::STEP_COMMISSION,
        self::STEP_DOCUMENTS,
        self::STEP_SETTLEMENT,
        self::STEP_STAFF_LIMIT,
        self::STEP_VENDOR_PAGE,
        self::STEP_QUEUE,
    ];

    /**
     * @param array{
     *   woocommerce_active:bool,
     *   commission_rate_bp:?int,
     *   commission_rules:int,
     *   document_types:int,
     *   settlement_delay_days:int,
     *   max_staff:int,
     *   vendor_page_reachable:bool,
     *   queue_binding:string,
     *   decision_open:bool
     * } $state
     * @param list<string> $skipped
     * @return list<array{key:string, status:string, blocker:string, target:string}>
     */
    public static function steps(array $state, array $skipped = []): array
    {
        $steps = [];
        foreach (self::ORDER as $key) {
            [$status, $blocker, $target] = self::judge($key, $state);
            if ($status === self::TODO && in_array($key, $skipped, true)) {
                // A skip hides the nag, never the fact. The step keeps its own
                // place in the list and says it was skipped, so «تمام شد» never
                // means «somebody pressed skip seven times».
                $status = self::SKIPPED;
            }
            $steps[] = ['key' => $key, 'status' => $status, 'blocker' => $blocker, 'target' => $target];
        }
        return $steps;
    }

    /**
     * @param array<string,mixed> $state
     * @return array{0:string, 1:string, 2:string}
     */
    private static function judge(string $key, array $state): array
    {
        return match ($key) {
            self::STEP_WOOCOMMERCE => [
                ((bool) ($state['woocommerce_active'] ?? false)) ? self::DONE : self::TODO,
                ((bool) ($state['woocommerce_active'] ?? false)) ? '' : 'woocommerce_missing',
                'plugins.php',
            ],
            self::STEP_COMMISSION => self::commission($state),
            self::STEP_DOCUMENTS => [
                ((int) ($state['document_types'] ?? 0)) > 0 ? self::DONE : self::TODO,
                '',
                'tmc-vendor-documents',
            ],
            self::STEP_SETTLEMENT => [
                ((int) ($state['settlement_delay_days'] ?? 0)) > 0 ? self::DONE : self::TODO,
                '',
                'tmc-settings',
            ],
            self::STEP_STAFF_LIMIT => [
                ((int) ($state['max_staff'] ?? 0)) > 0 ? self::DONE : self::TODO,
                '',
                'tmc-settings',
            ],
            self::STEP_VENDOR_PAGE => [
                ((bool) ($state['vendor_page_reachable'] ?? false)) ? self::DONE : self::TODO,
                ((bool) ($state['vendor_page_reachable'] ?? false)) ? '' : 'permalinks_plain',
                'options-permalink.php',
            ],
            self::STEP_QUEUE => self::queue($state),
            default => [self::TODO, '', 'tmc-dashboard'],
        };
    }

    /**
     * The rate is the one step that can be BLOCKED rather than merely undone.
     *
     * A per-vendor rule counts as configured even with no global rate, because
     * that is a real and complete way to run a marketplace. What is never
     * counted is a rate this plugin invented: FIN-02 forbids guessing one, so
     * with neither a rule nor an approved global value the step reports the
     * decision it is waiting on instead of asking the manager to make it up.
     *
     * @param array<string,mixed> $state
     * @return array{0:string, 1:string, 2:string}
     */
    private static function commission(array $state): array
    {
        if ((int) ($state['commission_rules'] ?? 0) > 0) {
            return [self::DONE, '', 'tmc-commissions'];
        }
        if (($state['commission_rate_bp'] ?? null) !== null) {
            return [self::DONE, '', 'tmc-settings'];
        }
        if ((bool) ($state['decision_open'] ?? true)) {
            return [self::BLOCKED, 'DEC-01', 'tmc-commissions'];
        }
        return [self::TODO, '', 'tmc-commissions'];
    }

    /**
     * @param array<string,mixed> $state
     * @return array{0:string, 1:string, 2:string}
     */
    private static function queue(array $state): array
    {
        $binding = (string) ($state['queue_binding'] ?? '');
        if ($binding === 'wp_cron_disabled') {
            return [self::TODO, 'wp_cron_disabled', 'tmc-jobs'];
        }
        return [$binding === '' ? self::TODO : self::DONE, '', 'tmc-jobs'];
    }

    /**
     * @param list<array{key:string, status:string, blocker:string, target:string}> $steps
     * @return array{done:int, todo:int, blocked:int, skipped:int, total:int}
     */
    public static function summary(array $steps): array
    {
        $out = ['done' => 0, 'todo' => 0, 'blocked' => 0, 'skipped' => 0, 'total' => count($steps)];
        foreach ($steps as $step) {
            $out[$step['status']] = ($out[$step['status']] ?? 0) + 1;
        }
        return $out;
    }
}
