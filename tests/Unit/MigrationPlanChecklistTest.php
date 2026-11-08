<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Migration\Application\MigrationPlanChecklist as Plan;
use Tecteb\Marketplace\Modules\Migration\Application\OwnershipState;

/**
 * The twelve steps, every branch, from an array.
 *
 * The class is pure, so this covers it completely — which is the point of it
 * being pure. The page that gathers the snapshot is tested separately.
 */
final class MigrationPlanChecklistTest extends TestCase
{
    /** A site where everything has been done. */
    private const COMPLETE = [
        'php_version' => '8.1.34',
        'platform_version' => '6.8',
        'woocommerce_active' => true,
        'woocommerce_version' => '11.0.1',
        'dokan_running' => true,
        'schema_version' => 38,
        'schema_target' => 38,
        'rollback' => 'safe',
        'shops' => 2,
        'observed' => 0,
        'marketplace' => 184,
        // ⛔ The ANSWER, not the two counts. Steps 9 and 10 used to derive it
        // here from `observed` and `marketplace`, which is the same rule
        // written twice — and both copies got a shop with no products wrong.
        'ownership_state' => OwnershipState::TAKEN,
        'scan_finished' => true,
        'ready' => 0,
        'drift_fixable' => 0,
        'risk_real' => 0,
        'status_repairable' => 0,
        'transfer_runs' => 3,
        'gates_missing' => [],
    ];

    /** @param array<string,mixed> $overrides @return array<string,array{key:string,status:string,blocker:string,detail:string}> */
    private function steps(array $overrides = []): array
    {
        $byKey = [];
        foreach (Plan::steps(array_merge(self::COMPLETE, $overrides)) as $step) {
            $byKey[$step['key']] = $step;
        }
        return $byKey;
    }

    public function testEveryStepIsJudgedAndInOrder(): void
    {
        $steps = Plan::steps(self::COMPLETE);
        self::assertCount(12, $steps);
        self::assertSame(Plan::ORDER, array_column($steps, 'key'));
        foreach ($steps as $step) {
            self::assertSame(Plan::DONE, $step['status'], $step['key']);
        }
    }

    public function testACompleteSiteIsReady(): void
    {
        $tally = Plan::tally(Plan::steps(self::COMPLETE));
        self::assertSame(12, $tally['done']);
        self::assertSame(12, $tally['total']);
        self::assertTrue($tally['ready']);
    }

    /**
     * ⛔ The rule the whole class hangs on: an unmeasured step is never ready.
     */
    public function testOneUnknownStepIsEnoughToWithholdReadiness(): void
    {
        $steps = Plan::steps(array_merge(self::COMPLETE, ['rollback' => '']));
        $tally = Plan::tally($steps);
        self::assertSame(1, $tally['unknown']);
        self::assertSame(0, $tally['blocked']);
        self::assertSame(0, $tally['todo']);
        self::assertFalse($tally['ready'], 'an unknown step is not a kind of done');
    }

    public function testAnEmptyChecklistIsNotReady(): void
    {
        $tally = Plan::tally([]);
        self::assertFalse($tally['ready'], 'nothing measured is not everything done');
        self::assertSame(0, $tally['total']);
    }

    // ------------------------------------------------------------ environment

    public function testAnOldPhpBlocksTheEnvironmentStep(): void
    {
        $step = $this->steps(['php_version' => '8.0.30'])[Plan::STEP_ENVIRONMENT];
        self::assertSame(Plan::BLOCKED, $step['status']);
        self::assertSame('php_too_old', $step['blocker']);
    }

    public function testThePhpFloorIsInclusive(): void
    {
        self::assertSame(
            Plan::DONE,
            $this->steps(['php_version' => '8.1.0'])[Plan::STEP_ENVIRONMENT]['status']
        );
    }

    public function testAVersionThatCouldNotBeReadIsUnknownRatherThanOld(): void
    {
        foreach ([['php_version' => ''], ['platform_version' => null], ['platform_version' => '']] as $o) {
            $step = $this->steps($o)[Plan::STEP_ENVIRONMENT];
            self::assertSame(Plan::UNKNOWN, $step['status'], (string) json_encode($o));
            self::assertSame('version_unreadable', $step['blocker']);
        }
    }

    public function testAStructureBehindTheTargetBlocks(): void
    {
        $step = $this->steps(['schema_version' => 37])[Plan::STEP_ENVIRONMENT];
        self::assertSame(Plan::BLOCKED, $step['status']);
        self::assertSame('schema_behind', $step['blocker']);
        self::assertSame('37/38', $step['detail']);
    }

    /** ⛔ And AHEAD is not «at»: that is a site somebody rolled back. */
    public function testAStructureAheadOfThisPackageAlsoBlocks(): void
    {
        $step = $this->steps(['schema_version' => 39])[Plan::STEP_ENVIRONMENT];
        self::assertSame(Plan::BLOCKED, $step['status']);
        self::assertSame('schema_behind', $step['blocker']);
    }

    public function testAnUnreadableTargetIsUnknown(): void
    {
        $step = $this->steps(['schema_target' => 0])[Plan::STEP_ENVIRONMENT];
        self::assertSame(Plan::UNKNOWN, $step['status']);
        self::assertSame('schema_unreadable', $step['blocker']);
    }

    // ---------------------------------------------------------------- plugins

    public function testNoWooCommerceBlocks(): void
    {
        $step = $this->steps(['woocommerce_active' => false])[Plan::STEP_PLUGINS];
        self::assertSame(Plan::BLOCKED, $step['status']);
        self::assertSame('woocommerce_missing', $step['blocker']);
    }

    public function testAnUnreadableWooCommerceVersionIsUnknown(): void
    {
        foreach ([null, '', '  '] as $version) {
            $step = $this->steps(['woocommerce_version' => $version])[Plan::STEP_PLUGINS];
            self::assertSame(Plan::UNKNOWN, $step['status'], var_export($version, true));
        }
    }

    /**
     * ⛔ Dokan RUNNING is the healthy state here: a migration planned after
     * the source has gone is a migration with nothing to read.
     */
    public function testDokanNotRunningBlocksThePlanningStep(): void
    {
        $step = $this->steps(['dokan_running' => false])[Plan::STEP_PLUGINS];
        self::assertSame(Plan::BLOCKED, $step['status']);
        self::assertSame('dokan_not_running', $step['blocker']);
    }

    public function testADokanStateNobodyCouldReadIsUnknown(): void
    {
        $step = $this->steps(['dokan_running' => null])[Plan::STEP_PLUGINS];
        self::assertSame(Plan::UNKNOWN, $step['status']);
        self::assertSame('dokan_state_unreadable', $step['blocker']);
    }

    // ----------------------------------------------------------------- backup

    public function testTheBackupStepHasThreeAnswersAndOnlySafeIsDone(): void
    {
        self::assertSame(Plan::DONE, $this->steps(['rollback' => 'safe'])[Plan::STEP_BACKUP]['status']);
        $blocked = $this->steps(['rollback' => 'blocked'])[Plan::STEP_BACKUP];
        self::assertSame(Plan::BLOCKED, $blocked['status']);
        self::assertSame('rollback_would_lose_data', $blocked['blocker']);
        foreach (['unknown', '', 'anything'] as $verdict) {
            $step = $this->steps(['rollback' => $verdict])[Plan::STEP_BACKUP];
            self::assertSame(Plan::UNKNOWN, $step['status'], $verdict);
            self::assertSame('rollback_unknown', $step['blocker']);
        }
    }

    // -------------------------------------------- inventory, dry run, figures

    public function testNoShopIsWorkRatherThanAnError(): void
    {
        $step = $this->steps(['shops' => 0])[Plan::STEP_INVENTORY];
        self::assertSame(Plan::TODO, $step['status']);
        self::assertSame('no_shop_identified', $step['blocker']);
    }

    public function testAnUnreadableShopCountIsUnknown(): void
    {
        foreach ([null, -1] as $shops) {
            self::assertSame(
                Plan::UNKNOWN,
                $this->steps(['shops' => $shops])[Plan::STEP_INVENTORY]['status'],
                var_export($shops, true)
            );
        }
    }

    public function testADryRunIsRecordedByItsRun(): void
    {
        self::assertSame(Plan::TODO, $this->steps(['transfer_runs' => 0])[Plan::STEP_DRY_RUN]['status']);
        self::assertSame(Plan::UNKNOWN, $this->steps(['transfer_runs' => null])[Plan::STEP_DRY_RUN]['status']);
        self::assertSame(Plan::UNKNOWN, $this->steps(['transfer_runs' => -1])[Plan::STEP_DRY_RUN]['status']);
    }

    /**
     * A scan that stopped short cannot report differences, however good the
     * numbers it did reach look.
     */
    public function testAScanThatDidNotFinishMakesTwoStepsUnknown(): void
    {
        $steps = $this->steps(['scan_finished' => false]);
        foreach ([Plan::STEP_DIFFERENCES, Plan::STEP_PLAN] as $key) {
            self::assertSame(Plan::UNKNOWN, $steps[$key]['status'], $key);
            self::assertSame('scan_did_not_finish', $steps[$key]['blocker']);
        }
    }

    public function testAnUnreadableFigureMakesTheDifferencesStepUnknown(): void
    {
        foreach (['ready', 'drift_fixable', 'risk_real'] as $figure) {
            $step = $this->steps([$figure => -1])[Plan::STEP_DIFFERENCES];
            self::assertSame(Plan::UNKNOWN, $step['status'], $figure);
            self::assertSame('figures_unreadable', $step['blocker']);
        }
    }

    /** The differences step asks whether the picture is VISIBLE, not empty. */
    public function testTheDifferencesStepIsDoneEvenWhenThereIsWorkToDo(): void
    {
        $step = $this->steps(['drift_fixable' => 100, 'risk_real' => 84])[Plan::STEP_DIFFERENCES];
        self::assertSame(Plan::DONE, $step['status']);
        self::assertSame('0/100/84', $step['detail']);
    }

    // ------------------------------------------------------------------- sync

    public function testBothKindsOfFixableDifferenceCountTowardsTheSyncStep(): void
    {
        $drift = $this->steps(['drift_fixable' => 100])[Plan::STEP_SYNC];
        self::assertSame(Plan::TODO, $drift['status']);
        self::assertSame('100', $drift['detail']);

        // The repair this round added is the other half of the same step.
        $status = $this->steps(['status_repairable' => 184])[Plan::STEP_SYNC];
        self::assertSame(Plan::TODO, $status['status']);
        self::assertSame('184', $status['detail']);

        $both = $this->steps(['drift_fixable' => 100, 'status_repairable' => 84])[Plan::STEP_SYNC];
        self::assertSame('184', $both['detail'], 'the two are added, not reported separately');
    }

    public function testAnUnreadableSyncFigureIsUnknown(): void
    {
        foreach ([['drift_fixable' => null], ['status_repairable' => -1]] as $o) {
            self::assertSame(Plan::UNKNOWN, $this->steps($o)[Plan::STEP_SYNC]['status'], (string) json_encode($o));
        }
    }

    // ----------------------------------------- plan, consent, transfer, gates

    public function testARealRiskKeepsThePlanStepOpen(): void
    {
        $step = $this->steps(['risk_real' => 84])[Plan::STEP_PLAN];
        self::assertSame(Plan::TODO, $step['status']);
        self::assertSame('real_risks_left', $step['blocker']);
        self::assertSame('84', $step['detail']);
    }

    /** ⛔ Consent is a press, so nothing stored can finish this step early. */
    public function testConsentIsOpenWhileAnyRowIsStillObserved(): void
    {
        $step = $this->steps([
            'observed' => 184,
            'marketplace' => 0,
            'ownership_state' => OwnershipState::ROWS_OBSERVED,
        ])[Plan::STEP_CONSENT];
        self::assertSame(Plan::TODO, $step['status']);
        self::assertSame('consent_is_a_press', $step['blocker']);
    }

    public function testTransferAndConsentBothNeedEveryRowMoved(): void
    {
        $halfway = $this->steps([
            'observed' => 84,
            'marketplace' => 100,
            'ownership_state' => OwnershipState::ROWS_OBSERVED,
        ]);
        self::assertSame(Plan::TODO, $halfway[Plan::STEP_TRANSFER]['status']);
        self::assertSame('rows_still_observed', $halfway[Plan::STEP_TRANSFER]['blocker']);
        self::assertSame('84', $halfway[Plan::STEP_TRANSFER]['detail']);
        self::assertSame(Plan::TODO, $halfway[Plan::STEP_CONSENT]['status']);
    }

    /**
     * ⛔ **This test asserted the defect, and was rewritten rather than
     * loosened.**
     *
     * «A shop with no rows at all has not transferred anything» is a true
     * sentence about an id nobody imported, and a dead end about shop 40 —
     * which carries a profile import stamp and no products, and whose steps 9
     * and 10 therefore read `TODO / rows_still_observed / ۰` for ever:
     * instructions that contradict themselves, with no exit but inventing a
     * product or deleting the shop. The owner's §1 refuses both.
     *
     * So the two cases are now told apart by a POSITIVE fact — the import
     * stamp — and both halves are pinned here, because a release that only
     * pinned the new one would let the stamp check be deleted silently.
     */
    public function testAShopWithNoRowsIsFinishedOnlyIfSomethingSaysItWasImported(): void
    {
        $imported = $this->steps([
            'observed' => 0,
            'marketplace' => 0,
            'ownership_state' => OwnershipState::NOTHING_TO_TRANSFER,
        ]);
        foreach ([Plan::STEP_TRANSFER, Plan::STEP_CONSENT] as $key) {
            self::assertSame(Plan::DONE, $imported[$key]['status'], $key);
            self::assertSame(OwnershipState::NOTHING_TO_TRANSFER, $imported[$key]['detail'], $key);
        }

        // And the control: nothing says this id was ever imported, so a
        // nought is not «finished» — it is «who is this shop».
        $stranger = $this->steps([
            'observed' => 0,
            'marketplace' => 0,
            'ownership_state' => OwnershipState::ROWS_OBSERVED,
        ]);
        foreach ([Plan::STEP_TRANSFER, Plan::STEP_CONSENT] as $key) {
            self::assertSame(Plan::TODO, $stranger[$key]['status'], $key);
        }
    }

    /** And neither finished answer counts as ready on its own. */
    public function testNothingToTransferStillNeedsEveryOtherStep(): void
    {
        $steps = Plan::steps(array_merge(self::COMPLETE, [
            'observed' => 0,
            'marketplace' => 0,
            'ownership_state' => OwnershipState::NOTHING_TO_TRANSFER,
            'gates_missing' => ['vendor_can_sell'],
        ]));
        self::assertFalse(Plan::tally($steps)['ready'], 'an open gate still withholds readiness');
    }

    public function testUnreadableOwnershipIsUnknownForBothSteps(): void
    {
        // The unreadable tally arrives as the ANSWER now: `OwnershipState`
        // refuses to read two numbers it was told not to trust.
        $steps = $this->steps(['observed' => null, 'ownership_state' => OwnershipState::UNREADABLE]);
        self::assertSame(Plan::UNKNOWN, $steps[Plan::STEP_TRANSFER]['status']);
        self::assertSame(Plan::UNKNOWN, $steps[Plan::STEP_CONSENT]['status']);
        // ⛔ And a state key that is MISSING altogether is unreadable too,
        // never a verdict: a caller holding an older snapshot shape must not
        // land on «done» (`alpha.68`).
        $older = $this->steps(['ownership_state' => null]);
        self::assertSame(Plan::UNKNOWN, $older[Plan::STEP_TRANSFER]['status']);
    }

    public function testAnOpenGateKeepsBothLastStepsOpenAndNamesIt(): void
    {
        $steps = $this->steps(['gates_missing' => ['vendor_can_sell', 'records_imported']]);
        foreach ([Plan::STEP_VALIDATE, Plan::STEP_EXIT_READY] as $key) {
            self::assertSame(Plan::TODO, $steps[$key]['status'], $key);
            self::assertSame('gates_open', $steps[$key]['blocker']);
            self::assertStringContainsString('vendor_can_sell', $steps[$key]['detail']);
        }
    }

    public function testCompletenessThatCouldNotBeReadIsUnknown(): void
    {
        $steps = $this->steps(['gates_missing' => null]);
        foreach ([Plan::STEP_VALIDATE, Plan::STEP_EXIT_READY] as $key) {
            self::assertSame(Plan::UNKNOWN, $steps[$key]['status'], $key);
            self::assertSame('completeness_unreadable', $steps[$key]['blocker']);
        }
    }

    /** An empty snapshot must not produce a single `DONE`. */
    public function testAnEmptySnapshotClaimsNothing(): void
    {
        foreach (Plan::steps([]) as $step) {
            self::assertNotSame(Plan::DONE, $step['status'], $step['key']);
        }
        self::assertFalse(Plan::tally(Plan::steps([]))['ready']);
    }
}
