<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;

/**
 * A MANAGER SAYING THE MIGRATION IS FINISHED — and the one check that stops
 * them saying it too early.
 *
 * §5 wants the temporary tools out of the everyday menu «پس از تأیید موفقیت
 * مهاجرت», and §3-10 wants the Min Max exit screen out on the same condition
 * with recovery kept. This is that confirmation: one action, audited, and
 * revocable from the health page.
 *
 * ### What it refuses, and why that is the whole value
 *
 * Hiding the tools while the migration still needs them is the failure mode,
 * and it is a quiet one: the manager presses a button, the menu gets tidier,
 * and three weeks later somebody needs the handover screen and cannot find
 * it. So the expensive question IS asked — once, here, on the click rather
 * than on every menu build — and every shop with an import run has to show
 * twelve DONE steps.
 *
 * ⛔ **And an unreadable checklist refuses.** `MigrationPlanChecklist` already
 * has four answers and its fourth is «اندازه‌گیری‌نشده», which it deliberately
 * never counts as ready (`alpha.95`). The same posture here: a step that could
 * not be measured is not a finished step, and a confirmation taken on a
 * checklist nobody could read would hide the tools on the strength of a failed
 * query.
 *
 * ⛔ **Nothing is deleted, by construction.** This class has no delete, no
 * DROP, no migration and no uninstall path. It writes one option and one audit
 * row. «از حذف فوری کد، دیتابیس، اسناد یا قابلیت بازگشت اجتناب کن» is
 * therefore a property of what this class CAN do, not a promise in a
 * docblock — `MigrationConclusionIsReversibleTest` reads these bytes for the
 * absence.
 */
final class ConcludeMigration
{
    /** No manager capability. */
    public const FORBIDDEN = 'forbidden';
    /** At least one shop still has unfinished steps; they are named. */
    public const STEPS_REMAIN = 'migration_steps_remain';
    /** Nothing has been imported, so there is no migration to conclude. */
    public const NOTHING_IMPORTED = 'migration_nothing_imported';
    /** The option could not be written; the menu is unchanged. */
    public const NOT_STORED = 'migration_conclusion_not_stored';
    /** Already concluded; pressing again changes nothing and writes nothing. */
    public const ALREADY = 'migration_already_concluded';
    /** Not concluded, so there is nothing to undo. */
    public const NOT_CONCLUDED = 'migration_not_concluded';

    public function __construct(
        private readonly MigrationToolsVisibility $visibility,
        private readonly MigrationPlanSnapshot $snapshot,
        private readonly MigrationCompleteness $completeness,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
        private readonly CapabilityCheckerInterface $capabilities
    ) {
    }

    /**
     * Hide the temporary section, if every imported shop is finished.
     *
     * @return OperationResult `unfinished` in the context names the shops and
     *         their first incomplete step, so the refusal is actionable rather
     *         than a flat no.
     */
    public function conclude(): OperationResult
    {
        if (!$this->capabilities->can(Capabilities::REVIEW_VENDOR)) {
            return OperationResult::failure(self::FORBIDDEN);
        }
        if ($this->visibility->concludedAt() !== '') {
            // Idempotent, and it writes NOTHING: a second click must not
            // stamp a second time or log a second row (`alpha.86`).
            return OperationResult::failure(self::ALREADY, [
                'concluded_at' => $this->visibility->concludedAt(),
            ]);
        }
        $shops = $this->completeness->shopVendorUserIds();
        if ($shops === []) {
            return OperationResult::failure(self::NOTHING_IMPORTED);
        }
        $unfinished = [];
        foreach ($shops as $vendorUserId) {
            $remaining = $this->remainingFor((int) $vendorUserId);
            if ($remaining !== []) {
                $unfinished[(int) $vendorUserId] = $remaining;
            }
        }
        if ($unfinished !== []) {
            return OperationResult::failure(self::STEPS_REMAIN, ['unfinished' => $unfinished]);
        }
        $at = $this->clock->now()->format('Y-m-d H:i:s');
        if (!$this->visibility->recordConclusion($at)) {
            return OperationResult::failure(self::NOT_STORED);
        }
        $this->audit->log(
            AuditEventCatalog::MIGRATION_CONCLUDED,
            $this->capabilities->currentUserId() ?? 0,
            'migration',
            'tools',
            ['concluded_at' => $at, 'shops' => array_map('intval', $shops)]
        );
        return OperationResult::success('migration_concluded', ['concluded_at' => $at]);
    }

    /**
     * The way back — and it asks for nothing but the capability.
     *
     * Deliberately NOT symmetrical with `conclude()`. Revealing the tools
     * cannot hurt anything, and a manager who needs them back is by definition
     * in a situation the checklist did not predict; making them satisfy a
     * condition first would be the gate with no way out that `alpha.97`
     * describes.
     */
    public function reopen(): OperationResult
    {
        if (!$this->capabilities->can(Capabilities::REVIEW_VENDOR)) {
            return OperationResult::failure(self::FORBIDDEN);
        }
        $was = $this->visibility->concludedAt();
        if ($was === '') {
            return OperationResult::failure(self::NOT_CONCLUDED);
        }
        if (!$this->visibility->forgetConclusion()) {
            return OperationResult::failure(self::NOT_STORED);
        }
        $this->audit->log(
            AuditEventCatalog::MIGRATION_REOPENED,
            $this->capabilities->currentUserId() ?? 0,
            'migration',
            'tools',
            ['was_concluded_at' => $was]
        );
        return OperationResult::success('migration_reopened', ['was_concluded_at' => $was]);
    }

    /**
     * The steps of one shop that are not DONE, by name.
     *
     * An unreadable snapshot yields the step names too: `steps()` answers
     * `UNKNOWN` for a figure it could not measure, and that is counted as
     * remaining rather than skipped.
     *
     * @return list<string>
     */
    private function remainingFor(int $vendorUserId): array
    {
        $steps = MigrationPlanChecklist::steps($this->snapshot->of($vendorUserId));
        $remaining = [];
        foreach ($steps as $step) {
            if (($step['status'] ?? MigrationPlanChecklist::UNKNOWN) !== MigrationPlanChecklist::DONE) {
                $remaining[] = (string) ($step['key'] ?? '');
            }
        }
        return $remaining;
    }
}
