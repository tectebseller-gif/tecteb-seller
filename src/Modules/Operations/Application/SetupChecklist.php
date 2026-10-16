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
 * **Blocked is a third answer, and it is not «incomplete».** It is also, from
 * `alpha.57`, unreachable for the commission step: that one waited on DEC-01,
 * and the owner has decided — commission is set per product and a product
 * with none sells, so there is no site-wide number left to wait for. The
 * answer kept its name because other steps may need it.
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
     * NOTHING TO CONFIGURE HERE ANY MORE — and that is the answer, not a
     * blank.
     *
     * This was the one step that could be BLOCKED: with neither a rule nor an
     * approved global rate it reported DEC-01 and the order gate stayed shut,
     * because FIN-02 said an unset rate was not zero and the plugin must not
     * invent one. The owner has now decided it, and the decision removes the
     * step's reason to exist rather than answering it:
     *
     *   * commission is Tecteb's share, set **per product** on the WooCommerce
     *     product editor — so there is no site-wide value for a checklist to
     *     wait on;
     *   * **a product with none sells**: «تعیین‌نشده یا صفر یعنی بدون کمیسیون»
     *     and «نبود نرخ مانع تأیید، انتشار یا فروش نیست».
     *
     * So the step is `DONE` unconditionally and its sentence says where the
     * field is. It is not deleted, because a checklist that silently loses a
     * row makes «۶ از ۶» mean something different from last week without
     * saying so, and because the manager still wants to know where commission
     * lives. `$state`'s commission keys are left in place and unread for the
     * same reason the scoped rules are: they are what explains lines recorded
     * before this round.
     *
     * The OTHER gates are untouched: DEC-02 and DEC-04 still close the order
     * gate and the ledger probe still runs — «سایر دروازه‌های مستقل … را
     * خودکار باز نکن» (§7).
     *
     * @param array<string,mixed> $state
     * @return array{0:string, 1:string, 2:string}
     */
    private static function commission(array $state): array
    {
        return [self::DONE, '', 'tmc-commissions'];
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
