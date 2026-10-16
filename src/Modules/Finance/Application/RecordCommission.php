<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Application;

use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionCalculator;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionOutcome;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionSnapshot;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerAccount;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerTransaction;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Finance\Domain\TectebCommission;
use Tecteb\Marketplace\Modules\Finance\Domain\TectebCommissionCalculator;

/**
 * Turns one paid item into four balanced ledger lines — once.
 *
 * The event key is the whole idempotency story (FIN-03): a duplicate payment
 * callback, a cron that runs twice and two workers racing each other all
 * produce the same key, and the unique index means only one of them writes.
 * The others are told the money was already recorded, which is the truth, not
 * an error.
 *
 * Nothing here decides WHEN an item is paid or complete — that is the order
 * module's job and it is blocked on open decisions. This service is the part
 * that can be built and tested today, and it refuses to run on an unset rate.
 */
final class RecordCommission
{
    public function __construct(
        private readonly LedgerRepositoryInterface $ledger,
        private readonly ResolveCommissionRate $rates,
        private readonly CommissionCalculator $calculator,
        private readonly AuditLogger $audit,
        /**
         * THE OWNER'S PER-PRODUCT COMMISSION — the model a new sale uses.
         *
         * When this is present, `accrue()` asks it and `ResolveCommissionRate`
         * is not consulted at all. That is the decision, not an option: a
         * general or vendor-level percentage standing in for a product with no
         * commission is the silent substitution §5-و forbids, and on this site
         * there has been a default rate since `alpha.6`, so every product
         * would inherit one.
         *
         * The scoped rules are NOT deleted and `calculate()` is NOT changed:
         * lines already recorded are explained by the snapshot they were
         * written with, and «اطلاعات مالی قبلی پاک یا بازنویسی نشود» is about
         * exactly that. What changes is which model decides the NEXT sale.
         *
         * Optional, and the reason is narrow: the evidence probes of
         * `alpha.38` and `alpha.39` build this service by reflection and a
         * required argument would stop them loading — the `alpha.32` trap. The
         * real container always provides it, and
         * `CommissionModelWiringTest` is the proof rather than this sentence.
         */
        private readonly ?TectebCommissionSourceInterface $tectebRules = null,
        private readonly ?TectebCommissionCalculator $tectebCalculator = null
    ) {
    }

    /**
     * @param array<string,string> $rateReferences see ResolveCommissionRate
     * @param Money $baseAfterDiscountBeforeTax B — excludes tax and shipping
     * @param Money|null $taxCollected recorded separately, never in the base
     */
    public function accrue(
        string $eventKey,
        int $vendorUserId,
        string $orderRef,
        string $itemRef,
        Money $baseAfterDiscountBeforeTax,
        array $rateReferences,
        ?Money $taxCollected = null,
        string $discountAllocation = '',
        /**
         * How many units the line carried — the multiplier a fixed commission
         * needs. `۲۰٬۰۰۰ × ۳ = ۶۰٬۰۰۰` is not recoverable from the line total
         * alone, so it travels and it is recorded in the snapshot.
         */
        int $quantity = 1,
        /** The product the rule is read off, and the variation that was sold. */
        int $wcProductId = 0,
        int $variationId = 0
    ): CommissionOutcome {
        if (trim($eventKey) === '') {
            return CommissionOutcome::needsConfiguration('event_key_required');
        }

        // THE EXISTING EVENT FIRST, BEFORE THE RATE.
        //
        // `alpha.39` resolved the rate, calculated, attempted the write, and
        // recovered only when the unique index refused it. So a sound document
        // already on the books became unrecoverable the moment its rate was
        // changed or removed: `calculate()` answered `needsConfiguration` and
        // this method returned there, never reaching the recovery. A retry of
        // a line whose money was correctly recorded then reported «rate
        // unknown» — about figures sitting in the ledger.
        //
        // Asked first, and nothing about today's configuration is consulted on
        // the way: «رویداد موجود پیش از وابستگی به نرخ فعلی بررسی شود».
        $recovered = $this->recover($eventKey);
        if ($recovered !== null) {
            return $recovered;
        }

        if ($this->tectebRules !== null && $this->tectebCalculator !== null) {
            // The owner's model: this product's own rule, or none, and «none»
            // is a result rather than a refusal — a product with no commission
            // still sells («نبود نرخ مانع … فروش نیست»).
            ['rule' => $rule, 'source' => $source] = $this->tectebRules->forItem($wcProductId, $variationId);
            $outcome = $this->tectebCalculator->calculate(
                $baseAfterDiscountBeforeTax,
                $quantity,
                $rule,
                $source,
                $vendorUserId,
                $discountAllocation
            );
            $rateBp = $rule->type === TectebCommission::PERCENT ? (int) $rule->percentBasisPoints : 0;
        } else {
            ['rate' => $rate, 'source' => $source] = $this->rates->forItem($rateReferences);
            $outcome = $this->calculator->calculate($baseAfterDiscountBeforeTax, $rate, $source, $vendorUserId, $discountAllocation);
            $rateBp = (int) $rate->basisPoints;
        }
        if (!$outcome->isCalculated()) {
            return $outcome;
        }

        $base = $outcome->base;
        $tax = $taxCollected ?? $base->zero();
        $transaction = (new LedgerTransaction($eventKey, $vendorUserId, $orderRef, $itemRef))
            ->snapshot($outcome->snapshot->toArray())
            ->add(LedgerAccount::CentralPayment, $base->add($tax), 'item_paid')
            ->add(LedgerAccount::Commission, $outcome->commission->negate(), 'commission_due')
            ->add(LedgerAccount::VendorEarning, $outcome->vendorShare->negate(), 'vendor_earned')
            ->add(LedgerAccount::TaxCollected, $tax->negate(), 'tax_collected');

        if (!$transaction->balances()) {
            return CommissionOutcome::needsConfiguration('unbalanced_transaction');
        }
        if (!$this->ledger->record($transaction)) {
            // THREE answers here, not one. `record()` is a `bool` and says
            // `false` both for «the unique index refused a duplicate» and for
            // «the write failed», and `alpha.38` called both of them
            // `already_recorded`. The caller then wrote an order line with a
            // null commission and an empty event reference and skipped the
            // line for ever — with a perfectly good ledger event sitting
            // beside it.
            //
            // So the ledger is asked. If the event is there, these are its
            // figures and they are recovered FROM IT, not recomputed at
            // today's rate. If it is not, the write genuinely failed and that
            // is a different word.
            // Asked again, and this is no longer the only place it is asked:
            // the read at the top covers a retry, and this covers the race —
            // two callbacks whose reads both missed and whose writes collided
            // on the unique index. One of them lands, the other recovers.
            $recovered = $this->recover($eventKey);
            return $recovered ?? CommissionOutcome::needsConfiguration('ledger_unwritable');
        }

        $this->audit->log(AuditEventCatalog::FINANCE_ACCRUED, 0, 'ledger', $eventKey, [
            'vendor_id' => $vendorUserId,
            'rate_bp' => $rateBp,
            'rate_source' => $source,
            'base_minor' => $base->minor,
        ]);
        return $outcome;
    }

    /**
     * The figures of an event already in the ledger, for a caller that has not
     * tried to write it.
     *
     * The same recovery `accrue()` falls back to, named and public, because a
     * repair asks exactly this question: «آنچه ثبت شده چه بود؟». It resolves
     * no rate and writes nothing — a rate resolved today would rewrite what
     * was agreed at the time — and answers `null` when there is no such event,
     * which is «there is nothing to recover from», not «zero».
     */
    public function recoverRecorded(string $eventKey): ?CommissionOutcome
    {
        return trim($eventKey) === '' ? null : $this->recover($eventKey);
    }

    /**
     * The figures of an event already in the ledger, read back off its rows.
     *
     * `item_paid` is base + tax, so the base is recovered by subtracting the
     * tax line rather than by trusting the caller's argument — the point of a
     * recovery is to report what was RECORDED, and a caller whose input had
     * changed would otherwise be believed.
     *
     * Returns null when there is no such event, which is the one case that
     * means the write failed.
     */
    private function recover(string $eventKey): ?CommissionOutcome
    {
        $entries = $this->ledger->forEvent($eventKey);
        if ($entries === []) {
            return null;
        }
        $paid = null;
        $commission = null;
        $vendorShare = null;
        $tax = null;
        $snapshotJson = '';
        foreach ($entries as $entry) {
            if ($snapshotJson === '' && $entry->snapshotJson !== '') {
                $snapshotJson = $entry->snapshotJson;
            }
            switch ($entry->reason) {
                case 'item_paid':
                    $paid = $entry->amount;
                    break;
                case 'commission_due':
                    $commission = $entry->amount->negate();
                    break;
                case 'vendor_earned':
                    $vendorShare = $entry->amount->negate();
                    break;
                case 'tax_collected':
                    $tax = $entry->amount->negate();
                    break;
            }
        }
        if ($paid === null || $commission === null || $vendorShare === null) {
            // An event with rows but not THESE rows is not a commission
            // accrual. Reported as a failure to recover rather than guessed at.
            return null;
        }
        $base = $tax === null ? $paid : $paid->subtract($tax);
        $decoded = $snapshotJson === '' ? null : json_decode($snapshotJson, true);
        return CommissionOutcome::recovered(
            $base,
            $commission,
            $vendorShare,
            is_array($decoded) ? CommissionSnapshot::fromArray($decoded) : null,
            // The tax the EVENT carried, so the caller can write the line's own
            // figures rather than mixing recovered money with fresh input.
            // `null` when the event has no tax line, which is a real state: a
            // zero tax adds no row (`LedgerTransaction::add()` drops zeroes).
            $tax ?? $base->zero()
        );
    }
}
