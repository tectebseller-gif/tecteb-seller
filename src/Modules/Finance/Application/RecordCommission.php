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
        private readonly AuditLogger $audit
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
        string $discountAllocation = ''
    ): CommissionOutcome {
        if (trim($eventKey) === '') {
            return CommissionOutcome::needsConfiguration('event_key_required');
        }
        ['rate' => $rate, 'source' => $source] = $this->rates->forItem($rateReferences);

        $outcome = $this->calculator->calculate($baseAfterDiscountBeforeTax, $rate, $source, $vendorUserId, $discountAllocation);
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
            $recovered = $this->recover($eventKey);
            return $recovered ?? CommissionOutcome::needsConfiguration('ledger_unwritable');
        }

        $this->audit->log(AuditEventCatalog::FINANCE_ACCRUED, 0, 'ledger', $eventKey, [
            'vendor_id' => $vendorUserId,
            'rate_bp' => (int) $rate->basisPoints,
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
            is_array($decoded) ? CommissionSnapshot::fromArray($decoded) : null
        );
    }
}
