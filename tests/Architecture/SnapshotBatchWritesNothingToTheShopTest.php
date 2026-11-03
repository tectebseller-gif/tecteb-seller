<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Migration\Application\OwnershipTransferPlan;

/**
 * WHAT THIS PROVES: the batched re-snapshot writes only to this marketplace's
 * own tables, it cannot transfer ownership, the whole-shop tally writes
 * nothing at all, and both are reachable from exactly one explicit admin
 * action each.
 *
 * ⛔ **Why these are source assertions.** A behaviour test shows that one
 * batch left the WooCommerce products alone. It cannot show that the next
 * line added to the apply service will not call `take()` or
 * `update_post_meta()` — those lines would be reachable and every behaviour
 * test would stay green (`alpha.77`).
 *
 * The owner's §2 is the whole reason: «فقط اطلاعات داخلی بازارگاه
 * به‌روزرسانی شود؛ هیچ تغییری روی محصولات ووکامرس انجام نشود.»
 *
 * ⛔ Comments are stripped first: both files name what they forbid out loud
 * (`alpha.22`).
 */
final class SnapshotBatchWritesNothingToTheShopTest extends TestCase
{
    private const APPLY = 'src/Modules/Migration/Application/ApplyRefreshSnapshot.php';

    private const TALLY = 'src/Modules/Migration/Application/ShopTransferTally.php';

    private const INTAKE = 'src/Modules/Migration/Application/ImportExistingProducts.php';

    private const PAGE = 'src/Modules/Migration/Presentation/Admin/MigrationPage.php';

    /** Every verb that reaches a WooCommerce product or a WordPress post. */
    private const SHOP_WRITES = [
        'update_post_meta',
        'delete_post_meta',
        'add_post_meta',
        'wp_update_post',
        'wp_insert_post',
        'wp_delete_post',
        'wp_set_object_terms',
        'wp_insert_term',
        'set_status',
        'set_catalog_visibility',
        'set_manage_stock',
        'set_stock_quantity',
        'set_regular_price',
        'set_sku',
        '->save(',
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

    public function testTheBatchNeverTouchesAWooCommerceProduct(): void
    {
        $code = $this->code(self::APPLY);
        foreach (self::SHOP_WRITES as $verb) {
            self::assertStringNotContainsString($verb, $code, 'the snapshot batch must not ' . $verb);
        }
    }

    /**
     * And the one method it calls per unit writes only our own tables.
     *
     * ⛔ Per METHOD BODY rather than per file, because
     * `ImportExistingProducts` legitimately writes to WooCommerce elsewhere —
     * `refreshFrom()` is the one the batch reaches, and asserting the file
     * would be asserting the wrong thing (`alpha.77`).
     */
    public function testTheUnitItRunsWritesOnlyOurOwnTables(): void
    {
        $body = $this->methodBody($this->code(self::INTAKE), 'refreshFrom');
        foreach (self::SHOP_WRITES as $verb) {
            self::assertStringNotContainsString($verb, $body, 'refreshFrom() must not ' . $verb);
        }
        // What it DOES write, so «it writes nothing» cannot be satisfied by a
        // method that was emptied (`alpha.76`).
        self::assertStringContainsString('$this->products->updateDetails(', $body);
        self::assertStringContainsString('$this->products->saveBaseline(', $body);
        self::assertStringContainsString('$this->products->recordMirroredStatusWhenAbsent(', $body);
    }

    /** It is a re-snapshot and it cannot become a transfer. */
    public function testTheBatchCannotMoveOwnership(): void
    {
        $code = $this->code(self::APPLY);
        foreach (['->take(', '->giveBack(', 'setLinkOwnership', 'LinkOwnership::Marketplace'] as $verb) {
            self::assertStringNotContainsString($verb, $code, 'the snapshot batch must not ' . $verb);
        }
        self::assertStringContainsString('$this->transfer->refreshFromStorefront($productId)', $code);
    }

    /** The four guards, in the order the transfer batch uses. */
    public function testTheBatchRePlansAndRefusesAnythingItDidNotOffer(): void
    {
        $body = $this->methodBody($this->code(self::APPLY), 'apply');
        self::assertStringContainsString('$this->capabilities->can(Capabilities::REVIEW_VENDOR)', $body);
        self::assertStringContainsString('$plan = $this->plan->plan($vendorUserId, $afterId);', $body);
        self::assertStringContainsString(
            "hash_equals(OwnershipTransferPlan::digestOf(\$offered), trim(\$seenDigest))",
            $body
        );
        self::assertStringContainsString("'selection_not_offered'", $body);
        // Refused WHOLE rather than filtered: doing part of something nobody
        // asked for is not a smaller version of that request.
        self::assertStringContainsString('if ($requested !== $offered) {', $body);
    }

    /** It stops at the first failure and names the product. */
    public function testTheBatchStopsAtTheFirstFailure(): void
    {
        $body = $this->methodBody($this->code(self::APPLY), 'apply');
        self::assertStringContainsString('if (!$result->ok) {', $body);
        self::assertStringContainsString("return \$this->answer(false, 'stopped', \$refreshed, \$productId", $body);
    }

    /** The audit row is written on the failure path too. */
    public function testTheBatchIsAuditedWhetherItFinishedOrStopped(): void
    {
        $body = $this->methodBody($this->code(self::APPLY), 'apply');
        self::assertSame(
            2,
            substr_count($body, '$this->record($vendorUserId,'),
            'one audit call on the stopped path and one on the finished path'
        );
    }

    /** The tally has no write of any kind. */
    public function testTheTallyIsReadOnly(): void
    {
        $code = $this->code(self::TALLY);
        foreach (array_merge(self::SHOP_WRITES, [
            'update_option',
            'set_transient',
            'update_user_meta',
            '->execute(',
            'INSERT',
            'UPDATE',
            'DELETE',
            '->refreshFromStorefront(',
        ]) as $verb) {
            self::assertStringNotContainsString($verb, $code, 'the tally must not ' . $verb);
        }
    }

    /** And it stores no position: the cursor travels in the query string. */
    public function testTheTallyStoresNoCursor(): void
    {
        $body = $this->methodBody($this->code(self::TALLY), 'of');
        foreach (['update_option', 'set_transient', 'update_user_meta', 'update_post_meta'] as $verb) {
            self::assertStringNotContainsString($verb, $body);
        }
        self::assertStringContainsString("'finished' =>", $body);
        self::assertStringContainsString("'stopped_for_budget' =>", $body);
    }

    /** One explicit admin action, named once, on a POST behind the nonce. */
    public function testTheBatchIsReachableFromExactlyOneAction(): void
    {
        $code = $this->code(self::PAGE);
        self::assertSame(
            1,
            substr_count($code, "'apply_refresh_batch'"),
            'exactly one handler branch'
        );
        self::assertSame(
            1,
            substr_count($code, 'value="apply_refresh_batch"'),
            'exactly one button'
        );
        self::assertStringContainsString('ApplyRefreshSnapshot::class', $code);
    }

    /**
     * The re-snapshot's offer list covers the buckets a refresh can clear,
     * and NOT the ones it cannot.
     *
     * Asserted on the constant rather than on behaviour, because the promise
     * «after this batch these rows are ready» is what the page prints, and a
     * list that quietly grew to include a real risk would make that number
     * untrue with no test failing.
     */
    public function testOnlyTheBucketsARefreshCanClearAreOffered(): void
    {
        self::assertSame(
            [OwnershipTransferPlan::DRIFT_FIXABLE, OwnershipTransferPlan::STATUS_BLOCKED],
            OwnershipTransferPlan::REFRESHABLE
        );
        self::assertNotContains(OwnershipTransferPlan::RISK_REAL, OwnershipTransferPlan::REFRESHABLE);
        self::assertNotContains(OwnershipTransferPlan::UNREADABLE, OwnershipTransferPlan::REFRESHABLE);
        self::assertNotContains(
            OwnershipTransferPlan::NO_STOREFRONT_PRODUCT,
            OwnershipTransferPlan::REFRESHABLE
        );
    }
}
