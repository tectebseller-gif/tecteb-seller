<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Application;

use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionCalculator;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionOutcome;
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
            ->add(LedgerAccount::CentralPayment, $base->add($tax), 'item_paid')
            ->add(LedgerAccount::Commission, $outcome->commission->negate(), 'commission_due')
            ->add(LedgerAccount::VendorEarning, $outcome->vendorShare->negate(), 'vendor_earned')
            ->add(LedgerAccount::TaxCollected, $tax->negate(), 'tax_collected');

        if (!$transaction->balances()) {
            return CommissionOutcome::needsConfiguration('unbalanced_transaction');
        }
        if (!$this->ledger->record($transaction)) {
            return CommissionOutcome::needsConfiguration('already_recorded');
        }

        $this->audit->log(AuditEventCatalog::FINANCE_ACCRUED, 0, 'ledger', $eventKey, [
            'vendor_id' => $vendorUserId,
            'rate_bp' => (int) $rate->basisPoints,
            'rate_source' => $source,
            'base_minor' => $base->minor,
        ]);
        return $outcome;
    }
}
