<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: reading a product's existing `product_cat` relationship
 * cannot change a term, a relationship, or the product.
 *
 * The owner's constraint for this round is flat: «هیچ دسته یا رابطهٔ ووکامرس
 * ساخته، حذف یا تغییر نکند.» A behaviour test can show that one preview wrote
 * nothing. It cannot show that no future preview will: the next line added to
 * the reader would be reachable and every behaviour test would stay green.
 * «There is no road from here to there» is a property of the source, so it is
 * asserted here (`alpha.77`'s companion — count the callers, and pin what the
 * file is allowed to contain).
 *
 * ⛔ Comments are stripped first. Both files name the very functions being
 * forbidden — this one's own docblock says `wp_insert_term()` out loud — and a
 * rule checked against raw source is satisfied by the sentence that describes
 * it (`alpha.22`).
 *
 * ⛔ And the needles carry their opening parenthesis. `wp_insert_term` is a
 * substring of nothing here, but `update_post_meta` is a substring of
 * `update_post_metadata`, and that near-miss has cost this repository a round
 * already (`alpha.33`, `alpha.60`).
 */
final class CategoryLinksAreReadOnlyTest extends TestCase
{
    private const READER = 'src/Modules/Product/Infrastructure/WooCommerce/WpProductCategoryLinkReader.php';

    private const LINK = 'src/Modules/Product/Domain/CategoryLink.php';

    private const PORT = 'src/Modules/Product/Application/ProductCategoryLinkReaderInterface.php';

    /** Every WordPress verb that could change a term or a relationship. */
    private const TAXONOMY_WRITERS = [
        'wp_insert_term(',
        'wp_update_term(',
        'wp_delete_term(',
        'wp_set_object_terms(',
        'wp_add_object_terms(',
        'wp_remove_object_terms(',
        'wp_set_post_terms(',
        'wp_set_post_categories(',
        'register_taxonomy(',
        'set_category_ids(',
        'update_term_meta(',
        'delete_term_meta(',
    ];

    /** And every SQL verb that could do it behind the taxonomy API's back. */
    private const SQL_WRITERS = ['INSERT ', 'UPDATE ', 'DELETE ', 'REPLACE ', 'TRUNCATE ', 'ALTER ', 'DROP '];

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

    public function testTheReaderNamesNoTaxonomyWriter(): void
    {
        $code = $this->code(self::READER);
        foreach (self::TAXONOMY_WRITERS as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $code,
                'the link reader must not be able to change a term or a relationship: ' . $needle
            );
        }
    }

    public function testTheReaderRunsNoWritingStatement(): void
    {
        $code = strtoupper($this->code(self::READER));
        foreach (self::SQL_WRITERS as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $code,
                'the link reader must only SELECT: ' . trim($needle)
            );
        }
        self::assertStringContainsString('SELECT', $code, 'and it does read');
    }

    public function testTheValueObjectHasNoWriteMethodOfAnyKind(): void
    {
        foreach ([self::LINK, self::PORT] as $file) {
            $code = $this->code($file);
            foreach (array_merge(self::TAXONOMY_WRITERS, ['$wpdb', 'update_post_meta(']) as $needle) {
                self::assertStringNotContainsString($needle, $code, $file . ' must only report: ' . $needle);
            }
        }
    }

    /**
     * The port offers no way to ask by NAME.
     *
     * «تطبیق بر اساس شناسهٔ معتبر دسته در همین سایت باشد، نه شباهت نام،
     * جست‌وجوی متنی یا حدس» — so the absence is the guard, and a method taking
     * a title or a query would be the way back in.
     */
    public function testThePortAsksByIdAndNeverByName(): void
    {
        $code = $this->code(self::PORT);
        foreach (['string $name', 'string $title', 'string $query', 'string $label', 'search('] as $needle) {
            self::assertStringNotContainsString($needle, $code, 'the port must match by id only: ' . $needle);
        }
        self::assertStringContainsString('linksFor(array $wcProductIds)', $code);
    }

    /**
     * A read that fails is reported as unreadable, and the count is separate.
     *
     * Pinned in source because no behaviour test here can reach the state:
     * `getResults()` answers «no rows» and «the statement failed» identically
     * (`alpha.8`), so without the separate count a short read looks exactly
     * like a product with fewer categories — and that is the one wrong answer
     * that would let a write happen.
     */
    public function testTheShortReadIsGuardedByACountOfItsOwn(): void
    {
        $code = $this->code(self::READER);
        self::assertStringContainsString('COUNT(*)', $code, 'the batch is counted before it is listed');
        self::assertStringContainsString('count($rows) !== $expected', $code, 'and a short read is refused');
        self::assertStringContainsString('CategoryLink::unreadable(', $code);
    }
}
