<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

final class CallLog
{
    /** @var list<string> */
    public array $entries = [];

    public function add(string $entry): void
    {
        $this->entries[] = $entry;
    }
}
