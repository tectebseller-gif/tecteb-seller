<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: the summary that makes a long value readable cannot change
 * it, and nothing on the way IN goes through it.
 *
 * §2's instruction has two halves and only one of them is about the screen:
 *
 *   «HTML اصلی، محتوا، لینک‌ها، ساختار سئو، تصاویر و متن محصول حفظ شود.
 *    تغییر داده برای زیباسازی نمایش ممنوع است.»
 *
 * `FieldSummary` strips tags, decodes entities and cuts at a character count —
 * every one of those is exactly the kind of transformation that must never
 * reach storage. The docblock says so; this is the part a refactor cannot
 * argue with.
 *
 * ⛔ AND THE SECOND HALF IS THE ONE A DOCBLOCK CANNOT HOLD. `alpha.41` learned
 * it on the sanitiser: a filter that runs on the way OUT is a display choice,
 * and the same filter on the way IN rewrites the column. So this also counts
 * the callers: a Presentation class may ask `FieldSummary` anything, and
 * nothing under `Application/` or `Infrastructure/` may ask it at all.
 */
final class FieldSummaryIsDisplayOnlyTest extends TestCase
{
    private const FILE = 'src/Modules/Product/Domain/FieldSummary.php';

    /** Writers that would turn a display helper into a data migration. */
    private const WRITERS = [
        'update_post_meta', 'add_post_meta', 'delete_post_meta',
        'wp_update_post', 'wp_insert_post',
        '->update(', '->insert(', '->replace(', '->delete(', '->execute(',
        'UPDATE ', 'INSERT ', 'DELETE ',
    ];

    public function testTheSummaryCannotWriteAnything(): void
    {
        $code = self::tokenised(__DIR__ . '/../../' . self::FILE);
        foreach (self::WRITERS as $writer) {
            self::assertStringNotContainsString(
                $writer,
                $code,
                self::FILE . ' may not write: ' . $writer
            );
        }
    }

    /**
     * ⛔ And it is asked only by code that is rendering something.
     *
     * Measured over the whole of `src/`, not over a list: a list is a thing
     * somebody forgets to add to, and the directory is not.
     */
    public function testNothingOutsidePresentationAsksIt(): void
    {
        $root = __DIR__ . '/../../src';
        $asking = [];
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace($root . '/', '', $file->getPathname());
            if ($path === 'Modules/Product/Domain/FieldSummary.php') {
                continue;
            }
            if (!str_contains(self::tokenised($file->getPathname()), 'FieldSummary')) {
                continue;
            }
            if (!str_contains($path, '/Presentation/')) {
                $asking[] = $path;
            }
        }
        self::assertSame(
            [],
            $asking,
            "a display summary is being asked for outside Presentation:\n" . implode("\n", $asking)
        );
    }

    /**
     * Comments blanked first: this repository has learned more than once that
     * bytes draw no line between code and prose, and the docblock above
     * explains the very writers this test forbids (`alpha.22`).
     */
    private static function tokenised(string $file): string
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        return $code;
    }
}
