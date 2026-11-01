<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: «which imported products still have no category» is
 * spelled out in exactly ONE place, and the list, its own total and the gate
 * that reads that total cannot come to disagree.
 *
 * The owner's constraint: «شمارنده، فهرست محصولات و دروازه categories_mapped
 * باید یک منطق مشترک داشته باشند.»
 *
 * ⛔ **Why a source assertion rather than a behaviour one.** A behaviour test
 * can show the two agree on today's data — and `alpha.87` shipped exactly
 * that: both halves were right, each about its own question, and the screen
 * was wrong. What has to be true is that there is no second place where the
 * condition is written, and that is a property of the source.
 *
 * ⛔ And the option `default_product_cat` is read in one place too, for the
 * same reason: two readings of one setting is how a counter and a screen come
 * to disagree about the same product (`alpha.79`).
 */
final class OneWithoutCategoryPredicateTest extends TestCase
{
    private const REPOSITORY = 'src/Modules/Product/Infrastructure/DbProductRepository.php';

    private const READER = 'src/Modules/Product/Infrastructure/WooCommerce/WpProductCategoryLinkReader.php';

    private function code(string $relative): string
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

    private function methodBody(string $code, string $method): string
    {
        $start = strpos($code, 'function ' . $method . '(');
        self::assertIsInt($start, 'no such method: ' . $method);
        $open = strpos($code, '{', (int) $start);
        self::assertIsInt($open, $method . ' has no body');
        $depth = 0;
        $length = strlen($code);
        for ($i = (int) $open; $i < $length; $i++) {
            if ($code[$i] === '{') {
                $depth++;
            } elseif ($code[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($code, (int) $open, $i - (int) $open + 1);
                }
            }
        }
        self::fail($method . ' has an unbalanced body');
    }

    public function testBothHalvesAskTheOneBuilderAndNeitherWritesTheClause(): void
    {
        $code = $this->code(self::REPOSITORY);
        foreach (['importedWithoutCategory', 'countImportedWithoutCategory'] as $method) {
            $body = $this->methodBody($code, $method);
            self::assertStringContainsString(
                '$this->withoutCategoryWhere(',
                $body,
                $method . ' must ask the one builder'
            );
            // ⛔ BOTH DIRECTIONS. Asking the builder is not enough if the
            // method also keeps its own copy of the condition — that is the
            // state `alpha.88` was in, where each half was spelled out and
            // they diverged on one shape.
            foreach ([
                "category_key = %s",
                'hasCategoryTable(',
                'categoriesTable(',
                'hasDefaultCategoryTable(',
            ] as $fragment) {
                self::assertStringNotContainsString(
                    $fragment,
                    $body,
                    $method . ' must not build the condition itself: found `' . $fragment . '`'
                );
            }
        }
    }

    public function testTheBuilderItselfCarriesEveryHalfOfTheQuestion(): void
    {
        $body = $this->methodBody($this->code(self::REPOSITORY), 'withoutCategoryWhere');
        foreach ([
            'category_key = %s' => 'the single key',
            'hasCategoryTable(' => 'the recorded set',
            'hasDefaultCategoryTable(' => 'the accepted default term',
            'readDefaultTermId(' => 'the term the acceptance is compared against',
            'term_relationships' => 'and whether the product still carries only that term',
        ] as $fragment => $why) {
            self::assertStringContainsString(
                $fragment,
                $body,
                'the builder must ask about ' . $why
            );
        }
    }

    /**
     * ⛔ And nothing WRITES it — which is what makes «two readings of one
     * setting cannot differ inside a request» a measurement rather than a
     * hope. The preview memoises per instance and the gate's predicate reads
     * statically; if any code here could change the option between those two
     * reads, the screen and the counter could disagree on one page, and that
     * is `alpha.87` one object over.
     */
    public function testNothingWritesTheOption(): void
    {
        $code = $this->code(self::READER);
        foreach (['update_option(', 'add_option(', 'delete_option('] as $verb) {
            self::assertStringNotContainsString(
                $verb,
                $code,
                'the one reader of `default_product_cat` must never write it'
            );
        }
    }

    public function testTheOptionIsReadInExactlyOneFile(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen(dirname(__DIR__, 2)) + 1);
            if (str_contains($this->code($relative), 'default_product_cat')) {
                $files[] = $relative;
            }
        }
        sort($files);
        self::assertSame([self::READER], $files, 'one setting, one reader');
    }
}
