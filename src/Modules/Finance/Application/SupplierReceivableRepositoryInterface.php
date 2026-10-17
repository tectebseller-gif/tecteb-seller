<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Application;

use Tecteb\Marketplace\Modules\Finance\Domain\SupplierReceivable;

/**
 * What a supplier owes back after a return, and the two ways it stops being
 * owed.
 *
 * Three verbs for the owner's three events, and they are three because §2
 * says so: «ثبت طلب، وصول واقعی و تهاتر سه رویداد متفاوت باشند». `record()`
 * is the debt; `settle()` carries the kind, so a collection and an offset are
 * the same shape of row with different meanings and the same balance holding
 * them apart. Nothing here moves money and nothing here deducts from a future
 * payment on its own — «کسر خودکار انجام نشود».
 */
interface SupplierReceivableRepositoryInterface
{
    public const RECORDED = 'recorded';
    /** The same return and item have a receivable already. */
    public const ALREADY_RECORDED = 'already_recorded';
    public const SETTLED = 'settled';
    /** The same submission again — nothing written, and the first one named. */
    public const ALREADY_SETTLED = 'already_settled';
    /** `settled_so_far + amount` would exceed what was owed. */
    public const ABOVE_BALANCE = 'above_balance';
    public const FAILED = 'storage_failed';

    /**
     * @return array{outcome:string, receivable_id:int}
     */
    public function record(
        int $supplierUserId,
        int $orderItemId,
        int $returnId,
        int $quantity,
        int $amountMinor,
        string $currency,
        int $exponent,
        string $reason,
        int $actorId,
        string $eventKey
    ): array;

    public function find(int $receivableId): ?SupplierReceivable;

    /**
     * Record a collection or an offset against one receivable.
     *
     * The balance guard is inside the writing statement, for the same reason
     * the obligation's is: two managers who both read a balance of 500,000
     * and both record 500,000 must not both land, and a caller that skipped
     * the check must not be able to write either.
     *
     * @param string $kind `collected` or `offset`
     * @return array{outcome:string, settlement_id:int, balance_minor:int}
     */
    public function settle(
        int $receivableId,
        string $kind,
        int $amountMinor,
        int $paymentId,
        string $reference,
        string $note,
        int $actorId,
        string $idempotencyKey
    ): array;

    /**
     * @return list<array{id:int, kind:string, amount_minor:int, payment_id:int, reference:string, recorded_by:int, created_at:string}>
     */
    public function settlementsOf(int $receivableId, int $limit = 50): array;

    /**
     * Receivables of one supplier that still have a balance.
     *
     * @return list<SupplierReceivable>
     */
    public function openFor(int $supplierUserId, int $limit = 50): array;

    /**
     * @return list<SupplierReceivable>
     */
    public function forSupplier(int $supplierUserId, int $limit = 50): array;

    /**
     * Every receivable that still has a balance, whoever owes it.
     *
     * The manager's screen needs this and nothing else does: a page that
     * asked per supplier would only find the suppliers it already knew to
     * ask about, which is how a debt goes unnoticed.
     *
     * @return list<SupplierReceivable>
     */
    public function allOpen(int $limit = 100): array;
}
