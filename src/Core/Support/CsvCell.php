<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Support;

/**
 * ONE RULE FOR A CSV CELL, because this plugin writes two CSVs and they
 * disagreed.
 *
 * ### What the disagreement was
 *
 * `ProductCsv` prefixed a dangerous cell with a single quote and counted TAB
 * and CR as themselves dangerous. `OrderLedgerPage::cell()` prefixed with a
 * TAB — the very character the other writer defuses — and its docblock said
 * it did so «the way the product export does», which was not true of either
 * the character or the list.
 *
 * Neither was obviously wrong on its own. A leading TAB does stop Excel
 * evaluating the cell. But two writers in one plugin applying contradictory
 * rules means the next person to add a third CSV picks whichever they read
 * first, and the one with no test (`cell()` had none) is the one that drifts.
 *
 * ### The rule
 *
 * A leading `=`, `+`, `-`, `@`, TAB or CR makes the cell a formula to
 * Excel, LibreOffice and Sheets alike, so the cell is prefixed with a single
 * quote — which those three read as «this is text» and which, unlike a TAB,
 * is not itself on the list it is defending against.
 *
 * ⛔ This defends the SPREADSHEET, not this plugin: none of these values is
 * ever evaluated here. It is on the list because a vendor's own product title
 * is a cell in a file a human opens on their own machine.
 */
final class CsvCell
{
    /**
     * Characters that make a spreadsheet treat the cell as a formula.
     *
     * TAB and CR are on the list because a cell beginning with either is
     * re-parsed by some importers into the NEXT cell, which can begin with
     * `=` — so they are not «harmless whitespace» in a file format whose
     * separators are characters.
     *
     * @var list<string>
     */
    public const FORMULA_STARTERS = ['=', '+', '-', '@', "\t", "\r"];

    /** Makes a cell safe to open, without changing what it says. */
    public static function defuse(string $value): string
    {
        if ($value === '' || !in_array($value[0], self::FORMULA_STARTERS, true)) {
            return $value;
        }
        return "'" . $value;
    }

    /** RFC 4180 quoting: wrap, and double any quote inside. */
    public static function quote(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }

    /**
     * Both, in the order that is safe — defuse first, then quote.
     *
     * The other order would put the prefix outside the quotes, where it is a
     * stray character rather than part of the cell.
     */
    public static function of(string $value): string
    {
        return self::quote(self::defuse($value));
    }
}
