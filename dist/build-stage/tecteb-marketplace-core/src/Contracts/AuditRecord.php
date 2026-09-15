<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts;

/**
 * Already-sanitised audit row. Never construct one from raw request data;
 * go through Core\Audit\AuditLogger.
 */
final class AuditRecord
{
    /** @param array<string,mixed> $payload allowlisted, scalar-only */
    public function __construct(
        public readonly string $eventType,
        public readonly ?int $actorId,
        public readonly ?string $objectType,
        public readonly ?string $objectId,
        public readonly array $payload,
        public readonly ?string $correlationId,
        public readonly \DateTimeImmutable $createdAtUtc
    ) {
    }
}
