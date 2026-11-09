<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Order;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Finance\Application\CommissionRuleRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Application\LedgerRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Application\ResolveCommissionRate;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionRate;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerTransaction;
use Tecteb\Marketplace\Modules\Finance\Domain\RateScope;
use Tecteb\Marketplace\Modules\Order\Application\OrderOperationsGate;
use Tecteb\Marketplace\Tests\Support\FakeDokanPresence;
use Tecteb\Marketplace\Tests\Support\FakeTrialUnlock;

/**
 * WHAT THIS PROVES: while Dokan is running, the order gate is shut — and the
 * trial switch cannot open it.
 *
 * The hazard is not hypothetical and it is not about an unmade decision.
 * Dokan attributes a product to a vendor by `post_author`; `TransferOwnership`
 * deliberately never changes it; `CaptureOrder` asks
 * `findByWcProduct()`, which filters on `link_ownership = 'marketplace'`. For
 * the 184 transferred products of shop 102 both answers are yes at the same
 * time, so two systems would record one sale: Dokan its sub-order and
 * commission, this plugin a `tmc_order_items` row and a balanced ledger
 * transaction.
 *
 * What used to stand in the way was `OPEN_DECISIONS` being a `const`, which
 * is a sequencing accident rather than a guard: it stops being in the way the
 * moment the owner closes DEC-02 and DEC-04 — and it never was in the way on
 * a staging site, where the trial switch waives exactly that condition while
 * Dokan is active.
 *
 * ⛔ So the ordering is the test, not an implementation detail: the Dokan
 * question is asked FIRST, and `READY_ON_TRIAL` is unreachable while it
 * answers yes.
 */
final class OrderGateRefusesWhileDokanRunsTest extends TestCase
{
    public function testTheGateIsShutWhileDokanRuns(): void
    {
        $gate = $this->gate(dokanRunning: true, trial: true);

        $answer = $gate->check();
        self::assertFalse($answer['ready']);
        self::assertSame(OrderOperationsGate::DOKAN_RUNNING, $answer['reason']);
        self::assertStringContainsString('class_WeDevs_Dokan', $answer['detail'], 'the screen is told WHICH signal fired');
        self::assertStringContainsString('post_author', $answer['detail'], 'and why both paths claim the product');
    }

    /**
     * The one that matters. Every other reason this gate gives can be waived
     * by a trial, because every other reason is a definition nobody has
     * written down. A second system recording the same sale is not.
     */
    public function testATrialCannotWaiveIt(): void
    {
        $gate = $this->gate(dokanRunning: true, trial: true);

        self::assertNotSame(OrderOperationsGate::READY_ON_TRIAL, $gate->check()['reason']);
        self::assertSame(
            [OrderOperationsGate::DOKAN_RUNNING . ':class_WeDevs_Dokan'],
            $gate->blockers(),
            'and the blocker names the signal, so the remedy is readable'
        );
    }

    /**
     * ⛔ And it is asked BEFORE the ledger probe, which is a write.
     *
     * The probe inserts a balanced zero-sum pair to prove the ledger accepts
     * writes. On a site where this gate is shut for a reason that cannot be
     * waived, asking that question would write a row to answer something
     * already decided — so the ledger double here throws if it is touched at
     * all, and the test passes only because nothing touches it.
     */
    public function testNothingIsWrittenToTheLedgerWhileDokanRuns(): void
    {
        $gate = $this->gate(dokanRunning: true, trial: true, ledger: new class () extends FakeLedgerForGate {
            public function hasEvent(string $eventKey): bool
            {
                throw new \LogicException('the gate asked the ledger while Dokan was running');
            }

            /** @param list<string> $eventKeys
             *  @return list<string> */
            public function eventsPresent(array $eventKeys): array
            {
                // The bulk form, modelled the same way as the single one so the
                // double cannot answer the two questions differently.
                return array_values(array_filter(
                    array_map(static fn ($k): string => (string) $k, $eventKeys),
                    fn (string $key): bool => $this->hasEvent($key)
                ));
            }

            public function eventBalances(array $eventKeys): array
            {
                return [];
            }

            public function record(LedgerTransaction $transaction): bool
            {
                throw new \LogicException('the gate WROTE to the ledger while Dokan was running');
            }
        });

        self::assertSame(OrderOperationsGate::DOKAN_RUNNING, $gate->check()['reason']);
        self::assertSame([OrderOperationsGate::DOKAN_RUNNING . ':class_WeDevs_Dokan'], $gate->blockers());
    }

    /**
     * The control, and it is the point of the whole change: with Dokan gone,
     * the gate answers exactly what it answered before — so this guard does
     * not stand between the owner and the state the migration is for.
     */
    public function testWithDokanGoneTheGateAnswersAsItAlwaysDid(): void
    {
        $gate = $this->gate(dokanRunning: false, trial: true);
        self::assertTrue($gate->check()['ready']);
        self::assertSame(OrderOperationsGate::READY_ON_TRIAL, $gate->check()['reason']);
        self::assertSame([], $gate->blockers());

        $closed = $this->gate(dokanRunning: false, trial: false);
        self::assertFalse($closed->check()['ready']);
        self::assertSame(OrderOperationsGate::DECISIONS_OPEN, $closed->check()['reason']);
    }

    /**
     * An unwired gate reads as «no Dokan», the same posture as `$trial`, so
     * the evidence probes of `alpha.38` and `alpha.39` keep loading with three
     * arguments. The wiring is pinned instead by
     * `OrderGateAsksAboutDokanTest`, because a guard nothing passes is not a
     * guard (`alpha.80`).
     */
    public function testAnUnwiredGateIsUnchanged(): void
    {
        $gate = new OrderOperationsGate(
            new ResolveCommissionRate(new FakeRulesForGate()),
            new FakeLedgerForGate(),
            new FakeTrialUnlock(true)
        );

        self::assertSame(OrderOperationsGate::READY_ON_TRIAL, $gate->check()['reason']);
    }

    private function gate(
        bool $dokanRunning,
        bool $trial,
        ?LedgerRepositoryInterface $ledger = null
    ): OrderOperationsGate {
        return new OrderOperationsGate(
            new ResolveCommissionRate(new FakeRulesForGate()),
            $ledger ?? new FakeLedgerForGate(),
            new FakeTrialUnlock($trial),
            new FakeDokanPresence($dokanRunning)
        );
    }
}

/** A ledger that accepts the probe and remembers nothing else. */
class FakeLedgerForGate implements LedgerRepositoryInterface
{
    public function record(LedgerTransaction $transaction): bool
    {
        return true;
    }

    public function hasEvent(string $eventKey): bool
    {
        return false;
    }

    /** @param list<string> $eventKeys
     *  @return list<string> */
    public function eventsPresent(array $eventKeys): array
    {
        // The bulk form, modelled the same way as the single one so the
        // double cannot answer the two questions differently.
        return array_values(array_filter(
            array_map(static fn ($k): string => (string) $k, $eventKeys),
            fn (string $key): bool => $this->hasEvent($key)
        ));
    }

    public function eventBalances(array $eventKeys): array
    {
        return [];
    }

    /** @return array{currency:string, exponent:int, resolved:bool} */
    public function unitsFor(int $vendorUserId): array
    {
        return ['currency' => 'IRT', 'exponent' => 0, 'resolved' => false];
    }

    /** @return list<int> */
    public function vendorsWithMixedUnits(int $limit = 200): array
    {
        return [];
    }

    /** @return list<array<string,mixed>> */
    public function forVendor(int $vendorUserId, int $limit = 100): array
    {
        return [];
    }

    /** @return list<array<string,mixed>> */
    public function forEvent(string $eventKey): array
    {
        return [];
    }

    public function coversOrder(int $wcOrderId): bool
    {
        return false;
    }

    /** @return array<string,array<string,mixed>> */
    public function balances(int $vendorUserId): array
    {
        return [];
    }
}

/** No rules anywhere: this gate stopped asking in `alpha.57`. */
class FakeRulesForGate implements CommissionRuleRepositoryInterface
{
    public function rate(RateScope $scope, string $reference): CommissionRate
    {
        return CommissionRate::unset();
    }

    public function setRate(RateScope $scope, string $reference, CommissionRate $rate): bool
    {
        return false;
    }

    public function clearRate(RateScope $scope, string $reference): bool
    {
        return false;
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return [];
    }
}
