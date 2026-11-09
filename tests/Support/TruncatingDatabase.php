<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Contracts\DatabaseInterface;

/**
 * A database that answers a named read with FEWER rows than it has.
 *
 * ⛔ **WHY A DOUBLE AND NOT FIVE HUNDRED AND ONE PRODUCTS.** The thing under
 * test is what a scan does when its `LIMIT` is reached: it must notice, and
 * say so. Reaching `StorefrontStop::SCAN_LIMIT` honestly would mean
 * publishing five hundred and one products through the real workflow, which
 * is minutes of fixture for one comparison — and the comparison is between
 * «how many did I get» and «how many are there», which this reproduces
 * exactly by shortening the first.
 *
 * It is a SEPARATE double from `FailingDatabase` on purpose. That one refuses
 * a statement; this one lets it through and shortens the answer, which is a
 * different fiction and would be confusing under one name.
 */
final class TruncatingDatabase implements DatabaseInterface
{
    /** @var list<array{needles:list<string>,keep:int}> */
    private array $rules = [];

    public function __construct(private readonly DatabaseInterface $inner)
    {
    }

    /**
     * Keep only the first `$keep` rows of reads whose SQL holds all needles.
     *
     * @param list<string> $needles
     */
    public function truncateWhen(array $needles, int $keep): void
    {
        $this->rules[] = ['needles' => $needles, 'keep' => max(0, $keep)];
    }

    public function getResults(string $sql, array $params = []): array
    {
        $rows = $this->inner->getResults($sql, $params);
        foreach ($this->rules as $rule) {
            foreach ($rule['needles'] as $needle) {
                if (!str_contains($sql, $needle)) {
                    continue 2;
                }
            }
            return array_slice($rows, 0, $rule['keep']);
        }
        return $rows;
    }

    public function prefix(): string
    {
        return $this->inner->prefix();
    }

    public function charsetCollate(): string
    {
        return $this->inner->charsetCollate();
    }

    public function execute(string $sql, array $params = []): ?int
    {
        return $this->inner->execute($sql, $params);
    }

    public function getVar(string $sql, array $params = []): mixed
    {
        return $this->inner->getVar($sql, $params);
    }

    public function getRow(string $sql, array $params = []): ?array
    {
        return $this->inner->getRow($sql, $params);
    }

    public function lastError(): string
    {
        return $this->inner->lastError();
    }

    public function begin(): bool
    {
        return $this->inner->begin();
    }

    public function commit(): bool
    {
        return $this->inner->commit();
    }

    public function rollback(): bool
    {
        return $this->inner->rollback();
    }

    public function inTransaction(): bool
    {
        return $this->inner->inTransaction();
    }
}
