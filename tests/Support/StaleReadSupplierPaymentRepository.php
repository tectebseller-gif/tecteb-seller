<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Modules\Finance\Application\SupplierPaymentRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Domain\SupplierPayment;

/**
 * A real repository whose UNLOCKED read is out of date and whose LOCKED read
 * is not — which is exactly what another request committing in the gap looks
 * like from inside the code under test.
 *
 * **Why not a second process here.** `tests/Support/concurrent-supplier-reconcile.php`
 * exists for that and it is the right tool when the question is «can two
 * requests really interleave»; `tools/alpha64-reproduction.sh` asks it that
 * way, on the delivered bytes. This question is smaller and it is the one a
 * suite can pin: once the two readings disagree, WHICH ONE does the decision
 * come from. A test that forks and waits is a test that passes on a bad build
 * one run in five, and a stale snapshot is the same divergence without the
 * clock.
 *
 * So `find()` answers from the snapshot taken when `freeze()` was called and
 * everything else — `findForUpdate()` included — goes to the real one.
 */
final class StaleReadSupplierPaymentRepository implements SupplierPaymentRepositoryInterface
{
    /** @var array<int,SupplierPayment> */
    private array $frozen = [];

    public function __construct(private readonly SupplierPaymentRepositoryInterface $inner)
    {
    }

    /** Remember this payment as the unlocked read will keep reporting it. */
    public function freeze(int $paymentId): void
    {
        $payment = $this->inner->find($paymentId);
        if ($payment !== null) {
            $this->frozen[$paymentId] = $payment;
        }
    }

    public function find(int $paymentId): ?SupplierPayment
    {
        return $this->frozen[$paymentId] ?? $this->inner->find($paymentId);
    }

    public function findForUpdate(int $paymentId): array
    {
        return $this->inner->findForUpdate($paymentId);
    }

    public function record(SupplierPayment $payment, array $itemShares, array $partialItemIds = []): array
    {
        return $this->inner->record($payment, $itemShares, $partialItemIds);
    }

    public function void(
        int $paymentId,
        string $reason,
        int $actorId,
        string $compensatingEvent,
        string $when
    ): array {
        return $this->inner->void($paymentId, $reason, $actorId, $compensatingEvent, $when);
    }

    public function markReconciled(
        int $paymentId,
        ?int $shareMinorFinal,
        string $posting,
        string $ledgerEvent,
        int $actorId,
        string $when
    ): bool {
        return $this->inner->markReconciled($paymentId, $shareMinorFinal, $posting, $ledgerEvent, $actorId, $when);
    }

    public function findByKey(string $idempotencyKey): ?SupplierPayment
    {
        return $this->inner->findByKey($idempotencyKey);
    }

    public function forOrderItem(int $orderItemId): ?SupplierPayment
    {
        return $this->inner->forOrderItem($orderItemId);
    }

    public function paidOrderItemIds(int $supplierUserId): array
    {
        return $this->inner->paidOrderItemIds($supplierUserId);
    }

    public function forSupplier(int $supplierUserId, int $limit = 50): array
    {
        return $this->inner->forSupplier($supplierUserId, $limit);
    }

    public function awaitingDefinition(int $limit = 100): array
    {
        return $this->inner->awaitingDefinition($limit);
    }

    public function markPosted(int $paymentId, string $ledgerEvent): bool
    {
        return $this->inner->markPosted($paymentId, $ledgerEvent);
    }
}
