<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Migration 39 adds a column and invents nothing to put in it.
 *
 * ⛔ **Why this is a test rather than a sentence in a docblock.** `NULL` in
 * `wc_order_status` means «nobody has told us», and the whole report reads it
 * that way: a row with no mirrored status prints «نامعلوم» rather than a
 * guess. A backfill — any backfill, including the obvious
 * `UPDATE … SET wc_order_status = 'pending'` — would invent a fact about the
 * 184 rows whose orders nobody has announced to this plugin, and the report
 * would then state it with a straight face.
 *
 * `alpha.89` guarded the same property the same way, for the same reason:
 * «`up()` هیچ `INSERT` ای ندارد، به‌عمد نه از فراموشی». A migration that is
 * additive BY INTENTION needs a test, because the next round's author will
 * read the empty column as an omission.
 */
final class StatusMirrorHasNoBackfillTest extends TestCase
{
    private const MIGRATION = 'src/Modules/Order/Infrastructure/Migrations/M0039WcOrderStatusOnRows.php';

    /** WHAT THIS PROVES: the step writes no row's data, ever. */
    public function testTheStepWritesNoData(): void
    {
        $source = self::code(self::MIGRATION);
        foreach (['UPDATE', 'INSERT', 'REPLACE', 'DELETE', 'TRUNCATE'] as $verb) {
            self::assertStringNotContainsStringIgnoringCase(
                $verb,
                $source,
                'migration 39 is additive by intention: ' . $verb . ' would invent a status'
            );
        }
    }

    /**
     * WHAT THIS PROVES: the column is nullable, so «unknown» is sayable.
     *
     * `NOT NULL DEFAULT 'pending'` would be a column of the right name that
     * cannot hold the one answer the report depends on — and `verify()` would
     * accept it if it only asked whether a column of that name existed
     * (`alpha.51`).
     */
    public function testTheColumnCanHoldUnknown(): void
    {
        $source = self::code(self::MIGRATION);
        self::assertStringContainsString("DEFINITION = 'VARCHAR(32) NULL DEFAULT NULL'", $source);
        self::assertStringNotContainsString('NOT NULL', $source);
    }

    /**
     * WHAT THIS PROVES: `verify()` asks about the SHAPE and about BOTH
     * indexes, not merely whether something of that name is there.
     *
     * A step that said «complete» on a column of the wrong type would freeze
     * the chain on 39 for every later round, on a real site (`alpha.51`).
     */
    public function testVerifyAsksAboutTheShapeAndBothIndexes(): void
    {
        $source = self::code(self::MIGRATION);
        $at = strpos($source, 'function verify(');
        self::assertNotFalse($at);
        $body = self::block(substr($source, $at));

        self::assertStringContainsString('columnState(', $body);
        self::assertStringContainsString('indexState(', $body);
        self::assertStringContainsString('STATUS_INDEX', $body);
        self::assertStringContainsString('CREATED_INDEX', $body);
    }

    /**
     * WHAT THIS PROVES: a catalogue read that failed is its own answer.
     *
     * `columnState()`/`indexState()` return `true|false|null`, and `null` is
     * «I could not find out». Collapsing it into `false` makes a failed read
     * indistinguishable from an absent column, and the step would then try to
     * add a column that is already there — or, worse, report «complete»
     * because it believed a read that never happened (`alpha.68`).
     */
    public function testAFailedCatalogueReadIsItsOwnAnswer(): void
    {
        $source = self::code(self::MIGRATION);
        foreach (['columnState', 'indexState'] as $name) {
            $at = strpos($source, 'function ' . $name . '(');
            self::assertNotFalse($at, $name . ' must exist');
            $signature = substr($source, $at, (int) strpos($source, '{', $at) - $at);
            self::assertStringContainsString(
                '?bool',
                $signature,
                $name . ' must be able to say «I do not know»'
            );
        }
    }

    /** The source with comments blanked: a docblock is not code (`alpha.22`). */
    private static function code(string $relative): string
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= str_repeat(' ', strlen($token[1]));
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    /** One brace-matched block from the first `{` onwards (`alpha.98`). */
    private static function block(string $from): string
    {
        $open = strpos($from, '{');
        if ($open === false) {
            return '';
        }
        $depth = 0;
        $length = strlen($from);
        for ($i = $open; $i < $length; $i++) {
            if ($from[$i] === '{') {
                $depth++;
            } elseif ($from[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($from, $open, $i - $open + 1);
                }
            }
        }
        return substr($from, $open);
    }
}
