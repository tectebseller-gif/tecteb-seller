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

    /**
     * The one file allowed to put either key into a write.
     *
     * It moved in `alpha.61`, and the move is the point: the panel used to
     * write the two keys with two `update_post_meta()` calls, and a failure on
     * the second left a pair nobody asked for. The two calls now live in
     * `CommissionRuleWriter`, which locks the rows, writes them inside one
     * transaction and verifies from the rows — so this assertion ALSO says the
     * panel can no longer write a half of the rule, because the panel is not
     * in the list.
     */
    private const WRITER = 'src/Modules/Finance/Infrastructure/WooCommerce/CommissionRuleWriter.php';

    /** The one file allowed to read them. */
    private const READER = 'src/Modules/Finance/Infrastructure/WooCommerce/WpTectebCommissionSource.php';

    /** And the one that refuses everybody else's write to them. */
    private const GUARD = 'src/Modules/Finance/Infrastructure/WooCommerce/TectebCommissionMetaGuard.php';

    public function testOnlyOneFileWritesTheCommissionMeta(): void
    {
        $writers = [];
        foreach (self::sourceFiles() as $relative => $code) {
            if (!str_contains($code, self::TYPE_META)
                && !str_contains($code, self::VALUE_META)
                && !str_contains($code, 'WpTectebCommissionSource::TYPE_META')
                && !str_contains($code, 'WpTectebCommissionSource::VALUE_META')) {
                continue;
            }
            // `update_post_meta`, `add_post_meta`, `delete_post_meta`,
            // `update_metadata`: the ways a post meta row changes through
            // WordPress. A file that mentions a key and none of these is
            // reading.
            //
            // `$wpdb` used to be on that list, and it had to come off: the
            // guard asks `$wpdb` which keys the DATABASE calls its own,
            // because `strtolower(rtrim())` is a Unicode collation imitated
            // in PHP. So raw SQL is judged by its VERB instead, and the
            // guard is held to reading in the test below.
            $throughWordPress = preg_match('/\b(update_post_meta|add_post_meta|delete_post_meta|update_metadata)\b/', $code) === 1;
            if (!$throughWordPress && preg_match('/\b(INSERT\s+INTO|UPDATE\s+`?\w|DELETE\s+FROM|->(query|insert|update|replace|delete)\s*\()/i', $code) !== 1) {
                continue;
            }
            $writers[] = $relative;
        }
        self::assertSame(
            [self::WRITER],
            $writers,
            "these files can write the commission meta; only the one-unit writer may:\n" . implode("\n", $writers)
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
            // The vendor's form and its CSV importer, the vendor area, and
            // the REST surface. This test says those files have never heard
            // of the keys — it does NOT say the keys cannot be written from
            // elsewhere, which is a different claim and belongs to
            // `testTheKeysAreGuardedOnEveryWritePathAndNotOnlyOurs()` below.
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
     * WHAT THIS PROVES: the guard only ever READS from the database.
     *
     * It asks `$wpdb` one question — whether this key collates equal to one
     * of ours — so it is the one file here allowed to name the keys beside
     * `$wpdb`. That permission is narrow and this is what keeps it narrow: a
     * guard that could write would be a second writer of the thing it
     * guards, which is the shape of defect this whole file exists to
     * prevent.
     */
    public function testTheGuardOnlyReadsFromTheDatabase(): void
    {
        $guard = self::code(self::GUARD);

        self::assertMatchesRegularExpression('/\bSELECT\b/', $guard, 'it does ask the database something');
        foreach (['INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'TRUNCATE', 'ALTER', 'DROP'] as $verb) {
            self::assertDoesNotMatchRegularExpression(
                '/\b' . $verb . '\b/i',
                $guard,
                'the guard must not be able to write: ' . $verb
            );
        }
        // The needles carry their opening parenthesis, because
        // `update_post_meta` is a SUBSTRING of `update_post_metadata` — the
        // filter this guard registers — and a needle that is part of another
        // word answers about that word (`alpha.33`). The first version of
        // this test failed on exactly that and was right to.
        foreach (['update_post_meta(', 'add_post_meta(', 'delete_post_meta(', '->query(', '->insert(', '->update('] as $writer) {
            self::assertStringNotContainsString($writer, $guard, 'the guard must not write: ' . $writer);
        }
    }

    /**
     * WHAT THIS PROVES: the keys are guarded where WooCommerce writes them
     * too, and the guard cannot be quietly dropped.
     *
     * The test above proves nothing about WooCommerce's own writers, and a
     * security review of the pushed `alpha.57` commit was right about it:
     * `WC_REST_Products_Controller` hands a request's `meta_data` to
     * `update_meta_data()` and `WC_Product_CSV_Importer` turns a `meta:<key>`
     * column into the same call — both permitted by `edit_products` /
     * `manage_woocommerce`, which a `shop_manager` holds and which is NOT the
     * marketplace's financial permission. «فقط پنل می‌نویسد» was true of
     * our code and false of the two keys, which is the `alpha.48` rule in new
     * clothes: a write that happens «somewhere else» does not pass a guard
     * that is not on that path.
     *
     * So the guard is asserted to be registered UNCONDITIONALLY — not inside
     * the `class_exists(\WooCommerce::class)` branch the panel sits in, since
     * a guard that depends on load order is a guard waiting to be bypassed.
     */
    public function testTheKeysAreGuardedOnEveryWritePathAndNotOnlyOurs(): void
    {
        $guard = self::code(self::GUARD);
        foreach (['add_post_metadata', 'update_post_metadata', 'delete_post_metadata'] as $filter) {
            self::assertStringContainsString(
                "'" . $filter . "'",
                $guard,
                $filter . ' must be guarded'
            );
        }
        self::assertStringContainsString('TectebCommissionPanel::CAPABILITY', $guard, 'the guard must ask the financial permission, not `edit_post`');

        $module = self::code('src/Modules/Finance/FinanceModule.php');
        self::assertStringContainsString('TectebCommissionMetaGuard::register();', $module);

        // …and outside the WooCommerce branch: everything between that `if`
        // and its closing brace is conditional, so the guard must not be in
        // it. Measured from the bytes rather than trusted to a sentence.
        $branch = strpos($module, 'TectebCommissionPanel::register($c);');
        $registered = strpos($module, 'TectebCommissionMetaGuard::register();');
        self::assertIsInt($branch);
        self::assertIsInt($registered);
        $between = substr($module, $branch, $registered - $branch);
        self::assertSame(
            1,
            substr_count($between, '}'),
            'the guard is inside the WooCommerce conditional; it must be registered unconditionally'
        );
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
     * WHAT THIS PROVES: the number step 4 shows a vendor comes from the SAME
     * rule the ledger will record.
     *
     * `alpha.57` moved commission to the product and `RecordCommission` has
     * consulted nothing else since — but `EstimateVendorShare` went on
     * resolving the scoped general rate, so the form promised a share the
     * sale would not produce. Measured on the installed `alpha.65` bytes:
     * `rate_source=general`, `rate_bp=1000`, commission 45,000 on a 450,000
     * product whose own commission meta was `__absent__`.
     *
     * Asserted from the module's BYTES and inside the binding, for the reason
     * the test above gives: an optional dependency the container forgets is a
     * silent fall back to the model the owner replaced.
     */
    public function testTheContainerWiresTheOwnersModelIntoTheShareEstimateToo(): void
    {
        $module = self::code('src/Modules/Product/ProductModule.php');
        $at = strpos($module, 'new EstimateVendorShare(');
        self::assertNotFalse($at, 'EstimateVendorShare is not constructed here any more');
        $binding = substr($module, $at, 600);
        self::assertStringContainsString(
            'TectebCommissionSourceInterface::class',
            $binding,
            'step 4 would estimate from the legacy scoped rate'
        );
        self::assertStringContainsString('TectebCommissionCalculator::class', $binding);

        // And the service itself asks the per-product source FIRST, so the
        // scoped rate is reachable only on a build that has no source at all.
        $service = self::code('src/Modules/Product/Application/EstimateVendorShare.php');
        $tecteb = strpos($service, 'tectebRules !== null');
        $scoped = strpos($service, 'rates->forItem(');
        self::assertNotFalse($tecteb, 'the per-product branch is gone');
        self::assertNotFalse($scoped, 'the legacy branch was deleted rather than kept as a fallback');
        self::assertLessThan($scoped, $tecteb, 'the scoped rate is asked before the product rule');
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
