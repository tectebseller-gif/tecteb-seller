<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tecteb\Marketplace\Modules\Order\Application\ReconcileOrders;

/**
 * WHAT THIS PROVES: the reconciliation audit cannot write, because it has
 * nothing to write with.
 *
 * ⛔ **THE OWNER'S WORDS, AS A TEST.** «هیچ اصلاح مالی خودکاری روی داده واقعی
 * انجام نده» and «یک کنترل تطبیق fail-closed و فقط‌خواندنی». This project's
 * rule for that claim is `alpha.9`/`alpha.86`: «read only» is enforced by the
 * ABSENCE OF A COLLABORATOR, never by a sentence in a docblock — a service
 * holding a transaction and promising not to use it is one edit from using it.
 *
 * So three things are asserted: the constructor takes no writer, the bytes
 * contain no writing verb, and the screen above it has no form that posts.
 */
final class ReconciliationWritesNothingTest extends TestCase
{
    private const SERVICE = 'src/Modules/Order/Application/ReconcileOrders.php';
    private const PAGE = 'src/Modules/Order/Presentation/Admin/OrderAuditPage.php';
    private const READER = 'src/Modules/Order/Infrastructure/WooCommerce/WcOrderLineReader.php';

    /** Verbs that change something, in SQL, in WordPress and in WooCommerce. */
    private const WRITING = [
        'INSERT', 'UPDATE ', 'DELETE', 'REPLACE INTO', 'TRUNCATE', 'ALTER ', 'DROP ',
        '->execute(', '->query(', 'begin(', 'commit(', 'rollback(',
        'update_post_meta', 'delete_post_meta', 'add_post_meta', 'update_metadata',
        'wp_insert_post', 'wp_update_post', 'wp_delete_post', 'wp_insert_term',
        'update_option', 'add_option', 'delete_option', 'set_transient',
        'wc_create_refund', 'wc_update_product_stock', 'wc_reduce_stock_levels',
        '->save(', '->set_status(', '->record(', '->updateStatus(',
        '->completeFinancials(', '->recordWcOrderStatus(', '->recordSettlementCompletion(',
    ];

    /** @return list<string> */
    private static function bytes(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        self::assertFileExists($path);
        return (string) file_get_contents($path);
    }

    /**
     * Comments blanked first: the docblocks of all three files DESCRIBE the
     * verbs they must not call, and a byte search cannot tell prose from code
     * (`alpha.22`).
     */
    private static function code(string $relative): string
    {
        $out = '';
        foreach (token_get_all(self::bytes($relative)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= str_repeat(' ', strlen($token[1]));
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    public function testTheServiceContainsNoWritingVerb(): void
    {
        $found = [];
        foreach ([self::SERVICE, self::PAGE, self::READER] as $file) {
            $code = self::code($file);
            foreach (self::WRITING as $verb) {
                if (stripos($code, $verb) !== false) {
                    $found[] = $verb . ' (' . $file . ')';
                }
            }
        }
        self::assertSame([], $found, 'the reconciliation audit writes nothing');
    }

    /**
     * ⛔ AND IT HOLDS NO WRITER. The constructor's own types are the guard:
     * a transaction, a clock or a logger would each be a way to change
     * something, and an audit logger in particular would make this screen
     * write a row every time somebody looked.
     */
    public function testTheServiceHoldsNothingThatCouldWrite(): void
    {
        $constructor = (new ReflectionClass(ReconcileOrders::class))->getConstructor();
        self::assertNotNull($constructor);
        $types = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $types[] = $type === null ? 'mixed' : (string) $type;
        }
        self::assertSame([
            '?Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface',
            '?Tecteb\Marketplace\Modules\Finance\Application\LedgerRepositoryInterface',
            '?Tecteb\Marketplace\Contracts\CapabilityCheckerInterface',
            '?Tecteb\Marketplace\Modules\Order\Application\OrderLineReaderInterface',
            '?Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface',
        ], array_map(
            static fn (string $t): string => str_starts_with($t, '?') ? $t : '?' . $t,
            $types
        ), 'no transaction, no clock, no logger');
    }

    /**
     * The screen has no form that POSTs and no nonce, because it has no
     * action to protect: both controls on it are `GET` addresses.
     */
    public function testTheScreenOffersNoAction(): void
    {
        $code = self::code(self::PAGE);
        foreach (['wp_nonce_field', 'check_admin_referer', 'admin-post.php', "method=\"post\"", "method='post'"] as $needle) {
            self::assertStringNotContainsStringIgnoringCase(
                $needle,
                $code,
                'the audit screen must offer nothing to press'
            );
        }
    }

    /**
     * ⛔ AND IT REFUSES BEFORE IT READS. The capability is asked in the first
     * statement of `pass()`, not after the window has been fetched: a report
     * that read the rows and then decided not to show them has already read
     * them (`alpha.86`).
     */
    public function testThePermissionIsAskedBeforeAnythingIsRead(): void
    {
        $code = self::code(self::SERVICE);
        $gate = strpos($code, 'VIEW_VENDOR_FINANCE');
        $read = strpos($code, 'linesToReconcile');
        // `assertIsInt` first: `strpos` answers `false` when the needle is
        // gone, and `false < anything` is true in PHP — so without this the
        // guard would pass on a file that had lost the capability check
        // altogether, which is the one thing it exists to catch.
        self::assertIsInt($gate, 'the capability check must be present');
        self::assertIsInt($read, 'the window read must be present');
        self::assertLessThan($read, $gate, 'the capability comes first');
    }
}
