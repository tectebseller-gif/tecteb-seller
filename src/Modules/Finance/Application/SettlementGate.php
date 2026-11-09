<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Application;

use Tecteb\Marketplace\Core\Config\OwnerDecisions;
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
     * Decisions that must close before withdrawals may run for real — **empty
     * now, because both of them have been answered.**
     *
     * DEC-02's answer is the mechanism this build already had: a manager
     * records effective completion per line (ORDER-01). FIN-04's answer is
     * «automatic settlement stays closed in v1».
     *
     * ⛔ **So this gate is still shut, and for a different reason.** That
     * difference is the whole point of emptying the list: a screen that says
     * «awaiting DEC-02, FIN-04» tells a manager to go and decide something
     * that is already decided. `CLOSED_BY_OWNER` says what is true instead —
     * the owner chose to keep automatic settlement closed — and the remedy is
     * a new decision rather than the same one.
     *
     * @var list<string>
     */
    public const OPEN_DECISIONS = [];

    /**
     * Automatic settlement, as the owner's standing decision.
     *
     * The same shape as `VendorWithdrawalPolicy::OPEN` and for the reason
     * written there: a setting would be a second place the answer lives and a
     * thing somebody could flip without the decision changing. When the owner
     * opens automatic settlement this constant changes in a build, with a
     * delivery note, and `OwnerDecisionsAreEnforcedTest` is what notices if it
     * changes by accident.
     */
    private const AUTOMATIC_SETTLEMENT_OPEN = false;

    /** Shut because the owner decided it stays shut — not because nobody has decided. */
    public const CLOSED_BY_OWNER = 'settlement_closed_by_owner';

    public function __construct(private readonly ?OrderTrialInterface $trial = null)
    {
    }

    /** @return array{ready:bool, reason:string, detail:string} */
    public function check(): array
    {
        if (self::OPEN_DECISIONS === [] && self::AUTOMATIC_SETTLEMENT_OPEN) {
            return ['ready' => true, 'reason' => self::READY, 'detail' => ''];
        }
        // The trial switch still waives this, and that is deliberate rather
        // than an oversight. It is refused outright on a production-resolved
        // site and it carries that site's own address (`alpha.98`), so what it
        // opens is a disposable environment where the settlement path can be
        // measured at all. Taking the waiver away would leave the whole of
        // `SettlementFlowTest` and every evidence run with no way to exercise
        // a path the owner will eventually open.
        if ($this->trial?->isActive() === true) {
            return [
                'ready' => true,
                'reason' => self::READY_ON_TRIAL,
                'detail' => 'trial mode on ' . $this->trial->environmentName()
                    . '; automatic settlement is closed by the owner for v1',
            ];
        }
        $refusal = $this->trial?->refusal() ?? '';
        if ($refusal !== '') {
            return [
                'ready' => false,
                'reason' => self::TRIAL_REFUSED,
                'detail' => 'trial mode is requested but refused here: ' . $refusal,
            ];
        }
        // ⛔ Not `DECISIONS_OPEN`. Nothing here is awaiting an answer: the
        // answer is «بسته بماند».
        return self::OPEN_DECISIONS === []
            ? [
                'ready' => false,
                'reason' => self::CLOSED_BY_OWNER,
                'detail' => 'automatic settlement is closed by the owner for v1 (' . OwnerDecisions::FIN_04 . ')',
            ]
            : [
                'ready' => false,
                'reason' => self::DECISIONS_OPEN,
                'detail' => 'awaiting ' . implode(', ', self::OPEN_DECISIONS),
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
        // The names only when there ARE names. Joining an empty list produced
        // «decisions_open:» — a reason with a colon and nothing after it,
        // which is how a screen ends up printing a blank where the remedy
        // should be.
        return self::OPEN_DECISIONS === []
            ? [$answer['reason']]
            : [$answer['reason'] . ':' . implode(',', self::OPEN_DECISIONS)];
    }
}
