<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * THE CATEGORY-LINK PAGE MUST USE THE CURSOR IT IS GIVEN.
 *
 * `CategoryLinkPlan::plan()` has taken a limit and a keyset position, and
 * returned `cursor` and `finished`, since `alpha.81`. The only screen that
 * writes those relationships read **neither**: it called `plan()` with no
 * arguments, and the word `cursor` did not appear in `HandoverPage.php` once.
 * So the page always asked for imported rows `id > 0 LIMIT 500`, and on a
 * catalogue with more imported rows than that the remainder was unreachable
 * from the only place that could record it. Measured on the owner's install
 * it cost nothing — 184 rows fit on the first page — which is exactly why
 * nothing went red and why this rule is a test rather than a note:
 * resumability in a service that no caller reaches is not resumability
 * (`alpha.80`).
 *
 * Pinned in both directions, because either half alone can be satisfied by a
 * page that still cannot page: the call has to CARRY a position, and the
 * screen has to OFFER the next one.
 */
final class CategoryPageUsesTheCursorTest extends TestCase
{
    private const PAGE = 'src/Modules/Migration/Presentation/Admin/HandoverPage.php';

    public function testThePlanIsAskedForAPositionRatherThanAlwaysTheFirstPage(): void
    {
        $source = $this->body('renderCategoryLinks');

        self::assertStringContainsString('CategoryLinkPlan::ROW_LIMIT', $source);
        self::assertStringContainsString('$after', $source);
        self::assertStringNotContainsString(
            'CategoryLinkPlan::class)->plan()',
            $source,
            'a plan asked with no arguments is page one, for ever'
        );
    }

    public function testThePositionComesFromTheRequestAndIsNeverStored(): void
    {
        $source = $this->source();

        self::assertStringContainsString("private const CATEGORY_AFTER = 'cat_after';", $source);
        self::assertStringContainsString('$request->queryInt(self::CATEGORY_AFTER)', $source);
        // Paging a read-only list is not a decision, so it leaves no trace.
        foreach (['update_option', 'set_transient', 'update_user_meta'] as $writer) {
            self::assertStringNotContainsString(
                $writer,
                $this->body('renderCategoryLinks'),
                'the category list writes nothing while it is being read'
            );
        }
    }

    public function testTheScreenOffersTheNextPageFromTheCursorItWasGiven(): void
    {
        $source = $this->body('renderCategoryLinks');

        self::assertStringContainsString("\$plan['cursor']", $source, 'the cursor is read');
        self::assertStringContainsString('categoryLinkUrl(', $source);
        self::assertStringContainsString('ادامه از اینجا', $source);
        // And the way back, so a manager three pages in is not stranded.
        self::assertStringContainsString('بازگشت به ابتدا', $source);
    }

    public function testTheWholeCatalogueFigureIsPrintedAndItsUnreadableAnswerIsNotNought(): void
    {
        $source = $this->body('renderCategoryLinks');

        self::assertStringContainsString("\$plan['unrecorded']", $source);
        // ⛔ Both halves. A page that printed the number without the guard
        // would read `-1` as a count, and a page with the guard and no number
        // would answer nothing at all.
        self::assertStringContainsString('$unrecorded < 0', $source);
        self::assertStringContainsString('عددی گزارش نمی‌شود', $source);
    }

    public function testTheApplyFormSaysHowManyRowsItCovers(): void
    {
        $source = $this->body('renderLinkApply');

        self::assertStringContainsString('count($apply) > ApplyCategoryLinks::BATCH_LIMIT', $source);
        self::assertStringContainsString('ApplyCategoryLinks::BATCH_LIMIT', $source);
        self::assertStringContainsString('ردیف آمادهٔ این صفحه را می‌برد', $source);
    }

    private function source(): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . self::PAGE);
        self::assertIsString($source);
        return $source;
    }

    /**
     * One method's body, extracted by matching braces.
     *
     * Not «up to the next `function`»: that was the first version of the
     * `alpha.77` rule and it cut a method in half. And not the whole file,
     * because a sentence in a neighbouring method is a different claim.
     */
    private function body(string $method): string
    {
        $source = $this->source();
        $at = strpos($source, 'function ' . $method . '(');
        self::assertIsInt($at, $method . '() not found');
        $open = strpos($source, '{', $at);
        self::assertIsInt($open);
        $depth = 0;
        $length = strlen($source);
        for ($i = $open; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $open, $i - $open + 1);
                }
            }
        }
        self::fail($method . '() has no closing brace');
    }
}
