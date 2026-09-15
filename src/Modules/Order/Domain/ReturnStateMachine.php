<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Domain;

/**
 * The moves a return may make, and the one that may only ever happen once.
 *
 * `received → refunded` is the money. It is reachable from exactly one state
 * and leads nowhere, so "refund it again" is not a transition that exists —
 * before any check, any lock or any button is considered. The database says
 * the same thing a second way (a unique index on the reversal event key), and
 * two answers to "can this be refunded twice" is the right number for money.
 *
 * Rejecting and cancelling are terminal too: a rejected return that could be
 * re-opened would let the same goods be counted against the line twice.
 */
final class ReturnStateMachine
{
    /** @var array<string, list<string>> */
    private const ALLOWED = [
        'requested' => ['approved', 'rejected', 'cancelled'],
        'approved' => ['received', 'cancelled'],
        'received' => ['refunded'],
        'refunded' => [],
        'rejected' => [],
        'cancelled' => [],
    ];

    public function canMove(ReturnStatus $from, ReturnStatus $to): bool
    {
        return in_array($to->value, self::ALLOWED[$from->value] ?? [], true);
    }

    /** @return list<ReturnStatus> */
    public function nextFrom(ReturnStatus $from): array
    {
        $next = [];
        foreach (self::ALLOWED[$from->value] ?? [] as $value) {
            $status = ReturnStatus::tryFrom($value);
            if ($status !== null) {
                $next[] = $status;
            }
        }
        return $next;
    }

    /** The one transition that moves money, named so callers can be explicit. */
    public function movesMoney(ReturnStatus $to): bool
    {
        return $to === ReturnStatus::Refunded;
    }
}
