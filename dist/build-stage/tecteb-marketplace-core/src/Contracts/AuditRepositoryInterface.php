<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts;

interface AuditRepositoryInterface
{
    /** Prepared insert. Returns false on failure; the caller MUST surface it. */
    public function insert(AuditRecord $record): bool;

    public function lastError(): string;
}
