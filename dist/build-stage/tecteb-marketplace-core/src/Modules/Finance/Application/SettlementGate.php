<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Application;

use Tecteb\Marketplace\Modules\Order\Application\OrderTrialInterface;

/**
 * Whether a withdrawal may be created and paid yet.
 *
 * The order gate asks "may money be RECORDED?". This one asks the next
 * question: "may money be PAID OUT?" — and it is not the same question, so it
 * does not share an answer.
 *
 * Two things are missing in the world, not in a setting, and each blocks only
 * what depends on it:
 *
 *   DEC-02  what "a completed order" means in a basket holding three shops.
 *           Until that is decided, no automatic process may declare a sale
 *           settleable. ORDER-01 leaves one door open and this build uses it:
 *           «فقط مدیر یا فرایند معتبر تأییدشده، تکمیل مؤثر در تسویه را ثبت
 *           می‌کند» — a manager may record it by hand, per line. So the
 *           mechanism is complete and measurable while the definition is not.
 *   FIN-04  which event releases the money, and the hold rules around refunds
 *           and disputes (DEC-03's return window feeds this).
 *
 * The four-day delay itself is NOT missing: it is an approved decision
 * (Master §8.3, A.2, F-03) and is read from settings. What is missing is the
 * event it counts from, which is exactly why the manager has to name it.
 *
 * As with the order gate, a disposable site may waive the open decisions with
 * the trial switch — and a production environment refuses that switch.
 */
final class SettlementGate
{
    public const READY = 'ready';
    public const READY_ON_TRIAL = 'ready_on_trial';
    public const DECISIONS_OPEN = 'decisions_open';
    public const TRIAL_REFUSED = 'trial_refused';

    /**
     * Decisions that must close before withdrawals may run for real.
     *
     * @var list<string>
     */
    public const OPEN_DECISIONS = ['DEC-02', 'FIN-04'];

    public function __construct(private readonly ?OrderTrialInterface $trial = null)
    {
    }

    /** @return array{ready:bool, reason:string, detail:string} */
    public function check(): array
    {
        if (self::OPEN_DECISIONS === []) {
            return ['ready' => true, 'reason' => self::READY, 'detail' => ''];
        }
        if ($this->trial?->isActive() === true) {
            return [
                'ready' => true,
                'reason' => self::READY_ON_TRIAL,
                'detail' => 'trial mode on ' . $this->trial->environmentName()
                    . '; still awaiting ' . implode(', ', self::OPEN_DECISIONS),
            ];
        }
        $refusal = $this->trial?->refusal() ?? '';
        return [
            'ready' => false,
            'reason' => $refusal !== '' ? self::TRIAL_REFUSED : self::DECISIONS_OPEN,
            'detail' => $refusal !== ''
                ? 'trial mode is requested but refused here: ' . $refusal
                : 'awaiting ' . implode(', ', self::OPEN_DECISIONS),
        ];
    }

    public function isReady(): bool
    {
        return $this->check()['ready'];
    }

    /** @return list<string> every unmet requirement, empty when settlement may run */
    public function blockers(): array
    {
        $answer = $this->check();
        if ($answer['ready']) {
            return [];
        }
        return [$answer['reason'] . ':' . implode(',', self::OPEN_DECISIONS)];
    }
}
