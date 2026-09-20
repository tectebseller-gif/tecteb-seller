<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Every repository that writes a table a report reads must fire the hook that
 * retires the cache.
 *
 * This is the guard against the one way a cached financial figure goes wrong
 * in practice: not a bug in the cache, but a NEW write path added months later
 * by somebody who never heard of it. A reviewer cannot be expected to
 * remember; a failing test can.
 *
 * It is deliberately a grep and not a runtime check. The alternative — proving
 * at runtime that some future method fires an action — requires calling that
 * method, which means knowing it exists, which is the thing that was forgotten.
 */
final class ReportInvalidationTest extends TestCase
{
    private const HOOK = 'tmc_vendor_figures_changed';

    /**
     * The tables the four vendor cards and the two manager cards are built
     * from, each with the repository that writes it.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function writers(): array
    {
        return [
            'order lines — every sales and finance figure' =>
                ['src/Modules/Order/Infrastructure/DbOrderItemRepository.php', 'tmc_order_items'],
            'the ledger — the vendor share and the marketplace total' =>
                ['src/Modules/Finance/Infrastructure/DbLedgerRepository.php', 'tmc_ledger_entries'],
            'shipments and returns — the operations card' =>
                ['src/Modules/Order/Infrastructure/DbShipmentRepository.php', 'tmc_shipments'],
            'withdrawals — what is settled and what is not' =>
                ['src/Modules/Finance/Infrastructure/DbWithdrawalRepository.php', 'tmc_withdrawals'],
        ];
    }

    /**
     * @dataProvider writers
     */
    public function testEveryWriterOfAReportedTableFiresTheHook(string $file, string $table): void
    {
        $path = dirname(__DIR__, 2) . '/' . $file;
        self::assertFileExists($path, $table . ' is read by a report; its repository must exist');
        $source = (string) file_get_contents($path);

        self::assertStringContainsString(
            self::HOOK,
            $source,
            $file . " writes {$table}, which a report reads, so it must fire " . self::HOOK
        );
        // Fired through the helper, not scattered: the helper is where the
        // «only when the write succeeded» and «only for a real shop» rules
        // live, and a raw `do_action` beside it would skip both.
        self::assertStringContainsString(
            'private function figuresChanged(int $vendorUserId): void',
            $source,
            $file . ' must fire the hook through figuresChanged(), not inline'
        );
    }

    public function testTheListenerAndTheHookNameAgree(): void
    {
        $listener = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Modules/Marketplace/Infrastructure/WordPress/ReportCacheInvalidation.php'
        );
        self::assertStringContainsString(
            "public const HOOK = '" . self::HOOK . "'",
            $listener,
            'the listener must listen to the name the writers fire'
        );
    }

    /**
     * A report may never be invalidated by the thing that READS it.
     *
     * `alpha.16` learned this on the store page: `VendorRoutes` forgot the
     * cache BEFORE the save, and a read landing in the gap re-cached the stale
     * body under the new version. Invalidation belongs to the write.
     */
    public function testTheReportsScreenNeverInvalidatesItsOwnCache(): void
    {
        $page = dirname(__DIR__, 2) . '/src/Modules/Marketplace/Presentation/Admin/ReportsPage.php';
        if (!is_file($page)) {
            self::markTestSkipped('the manager reports screen is not in this build');
        }
        $source = (string) file_get_contents($page);
        self::assertStringNotContainsString('forgetVendor', $source);
        self::assertStringNotContainsString('forgetMarketplace', $source);
    }
}
