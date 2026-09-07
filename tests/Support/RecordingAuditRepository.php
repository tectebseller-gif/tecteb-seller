<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Contracts\AuditRecord;
use Tecteb\Marketplace\Contracts\AuditRepositoryInterface;

final class RecordingAuditRepository implements AuditRepositoryInterface
{
    /** @var list<AuditRecord> */
    public array $records = [];
    public bool $fail = false;

    public function insert(AuditRecord $record): bool
    {
        if ($this->fail) {
            return false;
        }
        $this->records[] = $record;
        return true;
    }

    public function lastError(): string
    {
        return $this->fail ? 'simulated insert failure (Duplicate entry for key ...)' : '';
    }
}
