<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: the category-link preview writes nothing, and the one
 * thing that does write can only touch one column.
 *
 * ⛔ **Why these are source assertions.** A behaviour test can show that one
 * application wrote one category. It cannot show that no future application
 * will write a status, an ownership flag, a published flag or a ledger line:
 * the next line added to the method would be reachable and every behaviour
 * test would stay green. «There is no road from here to there» is a property
 * of the source.
 *
 * The owner's constraint is the whole reason: «محصول تکراری، ورود مجدد، تغییر
 * مالکیت، انتشار، اجازهٔ فروش یا تغییر مالی هم انجام نشود.»
 *
 * ⛔ Comments are stripped first. Both files name the very things being
 * forbidden — one of them says «no term is created, deleted or renamed» out
 * loud — and a rule checked against raw source is satisfied by the sentence
 * describing it (`alpha.22`).
 */
final class CategoryLinkPlanHasOneWriterTest extends TestCase
{
    private const READER = 'src/Modules/Product/Application/CategoryLinkPlan.php';

    private const WRITER = 'src/Modules/Product/Application/ApplyCategoryLinks.php';

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

    /** One method's body, by matching braces — never «up to the next `function`». */
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

    /** Every verb that would change something this round must not change. */
    private const FORBIDDEN = [
        'setCategoryWhenEmpty(' => self::WRITER,   // allowed in the writer only
        'updateDetails(' => '',
        'updateStatus(' => '',
        'setLinkOwnership(' => '',
        'link(' => '',
        'create(' => '',
        'createForForm(' => '',
        'updateInventory(' => '',
        'saveBaseline(' => '',
        'setBrandTerm(' => '',
        'stampImportRun(' => '',
        'wp_insert_term(' => '',
        'wp_set_object_terms(' => '',
        'set_category_ids(' => '',
        'wp_insert_post(' => '',
        'wp_update_post(' => '',
        'update_post_meta(' => '',
    ];

    public function testThePreviewHasNoWriteMethodOfAnyKind(): void
    {
        $code = $this->code(self::READER);
        foreach (array_keys(self::FORBIDDEN) as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $code,
                'the preview must only look: ' . $needle
            );
        }
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', '$wpdb'] as $needle) {
            self::assertStringNotContainsString($needle, $code, 'nor reach the database directly: ' . $needle);
        }
    }

    public function testTheWriterTouchesExactlyOneColumnAndNothingElse(): void
    {
        $code = $this->code(self::WRITER);
        foreach (self::FORBIDDEN as $needle => $allowedIn) {
            if ($allowedIn === self::WRITER) {
                continue;
            }
            self::assertStringNotContainsString(
                $needle,
                $code,
                'applying a category must not be able to do this: ' . $needle
            );
        }
        self::assertStringContainsString('setCategoryWhenEmpty(', $code, 'and it does write the one column');
    }

    /**
     * The write is guarded by the key that was SHOWN, inside the method.
     *
     * Pinned in source because `strpos` ordering is not containment: a test
     * that only checked the guard appears «before» the write would pass on a
     * file where the guard sat in another method entirely (`alpha.74`).
     */
    public function testTheFreshnessGuardAndTheWriteAreInTheSameMethod(): void
    {
        $body = $this->methodBody($this->code(self::WRITER), 'applyOne');

        self::assertStringContainsString('link_moved', $body);
        self::assertStringContainsString('$seen', $body);
        self::assertStringContainsString('setCategoryWhenEmpty(', $body);
        self::assertStringContainsString('isDefinite()', $body, 'and only a definite link is written');
    }

    /**
     * `definiteKey()` is the only source of the written value.
     *
     * So «the first of several» cannot be reached: that method returns `''`
     * for a multi-category product by construction, and the empty key is then
     * refused by the repository as `bad_category`. Two guards, and the owner
     * forbade the behaviour both of them prevent: «اولین دسته را خودسرانه
     * انتخاب نکن».
     */
    public function testTheWrittenKeyComesOnlyFromTheDefiniteLink(): void
    {
        $body = $this->methodBody($this->code(self::WRITER), 'applyOne');

        self::assertStringContainsString('$link->definiteKey()', $body);
        foreach (['validIds[0]', 'termIds[0]', 'reset($', 'array_shift('] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'never the first of several: ' . $needle);
        }
    }
}
