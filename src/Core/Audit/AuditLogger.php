<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Audit;

use Tecteb\Marketplace\Contracts\AuditRecord;
use Tecteb\Marketplace\Contracts\AuditRepositoryInterface;
use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Core\Support\TextSanitizer;

/**
 * The only way to write audit rows. Failures are returned, never swallowed,
 * so sensitive operations can report "saved, but audit failed" (CORE-07/08).
 */
final class AuditLogger
{
    public function __construct(
        private AuditRepositoryInterface $repository,
        private AuditEventSanitizer $sanitizer,
        private ClockInterface $clock
    ) {
    }

    /** @param array<string,mixed> $payload */
    public function log(
        string $eventType,
        ?int $actorId,
        ?string $objectType,
        ?string $objectId,
        array $payload = [],
        ?string $correlationId = null
    ): AuditResult {
        $clean = $this->sanitizer->sanitize($eventType, $payload);
        if ($clean === null) {
            return AuditResult::failure('unknown_event_type');
        }
        $correlationId = $correlationId ?? self::newCorrelationId();
        $record = new AuditRecord(
            $eventType,
            $actorId,
            $objectType !== null ? TextSanitizer::singleLine($objectType, 64) : null,
            $objectId !== null ? TextSanitizer::singleLine($objectId, 64) : null,
            $clean,
            $correlationId,
            $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))
        );
        if (!$this->repository->insert($record)) {
            return AuditResult::failure(TextSanitizer::singleLine($this->repository->lastError() ?: 'insert_failed', 200));
        }
        return AuditResult::success($correlationId);
    }

    public static function newCorrelationId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}
