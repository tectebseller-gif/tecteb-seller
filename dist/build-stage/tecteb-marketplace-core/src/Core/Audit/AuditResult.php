<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Audit;

final class AuditResult
{
    private function __construct(public readonly bool $ok, public readonly ?string $error, public readonly ?string $correlationId)
    {
    }

    public static function success(string $correlationId): self
    {
        return new self(true, null, $correlationId);
    }

    public static function failure(string $error): self
    {
        return new self(false, $error, null);
    }
}
