<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Contracts\DatabaseInterface;

/**
 * A real database that lets somebody else write in the gap between two of your
 * own statements — at an exact, named point, with no clock involved.
 *
 * **Why not two processes.** `concurrent-shop-write.php` exists for the races
 * that need a second transaction, and it is the right tool when the question is
 * «what does REPEATABLE READ show the parent». This question is different and
 * smaller: a read and a write in ONE process, with a third party's committed
 * change landing between them. A second process would have to be synchronised
 * with a file or a sleep, and a race test that waits is a race test that passes
 * on a bad build one run in five.
 *
 * So the interleaving is not raced for, it is *scheduled*: the callback runs
 * immediately before the n-th statement matching `$needles`, in the same
 * process, through the same connection. The statement then proceeds against the
 * rows the callback left behind — which is precisely «the row moved between the
 * SELECT and the UPDATE», every run, in the same order.
 */
final class InterferingDatabase implements DatabaseInterface
{
    /** @var list<array{needles:list<string>,nth:int,seen:int,run:callable,fired:bool}> */
    private array $rules = [];

    /** @var list<string> what actually fired, so a test can prove its hook ran */
    public array $fired = [];

    public function __construct(private readonly DatabaseInterface $inner)
    {
    }

    /**
     * Run `$callback` just BEFORE the `$nth` statement whose SQL contains all
     * of `$needles`. Fires once.
     *
     * @param list<string> $needles
     */
    public function before(array $needles, int $nth, callable $callback): void
    {
        $this->rules[] = ['needles' => $needles, 'nth' => $nth, 'seen' => 0, 'run' => $callback, 'fired' => false];
    }

    public function execute(string $sql, array $params = []): ?int
    {
        $this->maybeInterfere($sql);
        return $this->inner->execute($sql, $params);
    }

    private function maybeInterfere(string $sql): void
    {
        foreach ($this->rules as $i => $rule) {
            if ($rule['fired'] || !self::matches($sql, $rule['needles'])) {
                continue;
            }
            $this->rules[$i]['seen']++;
            if ($this->rules[$i]['seen'] !== $rule['nth']) {
                continue;
            }
            $this->rules[$i]['fired'] = true;
            $this->fired[] = $sql;
            ($rule['run'])();
        }
    }

    /** @param list<string> $needles */
    private static function matches(string $sql, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (!str_contains($sql, $needle)) {
                return false;
            }
        }
        return true;
    }

    public function prefix(): string
    {
        return $this->inner->prefix();
    }

    public function charsetCollate(): string
    {
        return $this->inner->charsetCollate();
    }

    public function getVar(string $sql, array $params = []): mixed
    {
        return $this->inner->getVar($sql, $params);
    }

    public function getRow(string $sql, array $params = []): ?array
    {
        return $this->inner->getRow($sql, $params);
    }

    public function getResults(string $sql, array $params = []): array
    {
        return $this->inner->getResults($sql, $params);
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

    public function lastError(): string
    {
        return $this->inner->lastError();
    }
}
