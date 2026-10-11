<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: the one tool meant to be run on the owner's own site
 * cannot write to it.
 *
 * Every other tool in `tools/` refuses to run anywhere but a disposable
 * database, and `DisposableOnlyToolsTest` holds them to it. This one is the
 * exception by design: §5 and §6 need facts about `staging.tecteb.com` that
 * this repository does not have, and the alternative to asking the site is
 * guessing — which the owner's instruction forbids twice.
 *
 * So the guard is different in kind. Rather than «refuse unless the database
 * is disposable», it is «contain nothing that could change anything», and
 * that is checked here by reading the file. A promise in a docblock is not a
 * guard; this is.
 *
 * Comments are blanked first, because bytes do not tell code from prose —
 * `alpha.22`'s rule, and the thing `SecurityRulesTest` still does not do.
 */
final class ReadOnlyInventoryTest extends TestCase
{
    private const TOOL = 'tools/plugin-exit-inventory.php';

    /**
     * Anything that could write. Named individually rather than matched by a
     * pattern, so adding one has to be a deliberate act with a reason.
     *
     * @var list<string>
     */
    private const WRITERS = [
        'update_post_meta', 'add_post_meta', 'delete_post_meta', 'update_metadata',
        'update_option', 'add_option', 'delete_option', 'update_site_option',
        'update_user_meta', 'add_user_meta', 'delete_user_meta',
        'update_term_meta', 'add_term_meta', 'delete_term_meta',
        'wp_insert_post', 'wp_update_post', 'wp_delete_post', 'wp_trash_post',
        'wp_insert_term', 'wp_update_term', 'wp_delete_term',
        'wp_insert_user', 'wp_update_user', 'wp_delete_user',
        'wp_schedule_event', 'wp_schedule_single_event', 'wp_clear_scheduled_hook',
        'activate_plugin', 'deactivate_plugins',
        // wpdb's own writers, and the two helpers that wrap them.
        '->insert(', '->update(', '->replace(', '->delete(', '->query(',
    ];

    /** SQL that changes something, in whatever string it is hidden. */
    private const WRITING_SQL = [
        'INSERT ', 'UPDATE ', 'DELETE ', 'ALTER ', 'DROP ', 'TRUNCATE ', 'CREATE ', 'REPLACE ',
    ];

    public function testTheInventoryToolCannotWriteAnything(): void
    {
        $code = self::codeWithoutComments(__DIR__ . '/../../' . self::TOOL);

        $found = [];
        foreach (self::WRITERS as $writer) {
            if (str_contains($code, $writer)) {
                $found[] = $writer;
            }
        }
        foreach (self::WRITING_SQL as $sql) {
            if (stripos($code, $sql) !== false) {
                $found[] = 'SQL: ' . trim($sql);
            }
        }

        self::assertSame(
            [],
            $found,
            self::TOOL . " is meant to be safe to run on the owner's own site, and these could change it:\n"
                . implode("\n", $found)
        );
    }

    /**
     * And it really is the tool the documentation points at — a test about a
     * file that has been renamed proves nothing.
     */
    public function testTheInventoryToolExistsAndSaysItIsReadOnly(): void
    {
        $path = __DIR__ . '/../../' . self::TOOL;
        self::assertFileExists($path);
        self::assertStringContainsString(
            'READ ONLY',
            (string) file_get_contents($path),
            'the tool must say what it is, where somebody about to run it will read it'
        );
    }

    /**
     * Comments blanked, strings kept.
     *
     * A tokenizer rather than a regex: the docblock of this very tool lists
     * `UPDATE` and `DELETE` while explaining that it contains neither, and a
     * byte scan would fail on the explanation.
     */
    private static function codeWithoutComments(string $path): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }
}
