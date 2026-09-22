<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The plugin never creates a product category.
 *
 * Until `alpha.24` the projector called `wp_insert_term()` whenever it could
 * not find a `tmc-…` slug, which on the owner's shop meant every marketplace
 * product landed in a term that existed nowhere in their menus, filters,
 * Elementor templates or sitemap — beside the 1,070 categories they actually
 * use. The instruction is «دسته‌بندی موازی یا تعریف دوبارهٔ آن‌ها نمی‌خواهیم»,
 * and an instruction that only lives in a docblock comes back.
 *
 * Comments are stripped first: `alpha.22` learned that the hard way, when two
 * files failed a byte-level scan for describing a function they never called.
 */
final class NoParallelTaxonomyTest extends TestCase
{
    /** @return list<string> */
    private static function sources(): array
    {
        $out = [];
        $root = dirname(__DIR__, 2) . '/src';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }
        sort($out);
        return $out;
    }

    /** Source with every comment and docblock blanked out. */
    private static function code(string $path): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }

    public function testNothingInTheProductModuleCreatesOrEditsATaxonomyTerm(): void
    {
        $forbidden = ['wp_insert_term', 'wp_update_term', 'wp_delete_term', 'register_taxonomy'];
        $offenders = [];
        foreach (self::sources() as $path) {
            $code = self::code($path);
            foreach ($forbidden as $needle) {
                if (str_contains($code, $needle . '(')) {
                    $offenders[] = basename($path) . ' calls ' . $needle . '()';
                }
            }
        }
        self::assertSame(
            [],
            $offenders,
            'categories belong to WooCommerce; the marketplace reads them and never writes them'
        );
    }

    public function testTheProjectorReadsTheCategoryThroughTheDirectory(): void
    {
        $code = self::code(dirname(__DIR__, 2) . '/src/Modules/Product/Infrastructure/WooCommerce/WooCommerceProjector.php');
        self::assertStringContainsString(
            'categories?->find(',
            $code,
            'the term id comes from the directory, not from a slug the projector builds'
        );
    }
}
