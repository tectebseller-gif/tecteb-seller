<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * TWO CSV WRITERS, ONE RULE — enforced, because they drifted once.
 *
 * `OrderLedgerPage::cell()` prefixed a dangerous cell with a TAB while
 * `ProductCsv` prefixed with a single quote and treated a leading TAB as
 * itself dangerous. The docblock of the first claimed it matched the second.
 * Nothing measured either claim, and the ledger writer had no test at all.
 */
final class OneCsvEscapingRuleTest extends TestCase
{
    private const WRITERS = [
        'src/Modules/Order/Presentation/Admin/OrderLedgerPage.php',
        'src/Modules/Product/Application/ProductCsv.php',
    ];

    private function source(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        self::assertFileExists($path, $relative);
        return self::withoutComments((string) file_get_contents($path));
    }

    /** Comments are prose, not code — `alpha.22`, and this file searches text. */
    private static function withoutComments(string $php): string
    {
        $out = '';
        foreach (token_get_all($php) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    public function testEveryCsvWriterGoesThroughTheSharedRule(): void
    {
        foreach (self::WRITERS as $writer) {
            self::assertStringContainsString(
                'CsvCell',
                $this->source($writer),
                $writer . ' must take its escaping from CsvCell'
            );
        }
    }

    /**
     * And none of them may write its own. A writer that hand-rolls the quoting
     * is a writer that can disagree with the other one again.
     */
    public function testNoCsvWriterHandRollsTheEscaping(): void
    {
        foreach (self::WRITERS as $writer) {
            $body = $this->source($writer);
            self::assertStringNotContainsString(
                'str_replace(\'"\', \'""\'',
                $body,
                $writer . ' must not quote cells itself'
            );
            self::assertStringNotContainsString(
                '"\t" . $value',
                $body,
                $writer . ' must not prefix with a tab — a tab is itself a formula starter'
            );
        }
    }

    /** The list lives in exactly one place. */
    public function testTheFormulaStarterListIsDefinedOnce(): void
    {
        $definitions = 0;
        $root = dirname(__DIR__, 2) . '/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $body = self::withoutComments((string) file_get_contents($file->getPathname()));
            if (preg_match('/FORMULA_STARTERS\s*=\s*\[/', $body) === 1) {
                $definitions++;
                self::assertStringEndsWith(
                    'Core/Support/CsvCell.php',
                    str_replace('\\', '/', $file->getPathname()),
                    'the list belongs to CsvCell'
                );
            }
        }
        self::assertSame(1, $definitions, 'exactly one literal list in the tree');
    }
}
