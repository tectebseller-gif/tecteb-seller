<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Contracts\DatabaseInterface;

/**
 * A real database with one statement made to fail — on purpose, by name.
 *
 * `FakeDatabase` answers nothing and records everything, which is right for
 * asserting «was this SQL written». This is the other half: every statement
 * runs against the REAL MariaDB through the gateway it wraps, except the ones
 * a test names. So a failed `DELETE` or a failed third `INSERT` is measured
 * against the rows that are actually in the table afterwards, with the real
 * transaction semantics underneath — which is the only way to see whether a
 * half-written gallery survives.
 *
 * `null` is what `execute()` returns on a real failure (`0` is a SUCCESS with
 * no rows changed — `alpha.8`'s rule), so that is exactly what a refusal
 * returns here.
 */
final class FailingDatabase implements DatabaseInterface
{
    /** @var list<array{needles:list<string>,nth:int,seen:int}> */
    private array $rules = [];

    /** @var list<array{needles:list<string>,nth:int,seen:int}> */
    private array $readRules = [];

    /** @var list<string> the statements this refused, in order */
    public array $refused = [];

    private string $error = '';

    public function __construct(private readonly DatabaseInterface $inner)
    {
    }

    /**
     * Refuse statements whose SQL contains ALL of `$needles`.
     *
     * @param list<string> $needles
     * @param int $nth 0 = every match; otherwise only that occurrence, so a
     *        test can fail the revision's own write and let the RESTORE's
     *        write through — or the other way round.
     */
    public function failWhen(array $needles, int $nth = 0): void
    {
        $this->rules[] = ['needles' => $needles, 'nth' => $nth, 'seen' => 0];
    }

    /**
     * Refuse a READ, which is a different answer from refusing a write.
     *
     * `execute()` says `null` for «refused» and a number for «done»; a read
     * says `null` for «refused» and ALSO `null` for «no such row», and that
     * collision is what `alpha.37`'s migration was broken by. So a test that
     * wants to prove the difference has to be able to make a named read fail
     * while every other read on the same connection keeps working — which is
     * what this does, and why it is a separate rule list: a needle that
     * matched both would refuse the write the test is trying to observe.
     *
     * @param list<string> $needles every one must appear in the SQL
     * @param int $nth 0 = every match; otherwise only that occurrence
     */
    public function failReadWhen(array $needles, int $nth = 0): void
    {
        $this->readRules[] = ['needles' => $needles, 'nth' => $nth, 'seen' => 0];
    }

    /**
     * Back to a database that works, without losing the LIST of what was
     * refused.
     *
     * The recorded error string IS cleared, and that matters: `lastError()`
     * would otherwise keep answering with the refusal after the refusals had
     * stopped, and code that reads it to tell «empty page» from «broken read»
     * — which the carry-over does, by design — would go on failing for ever.
     * Real `wpdb` resets `last_error` on each query, so keeping it would be
     * the unfaithful choice as well as the useless one.
     */
    public function stopFailing(): void
    {
        $this->rules = [];
        $this->readRules = [];
        $this->error = '';
    }

    /**
     * True when this read is one the test named.
     *
     * The haystack is the SQL **and its parameters**, because the thing a read
     * is usually identified by is a table name that arrives as `%s`. The first
     * version of this matched the SQL alone, and a rule naming `usermeta`
     * never fired against `… WHERE TABLE_NAME = %s` — which looked exactly
     * like a passing test («بررسی‌ای که نمی‌تواند رد شود، رد هم نشده است»).
     *
     * @param list<mixed> $params
     */
    private function refusesRead(string $sql, array $params = []): bool
    {
        $haystack = $sql;
        foreach ($params as $param) {
            if (is_scalar($param)) {
                $haystack .= ' ' . $param;
            }
        }
        foreach ($this->readRules as $i => $rule) {
            if (!self::matches($haystack, $rule['needles'])) {
                continue;
            }
            $this->readRules[$i]['seen']++;
            if ($rule['nth'] !== 0 && $this->readRules[$i]['seen'] !== $rule['nth']) {
                continue;
            }
            $this->refused[] = $sql;
            $this->error = 'read refused by FailingDatabase: ' . implode(' + ', $rule['needles']);
            return true;
        }
        return false;
    }

    public function execute(string $sql, array $params = []): ?int
    {
        foreach ($this->rules as $i => $rule) {
            if (!self::matches($sql, $rule['needles'])) {
                continue;
            }
            $this->rules[$i]['seen']++;
            if ($rule['nth'] !== 0 && $this->rules[$i]['seen'] !== $rule['nth']) {
                continue;
            }
            $this->refused[] = $sql;
            $this->error = 'refused by FailingDatabase: ' . implode(' + ', $rule['needles']);
            return null;
        }
        return $this->inner->execute($sql, $params);
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
        return $this->refusesRead($sql, $params) ? null : $this->inner->getVar($sql, $params);
    }

    public function getRow(string $sql, array $params = []): ?array
    {
        return $this->refusesRead($sql, $params) ? null : $this->inner->getRow($sql, $params);
    }

    public function getResults(string $sql, array $params = []): array
    {
        return $this->refusesRead($sql, $params) ? [] : $this->inner->getResults($sql, $params);
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
        return $this->error !== '' ? $this->error : $this->inner->lastError();
    }
}
