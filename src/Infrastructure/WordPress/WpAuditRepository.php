<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\AuditRecord;
use Tecteb\Marketplace\Contracts\AuditRepositoryInterface;
use Tecteb\Marketplace\Core\Migration\Migrations\M0001CreateAuditTable;

/** Prepared insert via $wpdb->insert() with explicit formats; NULL actor allowed. */
final class WpAuditRepository implements AuditRepositoryInterface
{
    private string $error = '';

    public function __construct(private \wpdb $wpdb)
    {
    }

    public function insert(AuditRecord $record): bool
    {
        $table = $this->wpdb->prefix . M0001CreateAuditTable::TABLE_SUFFIX;
        $payload = json_encode($record->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            $this->error = 'payload_encoding_failed';
            return false;
        }
        $result = $this->wpdb->insert(
            $table,
            [
                'event_type' => $record->eventType,
                'actor_id' => $record->actorId,
                'object_type' => $record->objectType,
                'object_id' => $record->objectId,
                'payload' => $payload,
                'correlation_id' => $record->correlationId,
                'created_at' => $record->createdAtUtc->format('Y-m-d H:i:s'),
            ],
            ['%s', '%d', '%s', '%s', '%s', '%s', '%s']
        );
        if ($result === false || $result === 0) {
            $this->error = (string) $this->wpdb->last_error ?: 'insert_failed';
            return false;
        }
        $this->error = '';
        return true;
    }

    public function lastError(): string
    {
        return $this->error;
    }
}
