<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Core\Support\CsvCell;

/** The one CSV rule both of this plugin's exports now read. */
final class CsvCellTest extends TestCase
{
    /** @return list<array{0:string}> */
    public static function dangerousLeadingCharacters(): array
    {
        return [['='], ['+'], ['-'], ['@'], ["\t"], ["\r"]];
    }

    /** @dataProvider dangerousLeadingCharacters */
    public function testEveryFormulaStarterIsDefused(string $lead): void
    {
        $cell = CsvCell::defuse($lead . 'SUM(A1:A9)');
        self::assertSame("'" . $lead . 'SUM(A1:A9)', $cell);
    }

    /**
     * ⛔ The prefix must not itself be on the list it defends against, which
     * is what the ledger export used to do: it prefixed with a TAB, and TAB is
     * a formula starter.
     */
    public function testThePrefixIsNotItselfAFormulaStarter(): void
    {
        self::assertNotContains("'", CsvCell::FORMULA_STARTERS);
        $twice = CsvCell::defuse(CsvCell::defuse('=1+1'));
        self::assertSame("'=1+1", $twice, 'defusing an already-defused cell changes nothing');
    }

    public function testOrdinaryTextIsUntouched(): void
    {
        foreach (['دستکش لاتکس', 'SKU-1', '1000', '', 'a=b'] as $value) {
            self::assertSame($value, CsvCell::defuse($value), $value);
        }
    }

    public function testQuotingDoublesInnerQuotes(): void
    {
        self::assertSame('"he said ""no"""', CsvCell::quote('he said "no"'));
        self::assertSame('""', CsvCell::quote(''));
    }

    /**
     * Order matters: the prefix belongs INSIDE the quotes. The other way round
     * it is a stray character between cells rather than part of one.
     */
    public function testTheDefusingHappensInsideTheQuotes(): void
    {
        self::assertSame('"\'=SUM(A1:A9)"', CsvCell::of('=SUM(A1:A9)'));
        self::assertStringStartsWith('"', CsvCell::of('=1'));
    }
}
