<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: every form on the category path carries the whole SET,
 * and the sentence the owner asked to have removed is gone from the product.
 *
 * ⛔ **Why these are source assertions.** A behaviour test can show that one
 * form posted two ids today. It cannot show that no later edit will put one
 * id back in the hidden input — and that single change is invisible from the
 * outside until a multi-category product silently posts `''`, fails the
 * freshness comparison and comes back as «the categories moved». That is
 * exactly what `alpha.81` shipped, one object away.
 *
 * The owner's instruction is the whole reason:
 *
 *   «مجموعهٔ کامل دسته‌ها باید حفظ شود، حتی وقتی والد و فرزند هم‌زمان
 *    متصل‌اند» · «پیام «نیازمند انتخاب یک دسته» برای این موارد حذف شود» ·
 *   «در زمان اعمال، دسته‌های فعلی دوباره با پیش‌نمایش مقایسه شود.»
 *
 * ⛔ Comments are stripped first. Every one of these files explains the very
 * thing being forbidden — one of them quotes the retired sentence in order to
 * say it was retired — and a rule checked against raw source is satisfied by
 * the prose describing it (`alpha.22`).
 */
final class CategoryConsentCarriesTheWholeSetTest extends TestCase
{
    private const HANDOVER = 'src/Modules/Migration/Presentation/Admin/HandoverPage.php';

    private const FORM = 'src/Modules/Product/Infrastructure/WordPress/ProductArea.php';

    /** @return list<string> */
    private function phpFiles(): array
    {
        $root = dirname(__DIR__, 2) . '/src';
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }
        sort($out);
        return $out;
    }

    private function code(string $path): string
    {
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

    public function testTheApplyFormPostsTheWholeSetAndNotOneKey(): void
    {
        $body = $this->methodBody(
            $this->code(dirname(__DIR__, 2) . '/' . self::HANDOVER),
            'renderLinkApply'
        );

        self::assertStringContainsString(
            'CategorySet::toValue(',
            $body,
            'the hidden input carries the set in the one spelling the service decodes'
        );
        self::assertStringContainsString('proposed_ids', $body, 'and it reads the set, not a key');
        // ⛔ `proposed_key` is `''` for a multi-category product BY
        // CONSTRUCTION (`CategorySet::soleKey()`), so posting it would send
        // consent to nothing for exactly the products this round exists for.
        foreach (['proposed_key', 'proposed_path\'', 'definiteKey('] as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $body,
                'the consent must not be one id: ' . $needle
            );
        }
    }

    public function testTheVendorFormReadsEveryPostedIdSeparately(): void
    {
        $code = $this->code(dirname(__DIR__, 2) . '/' . self::FORM);

        self::assertStringContainsString("postIdList('category')", $code);
        // ⛔ Measured, not argued: `sanitize_key('207,208,209')` is
        // `207208209` on this WordPress — the separators are stripped and
        // three ids fuse into one that resolves to nothing
        // (`docs/evidence/alpha82-audit/before-alpha81.txt`).
        self::assertStringNotContainsString(
            "postKey('category')",
            $code,
            'a sanitised key cannot carry a set'
        );
    }

    /**
     * The one writer guards against a second writer IN THE STATEMENT.
     *
     * ⛔ Asserted in source because no behaviour test can reach it. The PHP
     * check above it refuses a recorded set that is not contained in this one,
     * and sequentially that is enough — the statement's `NOT IN` only earns
     * its keep when a second request records a DIFFERENT set between this
     * one's read and its write. Removing it leaves every test green and lets
     * two writers merge `{244,245}` and `{244,246}` into a set nobody asked
     * for (`alpha.50`, and `alpha.77` on why this is a source assertion).
     */
    public function testTheClaimCarriesItsGuardsInsideTheStatement(): void
    {
        $body = $this->methodBody(
            $this->code(dirname(__DIR__, 2) . '/src/Modules/Product/Infrastructure/DbProductRepository.php'),
            'recordCategorySet'
        );

        self::assertStringContainsString('FOR UPDATE', $body, 'the recorded set is read under a lock');
        self::assertStringContainsString('NOT EXISTS', $body, 'and the claim carries its own conditions');
        self::assertStringContainsString(
            'NOT IN',
            $body,
            'including «no id is recorded that this set does not contain»'
        );
        self::assertStringContainsString('import_run_id <> %s', $body, 'and only an imported row');
        // And the key is written into an EMPTY one only, so a legacy key
        // survives — the owner's open decision is not taken here.
        self::assertStringContainsString('AND category_key = %s', $body);
    }

    public function testTheRetiredSentenceIsGoneFromTheWholeProduct(): void
    {
        $retired = 'نیازمند تصمیم انسان';
        $offenders = [];
        foreach ($this->phpFiles() as $path) {
            if (str_contains($this->code($path), $retired)) {
                $offenders[] = substr($path, strlen(dirname(__DIR__, 2)) + 1);
            }
        }
        self::assertSame(
            [],
            $offenders,
            'the owner asked for this sentence to go: ' . implode(', ', $offenders)
        );
    }
}
