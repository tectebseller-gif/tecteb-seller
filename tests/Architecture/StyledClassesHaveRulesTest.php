<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: every `tmc-` class the manager's product screen writes has
 * at least one rule in the admin stylesheet.
 *
 * This is `alpha.32`'s root cause turned into a test. The owner reported the
 * status filters as «هفت لینک زیرخط‌دار به‌صورت عمودی» with no visible current
 * one, and the reason was not a missing class in the markup or a stylesheet
 * that failed to load: `.tmc-tabs`, `.tmc-tab`, `.tmc-tab__count` and
 * `.tmc-pager` were written by the views and matched by ZERO rules in either
 * stylesheet. A `<nav><ul>` of links then falls back to the browser's list
 * layout — one block per line — and to this plugin's own
 * `.tmc-admin a { text-decoration: underline }`, and `is-current` decorates
 * nothing at all.
 *
 * Nothing in PHP can notice that, which is why three releases did not. A class
 * name is a contract between a view and a stylesheet, and this is the only
 * place that contract is checked.
 *
 * **Scope, stated plainly.** The files below are the ones `alpha.32` and
 * `alpha.33` rebuilt. Nineteen other admin views still write `.tmc-tab`, and
 * those screens are still unstyled in exactly the way the owner described — a
 * finding of that round, recorded in the delivery document, not something
 * quietly redesigned here. When one of them is rebuilt, add it to this list.
 *
 * Two stylesheets, because the vendor area is not wp-admin and loads a file of
 * its own: `alpha.33` rebuilt the vendor's first screen, and a class written
 * there has to be found in THAT sheet — a `tv-` rule in the admin stylesheet
 * would never reach the page.
 */
final class StyledClassesHaveRulesTest extends TestCase
{
    /**
     * Which stylesheet each rebuilt view's classes must be found in, and the
     * class prefix that sheet owns.
     *
     * @var array<string,array{prefix:string,views:list<string>}>
     */
    private const SHEETS = [
        'assets/admin/tmc-admin.css' => [
            'prefix' => 'tmc',
            'views' => [
                'src/Modules/Product/Presentation/Admin/ProductCatalogueView.php',
                'src/Modules/Product/Presentation/Admin/ProductDecisionHistoryView.php',
                'src/Modules/Product/Presentation/Admin/ProductReviewPage.php',
            ],
        ],
        'assets/vendor/tmc-vendor.css' => [
            'prefix' => 'tv',
            'views' => [
                'src/Modules/Vendor/Presentation/DashboardView.php',
                'src/Modules/Product/Presentation/ProductListView.php',
            ],
        ],
    ];

    public function testEveryClassARebuiltViewWritesIsStyled(): void
    {
        $missing = [];
        foreach (self::SHEETS as $sheet => $spec) {
            $css = (string) file_get_contents(__DIR__ . '/../../' . $sheet);
            foreach ($spec['views'] as $view) {
                foreach (self::classesIn(__DIR__ . '/../../' . $view, $spec['prefix']) as $class) {
                    if (preg_match('/\.' . preg_quote($class, '/') . '(?![A-Za-z0-9_-])/', $css) !== 1) {
                        $missing[] = $class . ' (' . $view . ' → ' . $sheet . ')';
                    }
                }
            }
        }
        self::assertSame(
            [],
            $missing,
            "these classes are written by a view and styled by nothing:\n" . implode("\n", $missing)
        );
    }

    /**
     * WHAT THIS PROVES: the class names with no rule are gone from this screen.
     *
     * Named rather than derived, because the test above only sees what the code
     * writes today: if `.tmc-tab` came back tomorrow with a rule added for it,
     * the check above would pass and this one would still say what was decided
     * — the filters on this page are `.tmc-filter`, and the shared name that
     * nineteen other screens use stays out of it.
     */
    public function testTheProductListDoesNotUseTheUnstyledSharedClasses(): void
    {
        foreach (['ProductCatalogueView', 'ProductDecisionHistoryView'] as $view) {
            $source = (string) file_get_contents(
                __DIR__ . '/../../src/Modules/Product/Presentation/Admin/' . $view . '.php'
            );
            foreach (['tmc-tabs', 'tmc-tab', 'tmc-tab__count'] as $unstyled) {
                self::assertDoesNotMatchRegularExpression(
                    '/"[^"]*\b' . preg_quote($unstyled, '/') . '\b/',
                    $source,
                    $view . ' still writes ' . $unstyled . ', which no stylesheet rule matches'
                );
            }
        }
    }

    /**
     * Class names out of the `class="…"` attributes a view writes.
     *
     * Comments are emptied first: this repository has learned twice that bytes
     * make no distinction between code and prose, and a class name inside a
     * docblock explaining a defect would be read as markup.
     *
     * @return list<string>
     */
    private static function classesIn(string $file, string $prefix = 'tmc'): array
    {
        $source = (string) file_get_contents($file);
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        preg_match_all('/class="([^"]*)"/', $code, $matches);
        $classes = [];
        foreach ($matches[1] as $attribute) {
            foreach (preg_split('/\s+/', $attribute) ?: [] as $class) {
                // Interpolated fragments (`tmc-catalogue__status--' . $tone`)
                // are not class names; the fixed part before them is.
                $class = trim($class);
                if ($class === '' || !preg_match('/^' . preg_quote($prefix, '/') . '-[A-Za-z0-9_-]+$/', $class)) {
                    continue;
                }
                $classes[$class] = true;
            }
        }
        return array_keys($classes);
    }
}
