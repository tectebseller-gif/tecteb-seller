<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Operations;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Operations\Application\SetupChecklist;

/**
 * WHAT THIS PROVES: the checklist reads state rather than remembering button
 * presses, and «waiting on a decision» is not the same answer as «not done».
 */
final class SetupChecklistTest extends TestCase
{
    public function testACompletelyConfiguredSiteHasNothingLeft(): void
    {
        $steps = SetupChecklist::steps($this->state());
        $summary = SetupChecklist::summary($steps);
        self::assertSame(0, $summary['todo']);
        self::assertSame(0, $summary['blocked']);
        self::assertSame($summary['total'], $summary['done']);
    }

    /**
     * WHAT THIS PROVES: a site with no commission rate anywhere has nothing
     * left to do about commission.
     *
     * This test used to assert the opposite — `BLOCKED` on DEC-01 — and the
     * owner has decided: commission is Tecteb's share, set per product on the
     * WooCommerce product editor, and «تعیین‌نشده یا صفر یعنی بدون کمیسیون».
     * A checklist that still waited for a site-wide number would be waiting
     * for something that no longer exists, and §7 asked for exactly this
     * blocker to be cleared.
     *
     * The old assertion is REPLACED rather than loosened, which is the
     * `alpha.49` rule: a test that writes down a rule the product no longer
     * has is deleted, not weakened.
     */
    public function testWithNoRateAnywhereThereIsNothingLeftToDoAboutCommission(): void
    {
        $steps = $this->byKey(SetupChecklist::steps($this->state(['commission_rate_bp' => null, 'commission_rules' => 0])));
        self::assertSame(SetupChecklist::DONE, $steps[SetupChecklist::STEP_COMMISSION]['status']);
        self::assertSame('', $steps[SetupChecklist::STEP_COMMISSION]['blocker'], 'no decision is being waited on');

        // …and no OTHER step was opened by that change: the ones that depend
        // on a real value still report it («سایر دروازه‌های مستقل … را خودکار
        // باز نکن»).
        $opened = $this->byKey(SetupChecklist::steps($this->state([
            'commission_rate_bp' => null,
            'commission_rules' => 0,
            'document_types' => 0,
            'woocommerce_active' => false,
        ])));
        self::assertSame(SetupChecklist::TODO, $opened[SetupChecklist::STEP_DOCUMENTS]['status']);
        self::assertSame(SetupChecklist::TODO, $opened[SetupChecklist::STEP_WOOCOMMERCE]['status']);
    }

    /** A site that still holds the old scoped rules reads the same way. */
    public function testTheOldScopedRulesNeitherCompleteNorBlockTheStep(): void
    {
        $steps = $this->byKey(SetupChecklist::steps($this->state(['commission_rate_bp' => null, 'commission_rules' => 3])));
        self::assertSame(SetupChecklist::DONE, $steps[SetupChecklist::STEP_COMMISSION]['status']);
    }

    public function testAValueLaterClearedMakesItsStepIncompleteAgain(): void
    {
        $steps = $this->byKey(SetupChecklist::steps($this->state(['document_types' => 0])));
        self::assertSame(SetupChecklist::TODO, $steps[SetupChecklist::STEP_DOCUMENTS]['status']);
    }

    public function testSkippingHidesTheNagAndNotTheFact(): void
    {
        $steps = SetupChecklist::steps($this->state(['document_types' => 0]), [SetupChecklist::STEP_DOCUMENTS]);
        $byKey = $this->byKey($steps);
        self::assertSame(SetupChecklist::SKIPPED, $byKey[SetupChecklist::STEP_DOCUMENTS]['status']);

        $summary = SetupChecklist::summary($steps);
        self::assertSame(1, $summary['skipped']);
        self::assertNotSame($summary['total'], $summary['done'], 'a skip must never read as done');
    }

    public function testSkippingSomethingAlreadyDoneChangesNothing(): void
    {
        $byKey = $this->byKey(SetupChecklist::steps($this->state(), [SetupChecklist::STEP_DOCUMENTS]));
        self::assertSame(SetupChecklist::DONE, $byKey[SetupChecklist::STEP_DOCUMENTS]['status']);
    }

    public function testADisabledCronIsSomethingToFixRatherThanSomethingDone(): void
    {
        $byKey = $this->byKey(SetupChecklist::steps($this->state(['queue_binding' => 'wp_cron_disabled'])));
        self::assertSame(SetupChecklist::TODO, $byKey[SetupChecklist::STEP_QUEUE]['status']);
        self::assertSame('wp_cron_disabled', $byKey[SetupChecklist::STEP_QUEUE]['blocker']);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function state(array $overrides = []): array
    {
        return array_merge([
            'woocommerce_active' => true,
            'commission_rate_bp' => 1000,
            'commission_rules' => 0,
            'document_types' => 2,
            'settlement_delay_days' => 7,
            'max_staff' => 5,
            'vendor_page_reachable' => true,
            'queue_binding' => 'action_scheduler',
            'decision_open' => true,
        ], $overrides);
    }

    /**
     * @param list<array{key:string, status:string, blocker:string, target:string}> $steps
     * @return array<string,array{key:string, status:string, blocker:string, target:string}>
     */
    private function byKey(array $steps): array
    {
        $out = [];
        foreach ($steps as $step) {
            $out[$step['key']] = $step;
        }
        return $out;
    }
}
