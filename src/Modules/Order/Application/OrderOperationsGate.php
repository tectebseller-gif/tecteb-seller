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
 * So the gate asks a question that has to be answered in the world rather
 * than in a setting: **can a balanced ledger transaction actually be
 * WRITTEN?** (FIN-03/§4.4 — a ledger that refuses inserts means nothing can
 * be recorded.) The probe writes and then leaves nothing behind: it uses a
 * reserved event key and a zero-sum pair, so asking the question never
 * invents money.
 *
 * **It used to ask a second one, and `alpha.57` removed it.** The first
 * question was «does a commission rate actually resolve for this sale?»,
 * because FIN-02 said an unset rate was not zero and an unconfigured
 * marketplace must not sell. The owner has now decided the opposite, per
 * product: a commission is Tecteb's share, it is set on the product, and
 * «تعیین‌نشده یا صفر یعنی بدون کمیسیون» — a product with no commission is
 * approvable, publishable and sellable. So `rate_unset` is no longer produced
 * anywhere, which is the one blocker §7 asked to clear.
 *
 * Nothing else moved. DEC-02 and DEC-04 are still the owner's and still close
 * this gate, and the ledger probe still runs.
 */
final class OrderOperationsGate
{
    public const PROBE_EVENT_PREFIX = 'tmc-gate-probe:';

    public const READY = 'ready';
    public const READY_ON_TRIAL = 'ready_on_trial';
    /**
     * @deprecated since `alpha.57` — kept as a NAME, never produced.
     *
     * This gate's first question used to be «does a commission rate actually
     * resolve for this sale?», because FIN-02 said an unset rate was not zero
     * and an unconfigured marketplace must not sell. The owner has now decided
     * the opposite, per product: «تعیین‌نشده یا صفر یعنی بدون کمیسیون» and
     * «نبود نرخ مانع تأیید، انتشار یا فروش نیست». So the check is gone — the
     * ONE blocker §7 asked to clear — and the constant stays so that a stored
     * reason, a log line or a screen written against the old name still reads
     * as what it was rather than as an unknown string.
     *
     * Nothing else was opened. DEC-02 and DEC-04 are still the owner's and
     * still close this gate, and the ledger probe still runs: «سایر دروازه‌های
     * مستقل فروش، پرداخت، مهاجرت یا تسویه را خودکار باز نکن».
     */
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
        /**
         * NO LONGER CONSULTED, and kept rather than removed.
         *
         * Two reasons, both deliberate. The evidence probes of `alpha.38` and
         * `alpha.39` build this gate positionally and a dropped parameter
         * would stop them loading — the `alpha.32` trap, which this repository
         * has paid for twice. And the scoped rules are still real data that
         * explains lines recorded before this round; what changed is that they
         * no longer decide whether a sale may happen.
         */
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
        // No rate in the detail any more: there is no single rate for a sale
        // under the per-product model, and printing the one a legacy scope
        // rule happens to hold would describe a rule nothing consults.
        return ['ready' => true, 'reason' => self::READY, 'detail' => 'ledger accepts writes'];
    }

    /**
     * Every unmet requirement — cheapest question first, and no database
     * work while the answer cannot change.
     *
     * While DEC-02 and DEC-04 are open and trial mode is off, the module is
     * blocked whatever else is true, so asking the ledger would cost a query
     * on every request to learn something already known. Once that is settled
     * (or waived for a trial), the ledger is asked — and the commission is
     * not: a product with none is not a blocked sale any more.
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
