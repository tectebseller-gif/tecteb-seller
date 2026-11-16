<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: no class that shares an element with `.tv-card` resets that
 * card's bottom margin.
 *
 * This is a defect of THIS round, found by its own falsification loop rather
 * than by reading, and it is the exact shape `alpha.32` keeps producing: a
 * class name is a contract, and two components that pick the same name do not
 * collide in PHP — they collide in the cascade, silently.
 *
 * MEASURED. §2 added a disclosure to the comparison cell and gave it the block
 * root `tv-fold` — a name `DashboardView` has owned since `alpha.33` for the
 * registration card. The new rule was `.tv-fold { margin: 0 }` at line 407 of
 * the vendor stylesheet; `.tv-card { … margin-block-end: var(--tv-s4) }` is at
 * line 308. Equal specificity (0,1,0), later wins — so the vendor dashboard's
 * «اطلاعات و مدارک فروشگاه» card, which carries BOTH classes, lost the gap
 * between itself and whatever follows it. Nothing in this repository could see
 * that: `StyledClassesHaveRulesTest` asks whether a class has A rule, not
 * whether the rule it has belongs to it.
 *
 * ⛔ **Margin only, and the reason is written here so the next round does not
 * widen it.** `.tv-card` sets `padding` as well, and `.tv-fold { padding: 0 }`
 * has overridden it deliberately since `alpha.33`: that disclosure's own
 * summary and list supply their padding, and a card's inner padding is a
 * decision the block inside it may take. The bottom margin is not — it is the
 * page's vertical rhythm BETWEEN cards, owned by the primitive, and a block
 * that resets it removes a gap it does not own. A guard wider than the defect
 * is a bigger defect (`alpha.60`).
 */
final class CardRhythmIsNotResetTest extends TestCase
{
    private const SHEET = 'assets/vendor/tmc-vendor.css';

    /** The primitive that owns the rhythm, and the property it owns. */
    private const PRIMITIVE = 'tv-card';

    public function testNothingSharingAnElementWithACardResetsItsMargin(): void
    {
        $css = self::stylesheet();
        $offenders = [];
        foreach (self::classesSharingAnElementWith(self::PRIMITIVE) as $class) {
            foreach (self::standaloneBlocksFor($css, $class) as $body) {
                if (preg_match('/(^|;)\s*margin(-block(-end)?)?\s*:/i', $body) === 1) {
                    $offenders[] = '.' . $class . ' { ' . trim(preg_replace('/\s+/', ' ', $body) ?? '') . ' }';
                }
            }
        }
        self::assertSame(
            [],
            $offenders,
            "these classes share an element with .tmc-card and reset the rhythm it owns:\n"
            . implode("\n", $offenders)
        );
    }

    /**
     * WHAT THIS PROVES: the measurement above is reachable — the primitive is
     * still in the sheet with a bottom margin, and something still shares an
     * element with it.
     *
     * Without this, the test above passes on a stylesheet that lost
     * `.tv-card` entirely, and a guard that cannot fail is indistinguishable
     * from an absent one (`alpha.105`).
     */
    public function testThePrimitiveStillOwnsABottomMarginAndStillSharesElements(): void
    {
        $css = self::stylesheet();
        $blocks = self::standaloneBlocksFor($css, self::PRIMITIVE);
        self::assertNotSame([], $blocks, '.' . self::PRIMITIVE . ' has no rule of its own any more');
        $sets = false;
        foreach ($blocks as $body) {
            if (preg_match('/(^|;)\s*margin(-block(-end)?)?\s*:/i', $body) === 1) {
                $sets = true;
            }
        }
        self::assertTrue($sets, '.' . self::PRIMITIVE . ' no longer sets a margin, so there is no rhythm to protect');
        self::assertNotSame(
            [],
            self::classesSharingAnElementWith(self::PRIMITIVE),
            'no view writes another class beside .' . self::PRIMITIVE . ', so the check above measures nothing'
        );
    }

    /** The stylesheet with its comments emptied (`alpha.22`). */
    private static function stylesheet(): string
    {
        $css = (string) file_get_contents(__DIR__ . '/../../' . self::SHEET);
        return (string) preg_replace('/\/\*.*?\*\//s', '', $css);
    }

    /**
     * Bodies of the rules whose WHOLE selector is this one class. A compound
     * selector is a different weight and a different claim; only the bare
     * single-class block competes with the primitive on equal terms.
     *
     * @return list<string>
     */
    private static function standaloneBlocksFor(string $css, string $class): array
    {
        preg_match_all('/(?m)^\s*\.' . preg_quote($class, '/') . '\s*\{([^}]*)\}/', $css, $matches);
        return $matches[1];
    }

    /**
     * Every other `tv-` class that some view writes in the SAME `class="…"`
     * attribute as the given one — derived from the views, never typed, so a
     * component added next round is covered the day it is written.
     *
     * @return list<string>
     */
    private static function classesSharingAnElementWith(string $class): array
    {
        $found = [];
        foreach (self::viewFiles() as $file) {
            $code = self::withoutComments((string) file_get_contents($file));
            preg_match_all('/class="([^"]*)"/', $code, $matches);
            foreach ($matches[1] as $attribute) {
                $classes = [];
                foreach (preg_split('/\s+/', $attribute) ?: [] as $one) {
                    $one = trim($one);
                    if (preg_match('/^tv-[A-Za-z0-9_-]+$/', $one) === 1) {
                        $classes[] = $one;
                    }
                }
                if (!in_array($class, $classes, true)) {
                    continue;
                }
                foreach ($classes as $one) {
                    if ($one !== $class) {
                        $found[$one] = true;
                    }
                }
            }
        }
        return array_keys($found);
    }

    /** @return list<string> */
    private static function viewFiles(): array
    {
        $files = [];
        $root = __DIR__ . '/../../src';
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    private static function withoutComments(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        return $code;
    }
}
