<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Admin\Presentation\AdminExtensions;
use Tecteb\Marketplace\Modules\Finance\Application\LedgerRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerAccount;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerTransaction;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Application\ShipmentRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\ReturnRequest;
use Tecteb\Marketplace\Modules\Order\Domain\ReturnStatus;
use Tecteb\Marketplace\Modules\Order\Domain\VendorOrderItem;
use Tecteb\Marketplace\Modules\Order\Presentation\Admin\ReturnsPage;
use TmcWpStubs\State;

/**
 * WHAT THIS PROVES: the reversal written by the REAL admin path — a POST to
 * the returns page, through the registered render callback — is in the unit
 * the sale was recorded in.
 *
 * `ShipmentAndReturnFlowTest` calls `ManageReturns::refund()` directly, which
 * is where the unit is read. This file asks the other question, and it is the
 * one `alpha.39` got wrong: WHAT DOES THE PAGE PASS? The answer then was
 * «nothing», and «nothing» meant the method's `'IRR', 0` defaults decided the
 * unit of every reversal on every site. A test on the service alone could not
 * see that, because the service was perfectly capable of being told the right
 * unit by a caller that never did.
 *
 * So the POST is built the way the form builds it — the same field names, the
 * same nonce action — and the page is invoked through
 * `AdminExtensions::pages()`, not by calling a method on the class.
 */
final class ReturnPagePathTest extends DatabaseTestCase
{
    private const VENDOR = 61;

    protected function setUp(): void
    {
        parent::setUp();
        State::reset();
        $this->bootPlugin(true);
        $this->loginAdmin();
        // Dropped and rebuilt by prefix: the contract suite's stub wpdb is a
        // real PDO on this same database, so a row one test leaves behind is
        // the next one's starting state — and the first version of this file
        // had both tests writing the same ledger event key.
        $this->resetSchema(new WpDatabase($this->wpdb));
        Bootstrap::container()->get(MigrationRunner::class)->run();
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        parent::tearDown();
    }

    public function testThePagesOwnRefundActionWritesTheReversalInTheRecordedUnit(): void
    {
        // A sale recorded in تومان — the unit the owner's site actually uses,
        // and one the `'IRR', 0` default is NOT.
        [$itemId, $eventKey] = $this->soldIn('IRT', 0, 100000, 10000);
        $returnId = $this->receivedReturn($itemId, 1);

        $this->post([
            'return_action' => ReturnStatus::Refunded->value,
            'return_id' => (string) $returnId,
            'tmc_returns_nonce' => wp_create_nonce('tmc_returns'),
        ]);
        $html = $this->renderReturnsPage();

        /** @var ShipmentRepositoryInterface $returns */
        $returns = Bootstrap::container()->get(ShipmentRepositoryInterface::class);
        self::assertSame(
            ReturnStatus::Refunded,
            $returns->findReturn($returnId)?->status,
            'the page really performed the refund: ' . $this->firstNotice($html)
        );

        /** @var LedgerRepositoryInterface $ledger */
        $ledger = Bootstrap::container()->get(LedgerRepositoryInterface::class);
        $reversal = $ledger->forEvent('return:' . $itemId . ':' . $returnId);
        self::assertNotSame([], $reversal, 'a reversal was written');
        foreach ($reversal as $entry) {
            self::assertSame('IRT', $entry->amount->currency, 'every reversal line is in the sale unit');
            self::assertSame(0, $entry->amount->exponent);
        }

        // And the vendor's books hold one unit, which is the thing a balance
        // depends on.
        $units = [];
        foreach ($ledger->forVendor(self::VENDOR, 500) as $entry) {
            $units[$entry->amount->currency . '/' . $entry->amount->exponent] = true;
        }
        self::assertSame(['IRT/0'], array_keys($units));
        self::assertNotSame([], $ledger->forEvent($eventKey), 'the accrual is still there, unchanged');
    }

    /**
     * WHAT THIS PROVES: a line that POINTS at a ledger event the ledger does
     * not have is refused by name on the page path, with nothing written.
     *
     * Note which legacy shape this is, because the other one is already
     * covered elsewhere: a line with an EMPTY `ledger_event` never reaches the
     * unit read at all — `isRecorded()` is false and `refund()` answers
     * `nothing_recorded` first, which is also correct and also writes nothing.
     * The state that needs the unit check is the mirror image: share and rate
     * recorded, an event key stored, and no event behind it. That is what
     * `alpha.38`'s unchecked ledger write could leave, and until now the
     * refund path would have reversed it in `IRR`/0 against nothing.
     */
    public function testThePageRefusesALineWhoseEventIsNotInTheLedgerAndWritesNothing(): void
    {
        [$itemId, $eventKey] = $this->soldIn('IRT', 0, 100000, 10000);
        $returnId = $this->receivedReturn($itemId, 1);

        // The event key stays on the line; the event leaves the ledger.
        // Written with SQL because no service produces this state — the point
        // is to meet it, not to make it.
        $ledgerTable = $this->wpdb->prefix . M0004CreateFinanceTables::LEDGER;
        $this->wpdb->pdo()->exec("DELETE FROM `{$ledgerTable}` WHERE event_key = '{$eventKey}'");
        /** @var OrderItemRepositoryInterface $items */
        $items = Bootstrap::container()->get(OrderItemRepositoryInterface::class);
        self::assertTrue($items->find($itemId)?->isRecorded(), 'the line still claims a recorded share');

        $this->post([
            'return_action' => ReturnStatus::Refunded->value,
            'return_id' => (string) $returnId,
            'tmc_returns_nonce' => wp_create_nonce('tmc_returns'),
        ]);
        $html = $this->renderReturnsPage();

        /** @var ShipmentRepositoryInterface $returns */
        $returns = Bootstrap::container()->get(ShipmentRepositoryInterface::class);
        self::assertSame(
            ReturnStatus::Received,
            $returns->findReturn($returnId)?->status,
            'the return is exactly where it was'
        );
        /** @var LedgerRepositoryInterface $ledger */
        $ledger = Bootstrap::container()->get(LedgerRepositoryInterface::class);
        self::assertSame([], $ledger->forEvent('return:' . $itemId . ':' . $returnId));

        // And the page SAYS so, in Persian, naming the line — a refusal a
        // manager cannot read is a refusal nobody acts on.
        self::assertStringContainsString('واحد پول', $html);
        self::assertStringContainsString('چیزی ثبت نشد', $html);
    }

    // ------------------------------------------------------------- fixture

    /** @param array<string,string> $fields */
    private function post(array $fields): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $fields;
        $_REQUEST = $fields;
        $_GET = ['page' => ReturnsPage::SLUG];
    }

    private function renderReturnsPage(): string
    {
        do_action('admin_menu');
        foreach (AdminExtensions::pages() as $page) {
            if ($page['slug'] !== ReturnsPage::SLUG) {
                continue;
            }
            return $this->capture(static function () use ($page): void {
                ($page['render'])();
            });
        }
        self::fail('the returns page is not registered');
    }

    /** The first notice the page printed, for a failure message worth reading. */
    private function firstNotice(string $html): string
    {
        return preg_match('/<p[^>]*>(.{0,200}?)</su', $html, $m) === 1 ? trim(strip_tags($m[1])) : '(no notice)';
    }

    /**
     * One sold line with its accrual, in a chosen unit.
     *
     * Written through the repositories rather than through `CaptureOrder`,
     * because this file is about the PAGE and a product-publishing fixture
     * would put thirty lines of unrelated setup between the question and the
     * answer. The accrual carries the four lines a real capture writes.
     *
     * @return array{0:int,1:string}
     */
    private function soldIn(string $currency, int $exponent, int $baseMinor, int $taxMinor): array
    {
        static $seq = 0;
        $seq++;
        $orderItemId = 9100 + $seq;
        $orderId = 7100 + $seq;
        $eventKey = 'order:' . $orderId . ':item:' . $orderItemId;
        $share = (int) ($baseMinor * 0.9);
        $commission = $baseMinor - $share;

        /** @var LedgerRepositoryInterface $ledger */
        $ledger = Bootstrap::container()->get(LedgerRepositoryInterface::class);
        $money = static fn (int $minor): Money => Money::of($minor, $currency, $exponent);
        self::assertTrue($ledger->record(
            (new LedgerTransaction($eventKey, self::VENDOR, (string) $orderId, (string) $orderItemId))
                ->add(LedgerAccount::CentralPayment, $money($baseMinor + $taxMinor), 'item_paid')
                ->add(LedgerAccount::Commission, $money($commission)->negate(), 'commission_due')
                ->add(LedgerAccount::VendorEarning, $money($share)->negate(), 'vendor_earned')
                ->add(LedgerAccount::TaxCollected, $money($taxMinor)->negate(), 'tax_collected')
        ));

        /** @var OrderItemRepositoryInterface $items */
        $items = Bootstrap::container()->get(OrderItemRepositoryInterface::class);
        $itemId = $items->record(new VendorOrderItem(
            0,
            $orderId,
            $orderItemId,
            0,
            self::VENDOR,
            'کالای صفحهٔ مرجوعی',
            'PAGE-1',
            1,
            $baseMinor,
            $baseMinor,
            $taxMinor,
            $commission,
            $share,
            1000,
            'general',
            $eventKey,
            OrderItemStatus::Delivered
        ));
        self::assertGreaterThan(0, $itemId);
        return [$itemId, $eventKey];
    }

    private function receivedReturn(int $itemId, int $quantity): int
    {
        /** @var ShipmentRepositoryInterface $returns */
        $returns = Bootstrap::container()->get(ShipmentRepositoryInterface::class);
        $returnId = $returns->openReturn(new ReturnRequest(
            0,
            $itemId,
            self::VENDOR,
            $quantity,
            ReturnStatus::Requested,
            'آزمون',
            '',
            requestedBy: 1,
            requestedAt: '2030-05-01 09:00:00'
        ));
        self::assertGreaterThan(0, $returnId);
        self::assertTrue($returns->updateReturnStatus($returnId, ReturnStatus::Approved, 1, '', null, null));
        self::assertTrue($returns->updateReturnStatus($returnId, ReturnStatus::Received, 1, '', null, null));
        return $returnId;
    }
}
