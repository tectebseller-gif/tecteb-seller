<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts;

/**
 * The event outbox: what happened, written down in the shape somebody else
 * would be told it.
 *
 * **Append-only, and the payload is never rewritten.** The signature is taken
 * over exactly these bytes; editing the row afterwards would leave a signature
 * that verifies nothing. Only the delivery state moves.
 *
 * **`event_id` is unique, and that is the idempotency key.** A receiver that
 * sees the same id twice — because a delivery was retried after a timeout it
 * did not know had succeeded — must be able to recognise it as one event. That
 * property is worthless unless the id is stable, so it is generated once, when
 * the event is recorded, and never again.
 */
interface OutboxRepositoryInterface
{
    /**
     * @param array<string,mixed> $payload
     * @return int the row id, or 0 when this event id was already recorded
     */
    public function record(
        string $eventType,
        string $eventId,
        ?int $vendorUserId,
        string $objectType,
        string $objectId,
        array $payload,
        string $signature
    ): int;

    /** @return list<array<string,mixed>> */
    public function pending(int $limit, int $afterId = 0): array;

    /** @return list<array<string,mixed>> */
    public function recent(array $states, int $limit, int $offset = 0): array;

    public function markDelivery(int $id, string $state, string $reason): bool;

    /** @return array<string,int> state => count */
    public function census(): array;

    public function count(array $states = []): int;

    public function lastError(): string;
}
