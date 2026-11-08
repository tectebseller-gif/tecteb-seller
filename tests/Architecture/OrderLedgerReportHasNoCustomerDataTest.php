<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: the per-line order report cannot leak a customer.
 *
 * «هیچ اطلاعات محرمانه مشتری نباید بی‌دلیل در گزارش یا خروجی CSV فروشنده افشا
 * شود» is a statement about absence, and the strongest form of it available
 * here is not a rule a screen keeps — it is that the TABLE has no such column
 * and the report reads nothing else.
 *
 * So this says both halves: the service and the page name no customer-shaped
 * field and no WooCommerce order or customer API, and `tmc_order_items`
 * declares no column that could hold one. The second half is read out of the
 * migration that creates it, so a column added later has to pass this test.
 */
final class OrderLedgerReportHasNoCustomerDataTest extends TestCase
{
    private const SERVICE = __DIR__ . '/../../src/Modules/Order/Application/OrderLedgerReport.php';
    private const PAGE = __DIR__ . '/../../src/Modules/Order/Presentation/Admin/OrderLedgerPage.php';
    private const REPOSITORY = __DIR__ . '/../../src/Modules/Order/Infrastructure/DbOrderItemRepository.php';

    /** @return array<string,array{string}> */
    public static function forbidden(): array
    {
        return [
            // The customer, in every spelling WooCommerce offers one.
            'customer object' => ['get_customer'],
            'customer id' => ['customer_id'],
            'billing' => ['billing'],
            'shipping address' => ['get_address'],
            'buyer e-mail' => ['get_billing_email'],
            'buyer telephone' => ['get_billing_phone'],
            'user lookup' => ['get_userdata'],
            'user by field' => ['get_user_by'],
            'wp user object' => ['WP_User'],
            // Loading an order at all: it is both the privacy hazard and the
            // performance one, and the report is built so that neither is
            // reachable.
            'order factory' => ['wc_get_order'],
            'orders query' => ['wc_get_orders'],
            // The customer view this plugin HAS. It exists for the vendor's
            // own order screen, where a shop legitimately needs an address to
            // post a parcel to — and it has no business in a financial
            // report.
            'customer view' => ['OrderCustomerView'],
            'order reader' => ['WcOrderReader'],
        ];
    }

    /** @dataProvider forbidden */
    public function testTheReportCannotReachACustomer(string $needle): void
    {
        foreach ([self::SERVICE, self::PAGE] as $file) {
            $source = self::code($file);
            self::assertNotSame('', $source, $file . ' must be readable for this test to mean anything');
            self::assertStringNotContainsString($needle, $source, basename($file) . ': ' . $needle);
        }
    }

    /**
     * ⛔ WHERE THE OTHER HALF OF THIS LIVES, and why it is not here.
     *
     * «The table has no customer column» is the stronger statement, and the
     * first version of it was attempted in this file by scanning the migration
     * sources. That was wrong twice over. It began vacuous — PHP's `glob()`
     * does not expand `**`, so it matched nought files and every assertion
     * passed about an empty string — and once the scan was fixed it produced a
     * false positive, because a migration file that touches the order-items
     * table also declares a vendor's bank details for another table, and a
     * whole-file text search cannot tell the two apart.
     *
     * A column set is a fact about a table, and the place to measure a fact
     * about a table is against a real one. It is asserted in
     * `tests/Database/OrderLedgerReportTest.php`, from
     * `information_schema.COLUMNS` — which cannot be vacuous and cannot be
     * confused by a neighbour's `ALTER`.
     */
    /**
     * WHAT THIS PROVES: the report's query selects named columns, not `*`.
     *
     * `SELECT *` would hand the page whatever the table grows next, which is
     * how a column added for one screen reaches another. The detailed read
     * names every column it wants.
     */
    public function testTheDetailedQueryNamesItsColumns(): void
    {
        $body = self::methodBody(self::REPOSITORY, 'detailed');
        self::assertStringContainsString('SELECT id, wc_order_id', $body);
        self::assertStringNotContainsString('SELECT *', $body);
    }

    /**
     * WHAT THIS PROVES: the count and the page share one `WHERE`.
     *
     * Two spellings of one filter are two filters that agree until somebody
     * edits one — and on a report the symptom is a pager that promises rows
     * the table does not show (`alpha.87`, on a list and its own counter).
     */
    public function testTheCountAndThePageAskTheSameQuestion(): void
    {
        $body = self::methodBody(self::REPOSITORY, 'detailed');
        self::assertSame(
            1,
            substr_count($body, 'detailedWhere('),
            'the WHERE must be built once and used by both halves'
        );
        self::assertStringContainsString('COUNT(*)', $body);
        self::assertStringContainsString('LIMIT %d OFFSET %d', $body);
    }

    /**
     * WHAT THIS PROVES: the sort comes from a fixed list, never from the
     * request.
     *
     * An `ORDER BY` assembled from a query string is an injection, and a
     * `match` with a default is the shape that cannot be one.
     */
    public function testTheSortIsAMatchWithADefault(): void
    {
        $body = self::methodBody(self::REPOSITORY, 'orderBy');
        // ⛔ The MATCH IS THE WHOLE BODY, not merely present in it.
        //
        // The falsification loop measured this: a mutation that left the
        // `match` exactly where it was and put `return $sort !== '' ? $sort :`
        // in front of it kept every string this test looked for, so the guard
        // stayed green while `ORDER BY` came straight from the query string.
        // `alpha.98`'s rule — the existence of a string is not the method
        // being called — on a method whose shape IS the guarantee.
        self::assertSame(
            1,
            preg_match('/^\s*\{\s*return match \(\$sort\) \{/', $body),
            'the sort must be the match itself, with nothing in front of it'
        );
        self::assertStringContainsString('default =>', $body);
        // Every branch ends in `id`, because `created_at` has second
        // precision and thirty rows in one second move a page boundary on
        // every request (`alpha.32`).
        foreach (['created_at ASC, id ASC', 'base_minor DESC, id DESC', 'created_at DESC, id DESC'] as $clause) {
            self::assertStringContainsString($clause, $body);
        }
    }

    /** The body of one method, taken by matching braces rather than a window. */
    private static function methodBody(string $file, string $name): string
    {
        $source = self::code($file);
        $at = strpos($source, 'function ' . $name . '(');
        self::assertIsInt($at, $name . ' must exist for this test to mean anything');
        $open = strpos($source, '{', $at);
        self::assertIsInt($open, $name . ' must have a body');
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
        return '';
    }

    private static function code(string $file): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token)) {
                $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }
}
