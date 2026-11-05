<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The status repair writes ONE column, and that is a property of the bytes
 * rather than a promise in prose.
 *
 * The owner's §6 lists thirteen things that must survive the migration, and
 * every one of them is preserved here by the absence of a verb. So the guard
 * is a list of absent verbs measured from the source, in the shape
 * `SnapshotBatchWritesNothingToTheShopTest` established — a fake or a
 * refactor that reached for one of them would fail here before it could reach
 * a product.
 */
final class StatusReconciliationIsNarrowTest extends TestCase
{
    private const SERVICE = 'src/Modules/Migration/Application/ReconcileStorefrontStatus.php';
    private const RULE = 'src/Modules/Product/Domain/StorefrontStatusReconciliation.php';
    private const REPOSITORY = 'src/Modules/Product/Infrastructure/DbProductRepository.php';

    /**
     * Everything that would change a WooCommerce product. The storefront is
     * the REFERENCE for the publication status, not the subject: this path
     * reads `post_status` and writes our column to match it.
     */
    private const SHOP_WRITES = [
        'wp_update_post',
        'wp_insert_post',
        'update_post_meta',
        'wp_set_object_terms',
        'set_status',
        'set_catalog_visibility',
        'set_stock_quantity',
        'set_manage_stock',
        'set_regular_price',
        'set_sale_price',
        'set_sku',
        'set_category_ids',
        '->save(',
    ];

    /**
     * Everything in this plugin's own tables that this path must not touch.
     * `updateStatus()` is on the list and it is the interesting one: it writes
     * the status AND clears the stamp AND wipes `review_note` AND stamps
     * `published_at`, so reaching for it would turn a reconciliation into a
     * decision and release the catalogue visibility with it.
     */
    private const OUR_WRITES = [
        'updateStatus',
        'updateDetails',
        'updatePrice',
        'updateInventory',
        'saveSpecs',
        'saveImages',
        'saveBaseline',
        'setLinkOwnership',
        'setCategoryWhenEmpty',
        'recordCategorySet',
        'recordMirroredStatusWhenAbsent',
        '->take(',
        '->move(',
        '->giveBack(',
    ];

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
        self::fail($method . ' is unbalanced');
    }

    public function testTheServiceNeverTouchesAWooCommerceProduct(): void
    {
        $code = $this->code(self::SERVICE);
        foreach (self::SHOP_WRITES as $verb) {
            self::assertStringNotContainsString($verb, $code, 'the status repair must not ' . $verb);
        }
    }

    public function testTheServiceWritesNoOtherColumnOfOurOwn(): void
    {
        $code = $this->code(self::SERVICE);
        foreach (self::OUR_WRITES as $verb) {
            self::assertStringNotContainsString($verb, $code, 'the status repair must not call ' . $verb);
        }
    }

    /**
     * `plan()` is the read-only half, so it must not call the one writer
     * either — a preview that repaired as it previewed is the shape the
     * owner's «اجرای آزمایشی بدون تغییر داده‌ها» rules out.
     */
    public function testThePreviewCallsNoWriter(): void
    {
        $body = $this->methodBody($this->code(self::SERVICE), 'plan');
        self::assertStringNotContainsString('reconcileStatusToStorefront', $body);
        self::assertStringContainsString('stampedForVendorAfter', $body);
    }

    /**
     * And the one writer is reached from `repairOne()` alone, so there is a
     * single place where a column changes.
     */
    public function testExactlyOneCallSiteWrites(): void
    {
        $code = $this->code(self::SERVICE);
        self::assertSame(
            1,
            substr_count($code, 'reconcileStatusToStorefront('),
            'the status repair must have exactly one write call site'
        );
        self::assertStringContainsString(
            'reconcileStatusToStorefront(',
            $this->methodBody($code, 'repairOne')
        );
    }

    /**
     * ⛔ The three guards that make the rule unforgeable live in the
     * STATEMENT, not in a read before it (`alpha.50`). Asserted from the
     * repository's own bytes, because a later refactor that moved any of them
     * into PHP would leave every test green and the guard gone.
     */
    public function testTheWritersGuardsAreInTheStatement(): void
    {
        $body = $this->methodBody($this->code(self::REPOSITORY), 'reconcileStatusToStorefront');
        $tight = (string) preg_replace('/\s+/', '', $body);
        self::assertStringContainsString('WHEREid=%dANDstatus=%sAND`', $tight, 'both value guards must be in the WHERE');
        self::assertStringContainsString('row_version=row_version+1', $tight, 'the counter must change on every match');
        // One column, and the three it must leave alone are named here so a
        // broadened SET fails rather than passing quietly.
        self::assertStringContainsString('SETstatus=%s,updated_at=%s,row_version=row_version+1', $tight);
        foreach (['review_note', 'published_at'] as $column) {
            self::assertStringNotContainsString($column, $tight, 'the repair must not write ' . $column);
        }
        // The stamp appears ONCE, in the WHERE, never in the SET — clearing it
        // would release the catalogue visibility.
        self::assertSame(1, substr_count($tight, 'MIRRORED'), 'the stamp belongs in the WHERE only');
    }

    /**
     * ⛔ Two guards no behavioural test can reach, asserted from the source
     * because that is the honest treatment of a layer nothing can observe
     * (`alpha.77`). Both survived this round's falsification loop, and the
     * MEASURED reason for each is written here rather than guessed:
     *
     *  * **the blank stamp expectation.** Removing the PHP refusal changes
     *    nothing observable: `mb_substr('', 0, 20)` is `''`, so the statement
     *    becomes `… AND mirrored_status = ''`, no row matches, and the method
     *    answers `false` exactly as it did. It is a DOUBLE GUARD whose other
     *    half is the statement (`alpha.82`), and the PHP half saves a round
     *    trip that could never succeed. Neither half may go.
     *  * **the verdict re-read in `repairOne()`.** The service's RE-PLAN
     *    refuses any row whose verdict moved, because such a row leaves the
     *    repairable list and the digest no longer matches — measured in
     *    `testAManagersDecisionTakenInBetweenWins`. So the inner check covers
     *    only the window BETWEEN the re-plan and one iteration of the loop,
     *    which a single-threaded test cannot enter. What it buys is real: a
     *    named refusal instead of a `TypeError` from a `null` target, and the
     *    row's own status and stamp going into the `WHERE`.
     */
    public function testTheTwoUnreachableGuardsAreStillThere(): void
    {
        $repository = $this->methodBody($this->code(self::REPOSITORY), 'reconcileStatusToStorefront');
        $tight = (string) preg_replace('/\s+/', '', $repository);
        self::assertStringContainsString(
            "if(\$expectedStamp===''){",
            $tight,
            'a blank stamp expectation must be refused rather than sent'
        );
        self::assertStringContainsString(
            'returnfalse;',
            $tight,
            'and refusing it means returning false'
        );

        $repairOne = $this->methodBody($this->code(self::SERVICE), 'repairOne');
        $body = (string) preg_replace('/\s+/', '', $repairOne);
        // The row is read again, the verdict is asked again, and the write
        // comes AFTER both — asserted by position, because containment alone
        // would accept a check that ran after the write (`alpha.74`).
        $read = strpos($body, '$this->products->find($productId)');
        $judge = strpos($body, 'Rule::decide(');
        $guard = strpos($body, "\$verdict['verdict']!==Rule::REPAIR");
        $write = strpos($body, 'reconcileStatusToStorefront(');
        self::assertIsInt($read, 'the row must be read again');
        self::assertIsInt($judge, 'the verdict must be asked again');
        self::assertIsInt($guard, 'the verdict must be checked');
        self::assertIsInt($write, 'and then written');
        self::assertLessThan($judge, $read, 'read before judging');
        self::assertLessThan($guard, $judge, 'judge before guarding');
        self::assertLessThan($write, $guard, 'guard before writing');
        // And the values that go into the WHERE are the row's own, re-read.
        self::assertStringContainsString('$product->status,', $repairOne);
        self::assertStringContainsString('(string) $product->mirroredStatus', $repairOne);
    }

    /**
     * ⛔ **One field, read in bulk — and `detail()` is gone from this path.**
     *
     * This service used to call `$this->reader->detail($wcId)` per row and
     * read `['status']` out of it and nothing else: a `wc_get_product()`
     * load, a brand term query and a permalink lookup, to learn one word.
     * Fifty rows meant fifty product loads, which is the 552-read shape
     * `alpha.91` took apart on the hand-over screen — and it is what made the
     * whole-shop walk unaffordable, so step 7 of the wizard went unmeasured
     * instead of being paid for.
     *
     * So `detail()` must stay out, and BOTH the preview and the write must
     * read through the one helper: a guard that came from a different door
     * than the offer is the `alpha.78` fault.
     */
    public function testTheStatusPathReadsOneFieldInBulk(): void
    {
        $code = $this->code(self::SERVICE);
        self::assertStringNotContainsString(
            '->detail(',
            $code,
            'reading a whole product to learn its status is what this path stopped doing'
        );
        self::assertSame(
            1,
            substr_count($code, '->statusesOf('),
            'exactly one place asks the reader for statuses'
        );
        self::assertSame(
            2,
            substr_count($code, '$this->statusesFor('),
            'the preview and the write both read through it'
        );
    }

    /**
     * The rule itself asks nothing of the world: no database, no WordPress,
     * no reader. It is three values in and a verdict out, which is what makes
     * the unit test able to cover every case.
     */
    public function testTheRuleIsPure(): void
    {
        $code = $this->code(self::RULE);
        foreach (['$wpdb', 'get_post', 'wc_get_product', 'DatabaseInterface', 'ReaderInterface'] as $verb) {
            self::assertStringNotContainsString($verb, $code, 'the rule must not reach for ' . $verb);
        }
    }
}
