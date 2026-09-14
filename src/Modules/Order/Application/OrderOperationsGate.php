<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Application;

use Tecteb\Marketplace\Modules\Finance\Application\LedgerRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Application\ResolveCommissionRate;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerAccount;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerTransaction;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;

/**
 * Whether this build may operate on real orders yet.
 *
 * The owner's instruction is the whole design here: **hiding the numbers is
 * not enough**. An order module that accepted a paid order and showed no
 * money would still have created an obligation nobody recorded — the vendor
 * would be owed something with no ledger line behind it, and no later
 * process could reconstruct what.
 *
 * So the gate asks two questions that have to be answered in the world, not
 * in a setting:
 *
 *   1. does a commission rate actually RESOLVE for this sale? (FIN-02 —
 *      unset is not zero, so an unconfigured marketplace cannot sell)
 *   2. can a balanced ledger transaction actually be WRITTEN? (FIN-03/§4.4 —
 *      a ledger that refuses inserts means nothing can be recorded)
 *
 * Only when both are true may order operations run. The probe writes and then
 * leaves nothing behind: it uses a reserved event key and a zero-sum pair, so
 * asking the question never invents money.
 */
final class OrderOperationsGate
{
    public const PROBE_EVENT_PREFIX = 'tmc-gate-probe:';

    public const READY = 'ready';
    public const RATE_UNSET = 'rate_unset';
    public const LEDGER_UNWRITABLE = 'ledger_unwritable';
    public const DECISIONS_OPEN = 'decisions_open';

    /**
     * Decisions the owner has not made yet. They are listed by name rather
     * than hidden behind a boolean, so the health page can say WHICH answer
     * is missing instead of «به دلایلی غیرفعال است».
     *
     * @var list<string>
     */
    public const OPEN_DECISIONS = ['DEC-02', 'DEC-04'];

    public function __construct(
        private readonly ResolveCommissionRate $rates,
        private readonly LedgerRepositoryInterface $ledger
    ) {
    }

    /**
     * @param array<string,string> $rateReferences the scopes a real sale would carry
     * @return array{ready:bool, reason:string, detail:string}
     */
    public function check(array $rateReferences = []): array
    {
        ['rate' => $rate, 'source' => $source] = $this->rates->forItem($rateReferences);
        if (!$rate->isSet()) {
            return [
                'ready' => false,
                'reason' => self::RATE_UNSET,
                'detail' => 'no commission rate resolves for this sale; an unset rate is not zero',
            ];
        }
        if (!$this->ledgerAcceptsWrites()) {
            return [
                'ready' => false,
                'reason' => self::LEDGER_UNWRITABLE,
                'detail' => 'the ledger refused a balanced probe transaction',
            ];
        }
        if (self::OPEN_DECISIONS !== []) {
            return [
                'ready' => false,
                'reason' => self::DECISIONS_OPEN,
                'detail' => 'awaiting ' . implode(', ', self::OPEN_DECISIONS),
            ];
        }
        return ['ready' => true, 'reason' => self::READY, 'detail' => 'rate source: ' . $source];
    }

    /**
     * Writes one balanced pair of zero-sum lines under a reserved key.
     *
     * A probe that only READ would answer a different question — a ledger can
     * be readable and still reject inserts (a missing table, a full disk, a
     * revoked grant), and that is exactly the failure this gate exists to
     * catch before an order depends on it.
     */
    private function ledgerAcceptsWrites(): bool
    {
        $key = self::PROBE_EVENT_PREFIX . 'v1';
        if ($this->ledger->hasEvent($key)) {
            return true;      // an earlier probe already proved it
        }
        $one = Money::of(1);
        $probe = (new LedgerTransaction($key, 0, 'gate-probe', 'gate-probe'))
            ->add(LedgerAccount::CentralPayment, $one, 'gate_probe')
            ->add(LedgerAccount::VendorEarning, $one->negate(), 'gate_probe');

        return $this->ledger->record($probe);
    }
}
