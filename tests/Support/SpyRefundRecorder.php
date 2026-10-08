<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Modules\Order\Application\RefundRecorderInterface;

/**
 * A `RefundRecorderInterface` that records nothing and remembers everything.
 *
 * It exists for one question the database suite could not ask before: **what
 * amount, exactly, is handed to the thing that creates the WooCommerce
 * refund?** Every returns test until `alpha.39` passed `null` for the
 * recorder — the honest argument when there is no WooCommerce — which meant
 * the conversion between stored minor units and the refund argument was the
 * one step in the money path with no test over it at all. That is the step
 * that was dividing by a hundred.
 *
 * This is a DOUBLE for WooCommerce and is never presented as WooCommerce: it
 * proves what this plugin hands over, not what WooCommerce then does with it.
 * The real `wc_create_refund()` path stays `Not Run` without WooCommerce.
 */
final class SpyRefundRecorder implements RefundRecorderInterface
{
    /** @var list<array{order:int, item:int, amount:string, quantity:int, reason:string, return:int}> */
    public array $calls = [];

    /** The ceiling this fake order reports as still refundable. */
    public function __construct(
        private readonly string $remaining = '999999999',
        private readonly bool $available = true,
        private readonly int $existing = 0
    ) {
    }

    /** The amount of the last call, as the string it was really given. */
    public function lastAmount(): ?string
    {
        $last = $this->calls[array_key_last($this->calls) ?? 0] ?? null;
        return $last === null ? null : $last['amount'];
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function canTransferMoney(int $wcOrderId): bool
    {
        return false;       // no gateway in any build, and none added here
    }

    public function moneyBlockers(int $wcOrderId): array
    {
        return ['gateway_missing'];
    }

    public function findExisting(int $wcOrderId, int $returnId): int
    {
        return $this->existing;
    }

    public function record(
        int $wcOrderId,
        int $wcOrderItemId,
        string $amount,
        int $quantity,
        string $reason,
        int $returnId
    ): array {
        $this->calls[] = [
            'order' => $wcOrderId,
            'item' => $wcOrderItemId,
            'amount' => $amount,
            'quantity' => $quantity,
            'reason' => $reason,
            'return' => $returnId,
        ];
        return [
            'ok' => true,
            'reason' => 'refund_recorded',
            'refund_id' => 90000 + count($this->calls),
            'money_moved' => false,
            'remaining' => $this->remaining,
        ];
    }
}
