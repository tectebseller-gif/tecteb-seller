<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: «کمیسیون تک‌طب» has exactly ONE writer, and the owner's
 * model is the one a real install uses.
 *
 * §5-هـ is a list of four doors — «فروشنده یا پرسنل بدون مجوز مدیریت مالی
 * نباید بتواند کمیسیون مدیر را از طریق فرم، CSV، درخواست دستکاری‌شده یا API
 * تغییر دهد» — and the strongest way to close four doors is for there to be
 * one. So this test does not check a guard: it searches the whole plugin for
 * a second writer of the two meta keys and fails if it finds one. A guard can
 * be forgotten at a call site; an absent writer cannot.
 *
 * The same test asserts the wiring, because an optional dependency that the
 * container forgets is a silent fall back to the model the owner replaced —
 * and on this site there has been a default commission rate since `alpha.6`,
 * so every product would inherit one. `RecordCommission` says in its docblock
 * that this file is the proof rather than that sentence.
 *
 * Comments are blanked before anything is counted: this repository has
 * learned three times that bytes make no distinction between code and prose,
 * and a meta key named in a docblock explaining this rule would be read as a
 * write (`alpha.22`).
 */
final class CommissionOwnershipTest extends TestCase
{
    private const TYPE_META = '_tmc_commission_type';
    private const VALUE_META = '_tmc_commission_value';

    /** The one file allowed to put either key into a write. */
    private const WRITER = 'src/Modules/Finance/Infrastructure/WooCommerce/TectebCommissionPanel.php';

    /** The one file allowed to read them. */
    private const READER = 'src/Modules/Finance/Infrastructure/WooCommerce/WpTectebCommissionSource.php';

    public function testOnlyThePanelWritesTheCommissionMeta(): void
    {
        $writers = [];
        foreach (self::sourceFiles() as $relative => $code) {
            if (!str_contains($code, self::TYPE_META)
                && !str_contains($code, self::VALUE_META)
                && !str_contains($code, 'WpTectebCommissionSource::TYPE_META')
                && !str_contains($code, 'WpTectebCommissionSource::VALUE_META')) {
                continue;
            }
            // `update_post_meta`, `add_post_meta`, `delete_post_meta` and
            // `$wpdb`: the four ways a post meta row changes. A file that
            // mentions a key and none of these is reading.
            if (preg_match('/\b(update_post_meta|add_post_meta|delete_post_meta|update_metadata|wpdb)\b/', $code) !== 1) {
                continue;
            }
            $writers[] = $relative;
        }
        self::assertSame(
            [self::WRITER],
            $writers,
            "these files can write the commission meta; only the panel may:\n" . implode("\n", $writers)
        );
    }

    /**
     * WHAT THIS PROVES: the vendor's own doors do not mention the keys at all.
     *
     * Named rather than derived, because the test above only says «this file
     * does not write». A vendor form that READ the key and echoed it into a
     * hidden field would be a different defect — the `alpha.52` shape, where
     * a form carried a value the box did not show — and the honest rule for a
     * manager-only financial field is that the vendor's surface has never
     * heard of it.
     */
    public function testNoVendorFacingPathMentionsTheCommissionMeta(): void
    {
        $seen = [];
        foreach (self::sourceFiles() as $relative => $code) {
            if ($relative === self::WRITER || $relative === self::READER) {
                continue;
            }
            // The vendor's form and its CSV importer, the vendor area, and the
            // REST surface. Three of the four doors §5-هـ names; the fourth,
            // a crafted request, is the panel's nonce and capability.
            $vendorFacing = str_contains($relative, '/Modules/Product/Application/')
                || str_contains($relative, '/Modules/Vendor/')
                || str_contains($relative, '/Modules/Api/')
                || str_contains($relative, '/Rest/');
            if (!$vendorFacing) {
                continue;
            }
            if (str_contains($code, self::TYPE_META) || str_contains($code, self::VALUE_META)) {
                $seen[] = $relative;
            }
        }
        self::assertSame([], $seen, "a vendor-facing file names the commission meta:\n" . implode("\n", $seen));
    }

    /**
     * WHAT THIS PROVES: the container hands `RecordCommission` the owner's
     * model, so the legacy scoped path is unreachable in a real install.
     */
    public function testTheContainerWiresTheOwnersCommissionModel(): void
    {
        $module = self::code('src/Modules/Finance/FinanceModule.php');

        self::assertStringContainsString(
            'TectebCommissionSourceInterface::class',
            $module,
            'the per-product commission source is not bound'
        );
        self::assertStringContainsString(
            'TectebCommissionCalculator::class',
            $module,
            'the per-product calculator is not bound'
        );

        // Both INSIDE the RecordCommission binding, not merely somewhere in
        // the file: a binding that exists and is never injected is the same
        // as no binding at all.
        $at = strpos($module, 'new RecordCommission(');
        self::assertNotFalse($at, 'RecordCommission is not constructed here any more');
        $binding = substr($module, $at, 600);
        self::assertStringContainsString('TectebCommissionSourceInterface::class', $binding);
        self::assertStringContainsString('TectebCommissionCalculator::class', $binding);
    }

    /**
     * WHAT THIS PROVES: a missing commission is no longer a reason the order
     * gate refuses to operate.
     *
     * §7 asked for exactly this one blocker to be cleared, so it is checked by
     * ABSENCE of the production — the constant survives as a name for stored
     * rows, and nothing assigns it.
     */
    public function testTheOrderGateNoLongerRefusesASaleForWantOfACommission(): void
    {
        $gate = self::code('src/Modules/Order/Application/OrderOperationsGate.php');

        self::assertSame(
            1,
            preg_match_all('/RATE_UNSET/', $gate),
            'RATE_UNSET must appear once — its declaration — and never be produced'
        );
        // …and the decisions and the ledger probe are still there, because
        // «سایر دروازه‌های مستقل … را خودکار باز نکن».
        self::assertStringContainsString('DECISIONS_OPEN', $gate);
        self::assertStringContainsString('LEDGER_UNWRITABLE', $gate);
        self::assertStringContainsString("'DEC-02', 'DEC-04'", $gate);
    }

    /**
     * Every PHP file under `src/`, with comments blanked.
     *
     * @return array<string,string> repo-relative path => code without comments
     */
    private static function sourceFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace($root . '/', '', (string) $file->getRealPath());
            $files[$relative] = self::code($relative);
        }
        ksort($files);
        return $files;
    }

    private static function code(string $relative): string
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
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
