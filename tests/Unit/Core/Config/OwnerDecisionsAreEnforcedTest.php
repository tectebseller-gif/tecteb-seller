<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Core\Config\OwnerDecisions;
use Tecteb\Marketplace\Modules\Finance\Application\SettlementGate;
use Tecteb\Marketplace\Modules\Order\Application\OrderTrialInterface;
use Tecteb\Marketplace\Modules\Finance\Application\VendorWithdrawalPolicy;
use Tecteb\Marketplace\Modules\Order\Application\OrderOperationsGate;
use Tecteb\Marketplace\Modules\Order\Domain\SettlementEligibility;
use Tecteb\Marketplace\Tests\Support\FakeDokanPresence;
use Tecteb\Marketplace\Tests\Support\FakeTrialUnlock;
use Tecteb\Marketplace\Tests\Unit\Modules\Order\FakeLedgerForGate;
use Tecteb\Marketplace\Tests\Unit\Modules\Order\FakeRulesForGate;
use Tecteb\Marketplace\Modules\Finance\Application\ResolveCommissionRate;

/**
 * WHAT THIS PROVES: the owner answered three decisions, exactly one gate
 * opened, and the other two are shut for a reason that says so.
 *
 * §1 is explicit about why a test like this is needed: «صرف حذف شناسه‌ها از
 * فهرست `OPEN_DECISIONS` کافی نیست. باید ثابت شود قواعد انتخاب‌شده در
 * محاسبات، وضعیت‌ها، آزمون‌ها و گزارش‌ها اعمال می‌شوند.» So nothing here reads
 * `OwnerDecisions` and checks it against itself — a record that claims a rule
 * is applied is not a rule being applied (`alpha.92`). Every assertion RUNS
 * the class that enforces the answer.
 *
 * ⛔ **The shape that matters is the separation.** Three gates were shut and
 * two of them named DEC-02. A change that empties one list and accidentally
 * opens the others would look exactly like this one from the outside, which
 * is why «the order gate opened» and «settlement did not» are asserted in the
 * same test rather than in two.
 */
final class OwnerDecisionsAreEnforcedTest extends TestCase
{
    /** DEC-02 and DEC-04: answered, so the order gate stops refusing. */
    public function testTheOrderGateIsOpenWithNoTrialAndNoDokan(): void
    {
        self::assertSame([], OrderOperationsGate::OPEN_DECISIONS);

        $gate = new OrderOperationsGate(
            new ResolveCommissionRate(new FakeRulesForGate()),
            new FakeLedgerForGate(),
            new FakeTrialUnlock(false),
            new FakeDokanPresence(false)
        );

        $answer = $gate->check();
        self::assertTrue($answer['ready'], 'the owner answered DEC-02 and DEC-04');
        self::assertSame(OrderOperationsGate::READY, $answer['reason']);
        self::assertSame([], $gate->blockers());
    }

    /**
     * FIN-04: answered «stays closed», which is NOT the same as unanswered —
     * and the reason code is how a screen can tell.
     */
    public function testSettlementStaysClosedAndSaysItIsADecision(): void
    {
        $gate = new SettlementGate(new FakeTrialUnlock(false));

        $answer = $gate->check();
        self::assertFalse($answer['ready'], 'automatic settlement is closed for v1');
        self::assertSame(
            SettlementGate::CLOSED_BY_OWNER,
            $answer['reason'],
            'shut by decision, not awaiting one'
        );
        self::assertNotSame(SettlementGate::DECISIONS_OPEN, $answer['reason']);
        self::assertStringContainsString(OwnerDecisions::FIN_04, $answer['detail']);
    }

    /**
     * And the blocker list carries no dangling colon now that the list it used
     * to join is empty.
     */
    public function testTheSettlementBlockerIsOneWordWithNothingAfterIt(): void
    {
        $blockers = (new SettlementGate(new FakeTrialUnlock(false)))->blockers();

        self::assertSame([SettlementGate::CLOSED_BY_OWNER], $blockers);
        foreach ($blockers as $blocker) {
            self::assertStringEndsNotWith(':', $blocker, 'a reason with a colon and nothing after it');
        }
    }

    /**
     * The vendor's own request path: untouched by any of this.
     *
     * The owner's §1 keeps it closed on POST and API as well as in the view,
     * and the enforcement is in `RequestWithdrawal`. This asserts the policy
     * the service reads — `SettlementFlowTest` runs the service itself against
     * a real database.
     */
    public function testWithdrawalRequestsStayClosed(): void
    {
        $policy = new VendorWithdrawalPolicy();

        self::assertFalse($policy->requestsAreOpen(), 'opening the order gate did not open this');
        self::assertFalse($policy->cancelIsOpen());
    }

    /**
     * DEC-02's mechanism, run: a manager may record completion only on an
     * order WooCommerce calls paid.
     *
     * The pure rule is asserted here over every status the release maps, and
     * `ManageOrderItems` is run against it in the database suite. The point of
     * doing it by disposition rather than by one example is that a future
     * status added to `WcOrderLifecycle::PAID` would silently become
     * settleable, and this is where that shows up.
     */
    public function testOnlyAPaidOrderMayBeDeclaredCompleteForSettlement(): void
    {
        foreach (['processing', 'completed'] as $paid) {
            self::assertTrue(SettlementEligibility::forCompletion($paid)['ok'], $paid);
        }
        $refused = [
            'pending' => SettlementEligibility::NOT_RECEIVED,
            'on-hold' => SettlementEligibility::NOT_RECEIVED,
            'failed' => SettlementEligibility::NOT_RECEIVED,
            'cancelled' => SettlementEligibility::NOT_RECEIVED,
            'checkout-draft' => SettlementEligibility::NOT_RECEIVED,
            'refunded' => SettlementEligibility::REFUND_NEEDS_DECISION,
            'wc-awaiting-shipment' => SettlementEligibility::STATUS_UNKNOWN,
        ];
        foreach ($refused as $status => $reason) {
            $answer = SettlementEligibility::forCompletion($status);
            self::assertFalse($answer['ok'], $status);
            self::assertSame($reason, $answer['reason'], $status);
        }
    }

    /**
     * ⛔ And the direction that must never be «allow»: a status nobody
     * recorded.
     *
     * `null` is what both sources answer when the column has no backfill and
     * the live reader is unwired or cannot reach WooCommerce. Reading it as
     * «not paid» would be right today and wrong the moment it is not, so it
     * is refused under its own name.
     */
    public function testAStatusNobodyRecordedIsRefusedUnderItsOwnName(): void
    {
        foreach ([null, '', '   '] as $nothing) {
            $answer = SettlementEligibility::forCompletion($nothing);
            self::assertFalse($answer['ok']);
            self::assertSame(SettlementEligibility::STATUS_UNRECORDED, $answer['reason']);
            self::assertNotSame(
                SettlementEligibility::NOT_RECEIVED,
                $answer['reason'],
                '«nobody told us» is not «not paid»'
            );
        }
    }

    /** Clearing a completion is refused by nothing: it only removes money. */
    public function testEveryRefusalHasASentenceOfItsOwn(): void
    {
        $messages = file_get_contents(
            __DIR__ . '/../../../../src/Modules/Order/Presentation/OrderMessages.php'
        );
        self::assertIsString($messages);
        foreach (SettlementEligibility::refusals() as $reason) {
            self::assertStringContainsString(
                "'" . $reason . "' =>",
                $messages,
                $reason . ' reaches a manager as a reason code with no Persian sentence'
            );
        }
    }

    /**
     * ⛔ THE NEGATIVE §6-8 ASKS FOR, AND THE SHARPEST ONE LEFT: the trial
     * switch does not open what the owner closed.
     *
     * `SettlementGate` still honours the trial, on purpose — a disposable site
     * has to be able to exercise the settlement path end to end, and
     * `SettlementFlowTest` does exactly that. But «the owner decided automatic
     * settlement stays closed» would be worth nothing if a trial could be
     * granted on the owner's real site, so the two halves are measured
     * together here:
     *
     *  - a trial that IS active waives it and says so, in `READY_ON_TRIAL`,
     *    with «closed by the owner for v1» in the detail rather than a claim
     *    that the decision is open;
     *  - a trial that is REFUSED does not, and the refusal reaches the answer
     *    rather than being swallowed into `CLOSED_BY_OWNER`.
     *
     * And the environment half — that a production-resolved site refuses the
     * switch outright, and that an UNDECLARED environment is refused like
     * production — is `TrialUnlockRequestTest`, which is where the resolver
     * lives. This test is about what the gate does with each answer.
     */
    public function testTheTrialSwitchDoesNotOpenWhatTheOwnerClosed(): void
    {
        // Refused: the gate must not read a refusal as «no trial asked».
        $refused = (new SettlementGate(new class implements OrderTrialInterface {
            public function isActive(): bool
            {
                return false;
            }

            public function refusal(): string
            {
                return 'host_changed';
            }

            public function environmentName(): string
            {
                return 'production';
            }
        }))->check();
        self::assertFalse($refused['ready']);
        self::assertSame(SettlementGate::TRIAL_REFUSED, $refused['reason']);
        self::assertStringContainsString('host_changed', $refused['detail'], 'and it names why');

        // Active: waived, and the detail says the decision is the owner's
        // rather than missing.
        $trialled = (new SettlementGate(new class implements OrderTrialInterface {
            public function isActive(): bool
            {
                return true;
            }

            public function refusal(): string
            {
                return '';
            }

            public function environmentName(): string
            {
                return 'staging';
            }
        }))->check();
        self::assertTrue($trialled['ready']);
        self::assertSame(SettlementGate::READY_ON_TRIAL, $trialled['reason']);
        self::assertStringNotContainsString(
            'DEC-',
            $trialled['detail'],
            'a waived gate must not claim a decision is open'
        );

        // ⛔ AND THE WITHDRAWAL POLICY IS NOT A GATE THE TRIAL CAN REACH.
        // It takes no trial at all — «با کلید آزمایشی هم باز نمی‌شود» is
        // enforced by the constructor taking nothing, which is stronger than
        // any check inside it (`alpha.62`).
        self::assertSame(
            0,
            (new \ReflectionClass(VendorWithdrawalPolicy::class))
                ->getConstructor()?->getNumberOfParameters() ?? 0,
            'nothing can be injected into the withdrawal policy to open it'
        );
        self::assertFalse((new VendorWithdrawalPolicy())->requestsAreOpen());
    }
}
