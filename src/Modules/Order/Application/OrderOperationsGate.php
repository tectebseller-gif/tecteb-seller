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
 * ONE addition since it was written: a disposable site may switch on trial
 * mode and run the whole path with sample rules (TrialUnlock). That waives
 * only the requirement that the open decisions be CLOSED — every other check
 * still runs, the same ledger lines are written, and a production
 * environment refuses the switch outright.
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
    public const READY_ON_TRIAL = 'ready_on_trial';
    public const RATE_UNSET = 'rate_unset';
    public const LEDGER_UNWRITABLE = 'ledger_unwritable';
    public const DECISIONS_OPEN = 'decisions_open';
    public const TRIAL_REFUSED = 'trial_refused';

    /**
     * Decisions the owner has not made yet. They are listed by name rather
     * than hidden behind a boolean, so the health page can say WHICH answer
     * is missing instead of «به دلایلی غیرفعال است».
     *
     * @var list<string>
     */
    public const OPEN_DECISIONS = ['DEC-02', 'DEC-04'];

    /** @var array<string, array{ready:bool, reason:string, detail:string}> */
    private array $memo = [];

    public function __construct(
        private readonly ResolveCommissionRate $rates,
        private readonly LedgerRepositoryInterface $ledger,
        private readonly ?OrderTrialInterface $trial = null
    ) {
    }

    /**
     * @param array<string,string> $rateReferences the scopes a real sale would carry
     * @return array{ready:bool, reason:string, detail:string}
     */
    public function check(array $rateReferences = []): array
    {
        // Memoised per request: the catalogue asks this once per product on
        // a shop page, and the answer cannot change inside one request.
        $memoKey = json_encode($rateReferences, JSON_UNESCAPED_UNICODE) ?: '';
        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }
        $answer = $this->evaluate($rateReferences);
        $this->memo[$memoKey] = $answer;
        return $answer;
    }

    /** @param array<string,string> $rateReferences @return array{ready:bool, reason:string, detail:string} */
    private function evaluate(array $rateReferences): array
    {
        // The decisions come first and cost nothing: while they are open and
        // no trial is running, the module cannot operate whatever the rates
        // say — and this method is called on every product of every shop page.
        if (self::OPEN_DECISIONS !== [] && $this->trial?->isActive() !== true) {
            $refusal = $this->trial?->refusal() ?? '';
            return [
                'ready' => false,
                'reason' => $refusal !== '' ? self::TRIAL_REFUSED : self::DECISIONS_OPEN,
                'detail' => $refusal !== ''
                    ? 'trial mode is requested but refused here: ' . $refusal
                    : 'awaiting ' . implode(', ', self::OPEN_DECISIONS),
            ];
        }
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
            // Reached only on a trial: every other requirement is met and the
            // decisions are waived, so say exactly that.
            return [
                'ready' => true,
                'reason' => self::READY_ON_TRIAL,
                'detail' => 'trial mode on ' . ($this->trial?->environmentName() ?? '')
                    . '; sample rules; still awaiting ' . implode(', ', self::OPEN_DECISIONS),
            ];
        }
        return ['ready' => true, 'reason' => self::READY, 'detail' => 'rate source: ' . $source];
    }

    /**
     * Every unmet requirement — cheapest question first, and no database
     * work while the answer cannot change.
     *
     * While DEC-02 and DEC-04 are open and trial mode is off, the module is
     * blocked whatever the rates say, so asking the ledger would cost a query
     * on every request to learn something already known. Once that is settled
     * (or waived for a trial), the rate and the ledger are both reported, so
     * clearing one does not reveal the next as a surprise.
     *
     * @param array<string,string> $rateReferences
     * @return list<string> empty when the module may operate
     */
    public function blockers(array $rateReferences = []): array
    {
        if (self::OPEN_DECISIONS !== [] && $this->trial?->isActive() !== true) {
            $refusal = $this->trial?->refusal() ?? '';
            return [
                $refusal !== ''
                    ? self::TRIAL_REFUSED . ':' . $refusal
                    : self::DECISIONS_OPEN . ':' . implode(',', self::OPEN_DECISIONS),
            ];
        }
        $blockers = [];
        ['rate' => $rate] = $this->rates->forItem($rateReferences);
        if (!$rate->isSet()) {
            $blockers[] = self::RATE_UNSET;
        }
        if (!$this->ledgerAcceptsWrites()) {
            $blockers[] = self::LEDGER_UNWRITABLE;
        }
        return $blockers;
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
