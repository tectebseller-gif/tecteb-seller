<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Operations\Application;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\OutboxRepositoryInterface;
use Tecteb\Marketplace\Core\Events\EventSigner;

/**
 * Recording an event that somebody else would want to know about.
 *
 * **Recording is not sending, and this release only records.** `OutboundPolicy`
 * blocks every outbound channel unconditionally — no option, constant or filter
 * opens it — so nothing written here leaves the site. That is the point rather
 * than a limitation to apologise for: the contract can be agreed, read and
 * verified now, and the day a real endpoint exists the same rows are delivered
 * unchanged. A half-built sender that «works in development» is how a test
 * order ends up in somebody's production queue.
 *
 * **The payload is an allowlist, built here, per event type.** Passing a
 * domain object straight through would put whatever that object gains next
 * month onto the wire, and the first time that happened it would be a bank
 * account. So each type names its fields, and a field not named is not sent.
 *
 * **The event id is stable and unique.** A receiver's only defence against a
 * retried delivery is recognising the id, which is worthless if the id is
 * regenerated — so it is derived once, from the type, the object and the
 * moment, and the unique index refuses a second row carrying it.
 */
final class RecordEvent
{
    /** @var array<string,list<string>> event type => the fields it carries */
    public const SCHEMA = [
        'vendor.approved' => ['vendor_user_id', 'store_name', 'approved_at'],
        'vendor.suspended' => ['vendor_user_id', 'reason', 'suspended_at'],
        'product.approved' => ['product_id', 'vendor_user_id', 'wc_product_id', 'title'],
        'product.rejected' => ['product_id', 'vendor_user_id', 'reason'],
        'order.recorded' => ['wc_order_id', 'vendor_user_id', 'items', 'total_minor'],
        'shipment.created' => ['shipment_id', 'wc_order_id', 'vendor_user_id', 'tracking'],
        'return.decided' => ['return_id', 'wc_order_id', 'vendor_user_id', 'decision'],
        'withdrawal.decided' => ['withdrawal_id', 'vendor_user_id', 'decision', 'amount_minor'],
    ];

    public function __construct(
        private readonly OutboxRepositoryInterface $outbox,
        private readonly ClockInterface $clock,
        private readonly string $secret
    ) {
    }

    /** @return list<string> the contract this build publishes */
    public static function types(): array
    {
        return array_keys(self::SCHEMA);
    }

    /**
     * @param array<string,mixed> $data raw; anything outside the type's schema is dropped
     * @return array{ok:bool, id:int, event_id:string, reason:string}
     */
    public function record(string $eventType, ?int $vendorUserId, string $objectType, string $objectId, array $data): array
    {
        $fields = self::SCHEMA[$eventType] ?? null;
        if ($fields === null) {
            // An unknown type is refused rather than passed through. A contract
            // that accepts anything is not one, and the receiver has no way to
            // know which shape to expect.
            return ['ok' => false, 'id' => 0, 'event_id' => '', 'reason' => 'unknown_event_type'];
        }

        $now = $this->clock->now();
        $eventId = $this->eventId($eventType, $objectType, $objectId, $now->getTimestamp());
        $payload = [
            'event' => $eventType,
            'event_id' => $eventId,
            'occurred_at' => $now->format(\DateTimeInterface::ATOM),
            'object' => ['type' => $objectType, 'id' => $objectId],
            'vendor_user_id' => $vendorUserId,
            'data' => $this->allowlist($data, $fields),
        ];
        $signature = EventSigner::sign($payload, $now->getTimestamp(), $this->secret);

        $id = $this->outbox->record($eventType, $eventId, $vendorUserId, $objectType, $objectId, $payload, $signature);
        if ($id === 0) {
            return ['ok' => false, 'id' => 0, 'event_id' => $eventId, 'reason' => 'already_recorded'];
        }
        return ['ok' => true, 'id' => $id, 'event_id' => $eventId, 'reason' => ''];
    }

    /**
     * @param array<string,mixed> $data
     * @param list<string> $fields
     * @return array<string,mixed>
     */
    private function allowlist(array $data, array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            // Scalars and flat lists of scalars only. A nested object would be
            // something nobody wrote a schema line for.
            if (is_scalar($value) || $value === null) {
                $out[$field] = $value;
            } elseif (is_array($value)) {
                $out[$field] = array_values(array_filter($value, static fn ($v): bool => is_scalar($v)));
            }
        }
        return $out;
    }

    private function eventId(string $type, string $objectType, string $objectId, int $timestamp): string
    {
        return substr(hash('sha256', $type . '|' . $objectType . '|' . $objectId . '|' . $timestamp), 0, 32);
    }
}
