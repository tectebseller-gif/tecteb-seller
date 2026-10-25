<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\SensitiveChange;

/**
 * WHAT THIS PROVES: a field the approval path APPLIES is a field the review
 * page DRAWS — in both directions, and for the set-valued fields in
 * particular.
 *
 * ⛔ **Why this test exists at all.** `alpha.82` made the category set
 * sensitive, so a vendor's proposed set now becomes a revision and approving
 * that revision writes it. `diffTable()` draws its rows by looping over
 * `SensitiveChange::SENSITIVE` and comparing with
 * `(string) $current === (string) $next` — which a set cannot ride: casting an
 * array to a string is a notice and the word «Array», and `categoryKey` is
 * `''` on both sides of a multi-category change because `soleKey()`
 * deliberately picks no winner. The measured consequence, before the row
 * existed: the manager pressed «تأیید تغییر» on a category change that never
 * appeared on their screen.
 *
 * That is the `alpha.49` rule one object along — consent is about the value
 * that was SHOWN — and the owner's own requirement that the preview show each
 * group's category set. So the binding is asserted here rather than left to a
 * reviewer's memory, and in BOTH directions, because a name on the list that
 * no page draws and a page that draws a field no list carries are two halves
 * of the same mistake (`alpha.76`).
 *
 * ⛔ Comments are stripped first: this file's own prose names every symbol it
 * forbids, and a rule checked against raw source is satisfied by the prose
 * describing it (`alpha.22`).
 */
final class SensitiveSetsAreShownBeforeApprovalTest extends TestCase
{
    private const PAGE = 'src/Modules/Product/Presentation/Admin/ProductReviewPage.php';

    private function code(string $path): string
    {
        $full = dirname(__DIR__, 2) . '/' . $path;
        self::assertFileExists($full);
        $out = '';
        foreach (token_get_all((string) file_get_contents($full)) as $token) {
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

    public function testTheListIsNotEmpty(): void
    {
        // Without this, every assertion below passes vacuously — the shape of
        // a guard that cannot fail (`alpha.46`).
        self::assertNotSame([], SensitiveChange::SENSITIVE_SETS);
        self::assertContains('categoryIds', SensitiveChange::SENSITIVE_SETS);
    }

    public function testEverySensitiveSetIsComparedAsASet(): void
    {
        $body = $this->methodBody(
            $this->code('src/Modules/Product/Domain/SensitiveChange.php'),
            'between'
        );
        self::assertStringContainsString(
            'self::SENSITIVE_SETS',
            $body,
            'between() must read the list, not a hand-written field name'
        );
        self::assertStringContainsString(
            'CategorySet::same(',
            $body,
            'a set is compared with CategorySet::same(), never with !== — '
                . '[245,244] against [244,245] is the same relationship'
        );
    }

    public function testEverySensitiveSetHasASetAccessor(): void
    {
        // A name on the list with no accessor must throw rather than read as
        // an empty set: `[]` on both sides is «no change», which is precisely
        // the silence this round closed.
        $details = new ProductDetails(title: 'x', categoryIds: [244, 245]);
        foreach (SensitiveChange::SENSITIVE_SETS as $field) {
            self::assertSame([244, 245], $details->setFor($field));
        }
        $this->expectException(\InvalidArgumentException::class);
        $details->setFor('somethingNobodyWrote');
    }

    public function testTheReviewPageDrawsEverySensitiveSet(): void
    {
        $body = $this->methodBody($this->code(self::PAGE), 'diffTable');
        self::assertStringContainsString(
            'SensitiveChange::SENSITIVE_SETS',
            $body,
            'the review table must derive its set rows from the same list the '
                . 'approval applies, so no field can be approvable and invisible'
        );
        self::assertStringContainsString(
            'CategorySet::same(',
            $body,
            'the row is drawn when the sets differ as SETS'
        );
        self::assertStringContainsString(
            'array_key_exists(',
            $body,
            'a payload from before alpha.82 carries no set, approval keeps the '
                . 'live one, and a row about a change that will not be made is '
                . 'the alpha.13 fault in review clothing'
        );
    }

    public function testTheSetRowNamesEachCategoryAndItsPath(): void
    {
        $code = $this->code(self::PAGE);
        $body = $this->methodBody($code, 'categorySetText');
        self::assertStringContainsString(
            'ProductCategoryDirectoryInterface',
            $body,
            'the path comes from the real taxonomy, not from a stored name'
        );
        self::assertStringContainsString(
            '->path',
            $body,
            'the FULL path: «لوازم جانبی» is on six branches of this catalogue, '
                . 'so a leaf name alone lets a move between branches read as no change'
        );
        self::assertStringContainsString(
            'diffTable',
            $code,
            'and the helper is only worth having because diffTable calls it'
        );
    }

    public function testEverySensitiveNameHasALabel(): void
    {
        // A row headed `categoryIds` is a row a Persian-reading manager cannot
        // act on, and `ProductMessages::field()` falls back to the raw key.
        //
        // Asserted from the SOURCE rather than by calling the method: the
        // architecture suite defines no WordPress symbols on purpose, and
        // `field()` calls `__()` on every arm.
        $body = $this->methodBody(
            $this->code('src/Modules/Product/Presentation/ProductMessages.php'),
            'field'
        );
        foreach ([...SensitiveChange::SENSITIVE, ...SensitiveChange::SENSITIVE_SETS] as $field) {
            self::assertStringContainsString(
                "'" . $field . "'",
                $body,
                'no Persian label for sensitive field: ' . $field
            );
        }
    }
}
