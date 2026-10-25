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
        'recordCategorySet(' => self::WRITER,  // allowed in the writer only
        // And the SINGLE-key writer is forbidden even here. It still exists and
        // is still right for the Dokan mapping path (`ApplyCategoryMapping`),
        // where the manager picks one destination — but on THIS path the value
        // is a product's own set, and storing one id of it is the collapse this
        // round removed.
        'setCategoryWhenEmpty(' => '',
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

    /**
     * The writer reaches ONE repository method, and that method's reach is the
     * link table plus the one key column.
     *
     * The claim got one word wider this round and no weaker: before, the whole
     * write was `category_key`; now it is the link rows plus that same key
     * when — and only when — the set has exactly one member. What the test is
     * for is unchanged: there must be no road from here to a status, an
     * ownership flag, a published flag, a price or a ledger line.
     */
    public function testTheWriterTouchesOneColumnAndTheLinkTableAndNothingElse(): void
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
        self::assertStringContainsString(
            'recordCategorySet(',
            $code,
            'and it does record the set'
        );
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
        self::assertStringContainsString('recordCategorySet(', $body);
        // And the comparison is SET equality, not string equality: the page
        // prints the ids in one order and `get_category_ids()` promises none,
        // so comparing the spellings would report `link_moved` about a
        // relationship nobody touched. «برابری تعداد کافی نیست» cuts the other
        // way too — a reordering is not a change.
        self::assertStringContainsString('CategorySet::same(', $body);
        // The four states with nothing to record still refuse by name, so a
        // product WooCommerce left on the default term, or one whose terms
        // could not be read, is never written as though it had been read.
        self::assertStringContainsString('$refusal', $body);
        self::assertStringContainsString('CategoryLink::DEFAULT_ONLY', $body);
    }

    /**
     * The recorded value is the product's OWN terms, whole.
     *
     * This is the heart of the owner's instruction: «مرجع باید شناسه‌های واقعی
     * ترم‌های همان محصول در ووکامرس باشد — نه نام، نه شباهت متنی، نه اولین
     * دسته» and «مجموعهٔ کامل دسته‌ها باید حفظ شود». So the set comes from
     * `$link->validIds` — the ids read from `product_cat` for that product —
     * and every spelling of «take one of them» is forbidden in source,
     * `definiteKey()` among them.
     *
     * ⛔ `definiteKey()` is on the forbidden side now, and that is a reversal
     * of `alpha.81` on purpose. It answers `''` for a multi-category product by
     * construction, which was the right refusal while a set could not be
     * recorded; reaching it today would turn the owner's nine products back
     * into «no single key» and record nothing for them.
     */
    public function testTheRecordedSetComesOnlyFromTheProductsOwnTerms(): void
    {
        $body = $this->methodBody($this->code(self::WRITER), 'applyOne');

        self::assertStringContainsString('$link->validIds', $body);
        self::assertStringContainsString('CategorySet::of(', $body, 'normalised in one place');
        foreach (
            ['validIds[0]', 'termIds[0]', 'reset($', 'array_shift(', 'definiteKey('] as $needle
        ) {
            self::assertStringNotContainsString($needle, $body, 'never one of several: ' . $needle);
        }
    }

    /**
     * The single key is derived, never chosen.
     *
     * `CategorySet::soleKey()` answers `''` for a set of two or more rather
     * than naming a winner, because which category's spec template applies to
     * a multi-category product is the owner's open decision
     * (`docs/open-decisions-alpha82.md` §1) and «انتخاب اولین، عمیق‌ترین یا
     * ترکیب خودکار قواعد مجاز نیست». Pinned here so a later edit cannot make
     * the writer pick one and leave the decision answered by accident.
     */
    public function testTheSingleKeyIsDerivedFromTheSetAndNeverChosen(): void
    {
        $body = $this->methodBody($this->code(self::WRITER), 'applyOne');

        self::assertStringContainsString('CategorySet::soleKey(', $body);
        foreach (['sort(', 'usort(', 'max(', 'min(', 'count($ids) >'] as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $body,
                'the writer must not rank the set: ' . $needle
            );
        }
    }
}
