<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: the per-line table can become a table, and every cell
 * carries the label the stacked layout reads.
 *
 * ⛔ **TWO DEFECTS, ONE STYLESHEET RULE, AND NOTHING IN PHP COULD SEE EITHER.**
 * `.tmc-table` is `display:block` until `@container tmccard (min-width: 44rem)`
 * says there is room, and the container is `.tmc-card`. In `alpha.99` the
 * report's `.tmc-scroll` wrapper was a bare `<div>` with no card above it, so
 * the query could NEVER match and a thirteen-column financial table rendered
 * as thirteen stacked lines per row at every width. And in that same stacked
 * layout each cell is labelled by `td::before { content: attr(data-label) }`
 * — and the cells carried no `data-label`, so every value printed after a
 * bare «: » with no word in front of it.
 *
 * The browser is the only place that is visible, so this test does the next
 * best thing: it holds the three bytes the layout depends on — the card, the
 * one column list, and a `<td>` that cannot be written without its label.
 */
final class ReportTableRendersAsATableTest extends TestCase
{
    private const LEDGER = 'src/Modules/Order/Presentation/Admin/OrderLedgerPage.php';
    private const AUDIT = 'src/Modules/Order/Presentation/Admin/OrderAuditPage.php';
    private const SHEET = 'assets/admin/tmc-admin.css';

    private static function code(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        self::assertFileExists($path);
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    /**
     * ⛔ THE STYLESHEET'S HALF OF THE CONTRACT. If the container query or the
     * container name is ever renamed, the card below stops helping and this
     * says so rather than the owner finding out.
     */
    public function testTheStylesheetStillTurnsATableIntoATableInsideACard(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::SHEET);
        self::assertMatchesRegularExpression(
            '/\.tmc-card\s*\{[^}]*container-name:\s*tmccard/s',
            $css,
            'the card must still be the named container'
        );
        self::assertMatchesRegularExpression(
            '/@container tmccard \(min-width: 44rem\) \{[^}]*\.tmc-table \{ display: table; \}/s',
            $css,
            'the table must still become a table inside a wide card'
        );
        self::assertStringContainsString(
            'content: attr(data-label)',
            $css,
            'the stacked layout must still label cells from data-label'
        );
    }

    /**
     * The table markup sits inside a `.tmc-card`, which is what gives the
     * container query something to match.
     */
    public function testEveryReportTableIsWrappedInACard(): void
    {
        foreach ([self::LEDGER, self::AUDIT] as $file) {
            $code = self::code($file);
            $card = strpos($code, 'tmc-card');
            $scroll = strpos($code, 'tmc-scroll');
            self::assertIsInt($card, $file . ' must open a card');
            self::assertIsInt($scroll, $file . ' must still scroll the table');
            // Every scroll wrapper has a card opened before it in the same
            // file. Measured by position rather than by nesting, which is the
            // most a byte test can claim — the browser evidence in
            // `docs/evidence/alpha100-reports/` is what shows the nesting.
            self::assertLessThan($scroll, $card, $file . ': the card must come first');
            self::assertStringContainsString('tmc-table', $code, $file);
        }
    }

    /**
     * ⛔ ONE LIST FOR THE HEADINGS AND THE CELLS. Two lists would have fixed
     * the labels until somebody added a column to one of them (`alpha.78`).
     */
    public function testTheHeadingsAndTheCellsComeFromOneList(): void
    {
        $code = self::code(self::LEDGER);
        self::assertSame(
            2,
            preg_match_all('/foreach \(self::columns\(\) as \$column\)/', $code),
            'the headings and the cells must both walk columns()'
        );
        self::assertStringContainsString(
            'data-label="\' . esc_attr($column[\'label\']) . \'"',
            $code,
            'the cell label must be the column label'
        );
    }

    /**
     * ⛔ AND NO `<td>` MAY BE WRITTEN WITHOUT ONE. This is the assertion that
     * would have caught `alpha.99`: it searches the rendered markup strings
     * for a cell opened without a label, in both report screens.
     */
    public function testNoCellIsWrittenWithoutItsLabel(): void
    {
        foreach ([self::LEDGER, self::AUDIT] as $file) {
            $code = self::code($file);
            // Every `<td` must be followed by `data-label` before the `>`
            // that closes the tag. A class attribute between them is fine.
            self::assertSame(
                0,
                preg_match_all('/<td(?![^>]*data-label)[^>]*>/', $code, $m),
                $file . ': a cell with no data-label would print a bare «: » '
                    . 'in the stacked layout — ' . implode(' | ', $m[0] ?? [])
            );
        }
    }

    /**
     * The filter form's grid is in the stylesheet. The class was written by
     * two views and matched by NOTHING, which is why thirteen filters stacked
     * — `alpha.32`'s root cause, one screen later.
     */
    public function testTheFilterFormIsStyled(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::SHEET);
        self::assertMatchesRegularExpression(
            '/\.tmc-filter-form \{[^}]*display: grid;/s',
            $css,
            'the filter bar must be a grid'
        );
        // ⛔ THREE, matching the six primary filters exactly. Four left two
        // holes on the second row, which only the rendered page could show
        // (`docs/evidence/alpha100-reports/`). Pinned here so a later tidy
        // cannot change the count without saying so.
        self::assertMatchesRegularExpression(
            '/@container tmccard \(min-width: 52rem\) \{\s*\.tmc-filter-form[^}]*grid-template-columns: repeat\(3/s',
            $css,
            'and three across on a wide card, one per primary filter pair'
        );
        // The advanced half must be visibly openable: a bold line with no
        // marker reads as a heading and nobody opens a heading.
        self::assertStringContainsString('.tmc-filter-form__more > summary::after', $css);
    }

    /**
     * The reports strip is filtered by CAPABILITY, one entry at a time.
     *
     * ⛔ A navigation is not a decoration: every entry it prints is a place
     * it is telling the reader they may go, and two of these five hold money.
     * The guard's CONDITION is pinned rather than the call alone, because
     * `if (false)` leaves the call's own text exactly where it was
     * (`alpha.79`) — and a containment test would pass on a strip that had
     * stopped filtering.
     */
    public function testTheReportsStripAsksAboutEachEntrysCapability(): void
    {
        $file = dirname(__DIR__, 2) . '/src/Modules/Admin/Presentation/Components.php';
        $code = (string) file_get_contents($file);
        self::assertStringContainsString(
            'if (!current_user_can($spec[\'capability\'])) {',
            $code,
            'each entry must be refused by its own capability'
        );
        // And the entries themselves carry one, so a new place cannot be
        // added without saying who may see it.
        preg_match("/private const REPORT_PLACES = \\[(.*?)\\n    \\];/s", $code, $m);
        self::assertNotEmpty($m, 'the list of places must be findable');
        self::assertSame(
            substr_count($m[1], "'label' =>"),
            substr_count($m[1], "'capability' =>"),
            'every report place needs a capability beside its label'
        );
    }

    /**
     * The product column wears the wrapping rule, and the rule has a wearer.
     *
     * ⛔ `StyledClassesHaveRulesTest` asks whether a class that is WRITTEN has
     * a rule. It never asks the other half, and this rule sat in the
     * stylesheet with nothing wearing it — so a vendor's product title broke
     * to one word per line in a column sized for «#۱۰۲», which only the
     * rendered page showed.
     */
    public function testTheProsePoundColumnIsTheOneThatWraps(): void
    {
        $page = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Modules/Order/Presentation/Admin/OrderLedgerPage.php'
        );
        self::assertStringContainsString("tmc-ledger__cell--wrap", $page, 'the rule needs a wearer');
        self::assertMatchesRegularExpression(
            "/\\\$t\\('product',[^)]*\\), true\\)/",
            $page,
            'and the wearer is the product column, which holds a vendor\'s own text'
        );
    }
}
