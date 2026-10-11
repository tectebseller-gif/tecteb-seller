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

    /**
     * The ONE exemption, named, with its reason — and nothing else.
     *
     * `alpha.48` gave the manager a one-click «approve this brand» on the
     * review screen, which has to add a term to WooCommerce's own
     * `product_brand`. That is not the thing this test exists to stop: the
     * defect of `alpha.23` was a PROJECTION inventing a category nobody's
     * menus knew, silently, on every save. This is a person deciding, once,
     * through a capability — the same shape `applySlug()` has, where a
     * projection may not move an address and a manager choosing one may.
     *
     * The exemption is written down rather than left as a loosened rule,
     * because `alpha.46` learned that an exception nobody records is an
     * exception the next round copies. And it is narrow in three ways, each
     * asserted below: one file, one taxonomy, and never `product_cat`.
     *
     * @var array<string,list<string>> file => the writes it may make
     */
    private const EXEMPT = [
        'WooCommerceBrandDirectory.php' => ['wp_insert_term'],
    ];

    public function testNothingInTheProductModuleCreatesOrEditsATaxonomyTerm(): void
    {
        $forbidden = ['wp_insert_term', 'wp_update_term', 'wp_delete_term', 'register_taxonomy'];
        $offenders = [];
        foreach (self::sources() as $path) {
            $code = self::code($path);
            $allowed = self::EXEMPT[basename($path)] ?? [];
            foreach ($forbidden as $needle) {
                if (str_contains($code, $needle . '(') && !in_array($needle, $allowed, true)) {
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

    /** The exempted file writes to `product_brand` and to nothing else. */
    public function testTheBrandRegistryWritesOnlyToTheBrandTaxonomy(): void
    {
        $path = dirname(__DIR__, 2) . '/src/Modules/Product/Infrastructure/WooCommerce/WooCommerceBrandDirectory.php';
        $code = self::code($path);
        self::assertStringNotContainsString(
            'product_cat',
            $code,
            'the brand registry has no business naming the category taxonomy'
        );
        self::assertStringContainsString("'product_brand'", $code, 'and it names the one it does write to');
        // One write, and it is an INSERT. A rename or a delete would be the
        // manager's own in WooCommerce's screen, never ours: a brand this
        // plugin renamed would rename it for every shop that uses it.
        self::assertSame(1, substr_count($code, 'wp_insert_term('));
        foreach (['wp_update_term', 'wp_delete_term', 'wp_remove_object_terms', 'wp_set_object_terms'] as $needle) {
            self::assertStringNotContainsString($needle . '(', $code, $needle . '() is not ours to call');
        }
    }

    /** And no file outside the exemption list is exempt. */
    public function testTheExemptionNamesOneFile(): void
    {
        self::assertCount(1, self::EXEMPT);
        self::assertArrayHasKey('WooCommerceBrandDirectory.php', self::EXEMPT);
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
