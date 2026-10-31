<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * THE ADMIN INTERFACE SAYS THE SHORT THING, AND FOLDS THE REST.
 *
 * The owner's standing instruction, from `alpha.85`: «توضیحات فنی طولانی،
 * هشدارهای آموزشی و جزئیات پیاده‌سازی را داخل صفحات و زیرمنوهای مدیریت نمایش
 * نده … توضیحات تکمیلی در صورت نیاز داخل بخش بازشونده «جزئیات بیشتر» قرار
 * بگیرند … هیچ قابلیت یا هشدار ایمنی صرفاً برای خلوت‌شدن صفحه حذف نشود.»
 *
 * Two rules are enforced here, and both are measured from the bytes:
 *
 * 1. **No internal identifier in text the page shows.** A version number, a
 *    decision code, an option or table or capability name, the word
 *    `migration` — true, and not what a shop manager came for. ⛔ The one
 *    exception is written into the pattern rather than into a list: a shortcode
 *    the manager has to TYPE (`[tmc_vendor_dashboard]`) is the instruction, not
 *    an implementation detail.
 * 2. **Nothing longer than {@see self::CAP} characters outside a disclosure.**
 *    Longer prose belongs behind `Components::moreDetails()` (or the older
 *    `techDetails()`, or a literal `<details>` in a view), where it is still on
 *    the page, still in the HTML, and still found by every contract test that
 *    pinned the sentence — which is why the conversion SPLITS sentences instead
 *    of rewriting them.
 *
 * ### Why the second rule carries a budget, and why the budget is exact
 *
 * The rule arrived with 76 strings already over the cap in 21 files. Fixing
 * every one of them in the round that introduced the rule would have put a
 * presentation-wide rewrite inside a round about category relationships, and
 * a rule with no test is a sentence in a document. So the cap is enforced
 * per file against {@see self::BUDGET}, by **exact equality**: a file that
 * gains a long line fails, and so does a file that loses one without
 * lowering its number. That is the ratchet «از این مرحله به بعد» needs — the
 * total can only be driven down, and the remaining count is a number in this
 * file rather than a feeling. It is not an exemption list: every entry is a
 * debt, and `docs/admin-ui-rules-fa.md` says so.
 */
final class AdminProseIsShortOrFoldedTest extends TestCase
{
    /** How long a line the page shows may be, in characters. */
    private const CAP = 180;

    /** The admin surface: pages, views and the message catalogues they print. */
    private const DIRS = [
        'src/Modules/Admin/Presentation',
        'src/Modules/Migration/Presentation',
        'src/Modules/Product/Presentation/Admin',
        'src/Modules/Finance/Presentation',
        'src/Modules/Operations/Presentation',
        'src/Modules/Vendor/Presentation/Admin',
    ];

    /**
     * Long lines still shown, per file. Measured, not estimated — and it only
     * goes down. Every number here is work the rule has not reached yet.
     *
     * @var array<string,int>
     */
    private const BUDGET = [
        'src/Modules/Admin/Presentation/Messages.php' => 2,
        'src/Modules/Admin/Presentation/Views/health.php' => 3,
        'src/Modules/Admin/Presentation/Views/settings.php' => 1,
        'src/Modules/Finance/Presentation/Admin/CommissionRulesPage.php' => 1,
        'src/Modules/Finance/Presentation/Admin/SupplierPaymentsPage.php' => 4,
        'src/Modules/Finance/Presentation/SupplierPaymentMessages.php' => 5,
        'src/Modules/Migration/Presentation/Admin/MigrationPage.php' => 5,
        'src/Modules/Migration/Presentation/Admin/MinMaxExitPage.php' => 9,
        'src/Modules/Operations/Presentation/Admin/EventsPage.php' => 1,
        'src/Modules/Operations/Presentation/Admin/QueuePage.php' => 5,
        'src/Modules/Operations/Presentation/Admin/SetupWizardPage.php' => 1,
        'src/Modules/Operations/Presentation/OperationsMessages.php' => 1,
        'src/Modules/Operations/Presentation/SetupMessages.php' => 1,
        'src/Modules/Product/Presentation/Admin/ProductCatalogueView.php' => 3,
        'src/Modules/Product/Presentation/Admin/ProductReviewPage.php' => 4,
        'src/Modules/Product/Presentation/Admin/SpecTemplatesPage.php' => 1,
        'src/Modules/Product/Presentation/Admin/StorefrontPage.php' => 10,
        'src/Modules/Vendor/Presentation/Admin/DocumentTypesPage.php' => 2,
    ];

    /** What the budget adds up to. Stated so one number tells the story. */
    private const BUDGET_TOTAL = 59;

    public function testNoInternalIdentifierIsShownOnAnAdminPage(): void
    {
        $offenders = [];
        foreach ($this->strings() as $row) {
            if ($row['folded']) {
                continue;
            }
            if (preg_match('/alpha\.\d+|(?:DEC|FIN|ORDER)-\d+|(?<!\[)\btmc_[a-z_]+|\bmigration\b/u', $row['text'], $m) === 1) {
                $offenders[] = sprintf('%s:%d [%s]', $row['file'], $row['line'], $m[0]);
            }
        }
        self::assertSame(
            [],
            $offenders,
            "یک شناسهٔ داخلی روی صفحهٔ مدیریت چاپ می‌شود. اگر لازم است، داخل «جزئیات بیشتر» ببریدش:\n"
                . implode("\n", $offenders)
        );
    }

    public function testEveryLongLineIsFoldedOrCountedAgainstItsFilesBudget(): void
    {
        $counts = [];
        foreach ($this->strings() as $row) {
            if (!$row['folded'] && mb_strlen($row['text']) > self::CAP) {
                $counts[$row['file']] = ($counts[$row['file']] ?? 0) + 1;
            }
        }
        ksort($counts);
        $budget = self::BUDGET;
        ksort($budget);
        self::assertSame(
            $budget,
            $counts,
            'بودجهٔ متن بلند عوض شده است. فایلی که بهتر شده باید عددش را پایین بیاورد، و'
                . ' فایلی که بدتر شده باید متنش را داخل «جزئیات بیشتر» ببرد.'
        );
    }

    public function testTheBudgetTotalMatchesTheBudget(): void
    {
        // Both directions, so the headline number cannot drift away from the
        // table underneath it (`alpha.82`).
        self::assertSame(self::BUDGET_TOTAL, array_sum(self::BUDGET));
    }

    public function testTheDisclosureIsBuiltInOnePlaceOnly(): void
    {
        $component = file_get_contents($this->root() . '/src/Modules/Admin/Presentation/Components.php');
        self::assertIsString($component);
        self::assertStringContainsString('<details class="tmc-tech tmc-more">', $component);

        // Nobody else writes that disclosure by hand: one builder means one
        // span for this test to recognise, and one place to change the markup.
        $offenders = [];
        foreach ($this->files() as $file) {
            $path = $this->relative($file);
            if ($path === 'src/Modules/Admin/Presentation/Components.php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (str_contains($source, 'tmc-more')) {
                $offenders[] = $path;
            }
        }
        self::assertSame([], $offenders, implode(', ', $offenders));
    }

    /**
     * Every translatable string the admin surface builds, with whether it sits
     * inside a disclosure.
     *
     * Tokenised rather than grepped: a docblock is not a screen, and this
     * repository has paid for that confusion before (`alpha.60`).
     *
     * @return list<array{file:string, line:int, text:string, folded:bool}>
     */
    private function strings(): array
    {
        $rows = [];
        foreach ($this->files() as $file) {
            $source = (string) file_get_contents($file->getPathname());
            $tokens = token_get_all($source);
            $folded = $this->foldedSpans($tokens);
            $count = count($tokens);
            for ($i = 0; $i < $count; $i++) {
                $token = $tokens[$i];
                if (!is_array($token) || $token[0] !== T_STRING) {
                    continue;
                }
                if (!in_array($token[1], ['__', 'esc_html__', 'esc_attr__'], true)) {
                    continue;
                }
                $j = $this->skipSpace($tokens, $i + 1);
                if (($tokens[$j] ?? null) !== '(') {
                    continue;
                }
                $j = $this->skipSpace($tokens, $j + 1);
                $argument = $tokens[$j] ?? null;
                if (!is_array($argument) || $argument[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    // A string built at runtime is not something this rule can
                    // measure, and pretending otherwise would be a guess.
                    continue;
                }
                $inside = false;
                foreach ($folded as [$from, $to]) {
                    if ($j > $from && $j < $to) {
                        $inside = true;
                        break;
                    }
                }
                $rows[] = [
                    'file' => $this->relative($file),
                    'line' => (int) $argument[2],
                    'text' => stripcslashes(substr((string) $argument[1], 1, -1)),
                    'folded' => $inside,
                ];
            }
        }
        return $rows;
    }

    /**
     * The token ranges a disclosure covers: the argument list of
     * `moreDetails()`/`techDetails()`, and a literal `<details>` written as
     * inline HTML in a view.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return list<array{0:int,1:int}>
     */
    private function foldedSpans(array $tokens): array
    {
        $spans = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }
            if (!in_array($token[1], ['moreDetails', 'techDetails'], true)) {
                continue;
            }
            $open = $i + 1;
            while ($open < $count && $tokens[$open] !== '(') {
                $open++;
            }
            if ($open >= $count) {
                continue;
            }
            $depth = 0;
            for ($k = $open; $k < $count; $k++) {
                if ($tokens[$k] === '(') {
                    $depth++;
                } elseif ($tokens[$k] === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $spans[] = [$open, $k];
                        break;
                    }
                }
            }
        }
        // A view file folds with markup, not with a call.
        $depth = 0;
        $start = null;
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_INLINE_HTML) {
                continue;
            }
            $opens = substr_count($token[1], '<details');
            $closes = substr_count($token[1], '</details>');
            if ($depth === 0 && $opens > 0) {
                $start = $i;
            }
            $depth += $opens - $closes;
            if ($depth <= 0 && $start !== null && $closes > 0) {
                $spans[] = [$start, $i];
                $start = null;
                $depth = 0;
            }
        }
        if ($start !== null) {
            $spans[] = [$start, PHP_INT_MAX];
        }
        return $spans;
    }

    /**
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private function skipSpace(array $tokens, int $i): int
    {
        $count = count($tokens);
        while ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
            $i++;
        }
        return $i;
    }

    /** @return list<SplFileInfo> */
    private function files(): array
    {
        $files = [];
        foreach (self::DIRS as $dir) {
            $path = $this->root() . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }
            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file;
                }
            }
        }
        usort($files, static fn (SplFileInfo $a, SplFileInfo $b): int => strcmp($a->getPathname(), $b->getPathname()));
        return $files;
    }

    private function relative(SplFileInfo $file): string
    {
        return str_replace($this->root() . '/', '', str_replace('\\', '/', $file->getPathname()));
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
