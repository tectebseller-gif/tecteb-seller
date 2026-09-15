<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Domain;

/**
 * Which moves a withdrawal may make — FIN-05's path, and nothing else.
 *
 * Two edges are deliberately absent and both would be bugs:
 *
 *  - There is no way back from `paid`. A paid transfer is a fact in the world;
 *    a mistake after it is corrected with a compensating entry, never by
 *    rewriting the request (§4.4: «مبلغ هر خط دفترکل تغییرناپذیر است»).
 *  - There is no automatic way out of `reconciliation_required`. It is reached
 *    when the transfer's outcome is unknown, and the only exits are a human
 *    deciding it did happen (`paid`) or that it did not (`approved`, back in
 *    the queue). Nothing here may retry it by itself.
 */
final class WithdrawalStateMachine
{
    /** @var array<string, list<string>> */
    private const ALLOWED = [
        'requested' => ['reviewing', 'rejected', 'cancelled'],
        'reviewing' => ['approved', 'rejected', 'cancelled'],
        'approved' => ['payment_in_progress', 'rejected', 'cancelled'],
        'payment_in_progress' => ['paid', 'reconciliation_required'],
        'reconciliation_required' => ['paid', 'approved'],
        'paid' => [],
        'rejected' => [],
        'cancelled' => [],
    ];

    public function canMove(WithdrawalStatus $from, WithdrawalStatus $to): bool
    {
        return in_array($to->value, self::ALLOWED[$from->value] ?? [], true);
    }

    /** @return list<WithdrawalStatus> */
    public function nextFrom(WithdrawalStatus $from): array
    {
        $next = [];
        foreach (self::ALLOWED[$from->value] ?? [] as $value) {
            $status = WithdrawalStatus::tryFrom($value);
            if ($status !== null) {
                $next[] = $status;
            }
        }
        return $next;
    }

    /** The vendor's own move: cancelling their request while it is still free. */
    public function vendorMayCancel(WithdrawalStatus $from): bool
    {
        return $this->canMove($from, WithdrawalStatus::Cancelled);
    }
}
