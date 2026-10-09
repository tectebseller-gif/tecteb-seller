<?php
declare(strict_types=1);

/*
 * The named defects of this round, asked of whatever build is on disk.
 *
 * ### Why a probe and not the round's own tests
 *
 * `alpha.32` learned this the hard way: a test that names a class the old tree
 * does not have is a FATAL at load, **zero tests execute**, and every «the
 * defect reproduced» line is a report about nothing having run. So this file
 * asks only questions both builds can answer — `reserve()`, `updateStatus()`,
 * `cancel()`, `capture()`, `recordWooCommerceRefund()` — and prints `key=value`
 * lines that the shell compares. It never names a symbol introduced by
 * `alpha.39`, and where a return array grew a key it reads it with `??` so the
 * old shape answers «absent» rather than crashing.
 *
 * The one place a signature CHANGED is `RefundRecorderInterface::record()`
 * (`float $amount` → `string $amount`). The spy below declares `float|string`,
 * which is wider than either, and PHP's parameter contravariance makes it a
 * legal implementation of both. So one spy, two builds, no reflection tricks.
 *
 * Usage:
 *   php tests/Support/alpha38-probe.php <verb>
 *
 * Verbs and what they measure are listed in `tools/alpha38-reproduction.sh`,
 * which is the only intended caller. Every verb prints at least one
 * `key=value` line and `probe=ran`; a verb that prints no `probe=ran` did not
 * execute, and the shell treats that as a broken measurement rather than as a
 * result.
 */

require dirname(__DIR__) . '/bootstrap-contract.php';

use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Config\SettingsService;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpAuditRepository;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Infrastructure\WordPress\WpOptionStore;
use Tecteb\Marketplace\Modules\Finance\Application\RecordCommission;
use Tecteb\Marketplace\Modules\Finance\Application\RequestWithdrawal;
use Tecteb\Marketplace\Modules\Finance\Application\ResolveCommissionRate;
use Tecteb\Marketplace\Modules\Finance\Application\ReviewWithdrawals;
use Tecteb\Marketplace\Modules\Finance\Application\SettlementGate;
use Tecteb\Marketplace\Modules\Finance\Application\VendorBalance;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionCalculator;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionRate;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerAccount;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerTransaction;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Finance\Domain\RateScope;
use Tecteb\Marketplace\Modules\Finance\Domain\WithdrawalStateMachine;
use Tecteb\Marketplace\Modules\Finance\Domain\WithdrawalStatus;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbCommissionRuleRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbLedgerRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbVendorMoneyUnitRegistry;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbWithdrawalRepository;
use Tecteb\Marketplace\Modules\Order\Application\CaptureOrder;
use Tecteb\Marketplace\Modules\Order\Application\ManageOrderItems;
use Tecteb\Marketplace\Modules\Order\Application\ManageReturns;
use Tecteb\Marketplace\Modules\Order\Application\OrderOperationsGate;
use Tecteb\Marketplace\Modules\Order\Application\RefundRecorderInterface;
use Tecteb\Marketplace\Modules\Order\Application\RefundScope;
use Tecteb\Marketplace\Modules\Order\Application\ReturnTerms;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStateMachine;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\ReturnStateMachine;
use Tecteb\Marketplace\Modules\Order\Domain\ReturnStatus;
use Tecteb\Marketplace\Modules\Order\Domain\VendorOrderItem;
use Tecteb\Marketplace\Modules\Order\Infrastructure\DbOrderItemRepository;
use Tecteb\Marketplace\Modules\Order\Infrastructure\DbShipmentRepository;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbVariationRepository;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbStaffRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbStoreRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Tests\Support\FailingDatabase;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
use Tecteb\Marketplace\Tests\Support\FakeCatalogProjector;
use Tecteb\Marketplace\Tests\Support\FakeTrialUnlock;
use Tecteb\Marketplace\Tests\Support\InterferingDatabase;
use TmcWpStubs\State;

foreach (['TMC_TEST_DB_DSN', 'TMC_TEST_DB_USER', 'TMC_TEST_DB_PASS'] as $var) {
    if (getenv($var) === false) {
        fwrite(STDERR, "alpha38-probe: {$var} is not set\n");
        exit(1);
    }
}
if (!str_contains((string) getenv('TMC_TEST_DB_DSN'), 'tmc_test')) {
    fwrite(STDERR, "alpha38-probe: refuses any DSN that is not the disposable tmc_test\n");
    exit(1);
}

const VENDOR = 91;
const MANAGER = 9;

/**
 * A refund recorder that writes nothing and remembers the argument.
 *
 * `$amount` is declared `float|string` on purpose: it is `float` on
 * `alpha.38`'s interface and `string` on `alpha.39`'s, and PHP's parameter
 * contravariance makes a union wider than either a legal implementation of
 * both. One spy, two trees, no reflection.
 */
final class ProbeRefundSpy implements RefundRecorderInterface
{
    /** @var list<float|string> */
    public array $amounts = [];

    public function isAvailable(): bool
    {
        return true;
    }

    public function canTransferMoney(int $wcOrderId): bool
    {
        return false;       // no gateway in any build, and none added here
    }

    public function moneyBlockers(int $wcOrderId): array
    {
        return ['gateway_missing'];
    }

    public function findExisting(int $wcOrderId, int $returnId): int
    {
        return 0;
    }

    public function record(
        int $wcOrderId,
        int $wcOrderItemId,
        float|string $amount,
        int $quantity,
        string $reason,
        int $returnId
    ): array {
        $this->amounts[] = $amount;
        return [
            'ok' => true,
            'reason' => 'refund_recorded',
            'refund_id' => 7001,
            'money_moved' => false,
            'remaining' => '999999999',
        ];
    }
}

/** Everything the probes share, built once. */
final class ProbeWorld
{
    public WpDatabase $db;
    public SystemClock $clock;
    public AuditLogger $audit;
    public SettingsService $settings;
    public DbOrderItemRepository $orderItems;
    public DbWithdrawalRepository $withdrawals;
    public DbLedgerRepository $ledger;
    public DbProductRepository $products;
    public DbCommissionRuleRepository $rules;
    public DbStoreRepository $stores;
    public DbVendorRepository $vendors;
    public StaffAccess $access;
    public VendorBalance $balance;

    public function __construct()
    {
        State::$optionsBackedByWpdb = true;
        $GLOBALS['wpdb'] = new \wpdb();
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->ensureOptionsTable();
        $wpdb->pdo()->exec("TRUNCATE TABLE `{$wpdb->options}`");

        $this->db = new WpDatabase($wpdb);
        $this->clock = new SystemClock();
        $this->audit = new AuditLogger(new WpAuditRepository($wpdb), new AuditEventSanitizer(), $this->clock);
        $this->settings = new SettingsService(new WpOptionStore());

        // The real migration chain, the same way `DatabaseTestCase` builds
        // it — never a list this file keeps, which is how the suite's lists
        // went stale. Dropped first by PREFIX: a probe that inherits the
        // previous verb's rows is measuring the previous verb.
        $this->dropEverything($wpdb);
        foreach (Bootstrap::migrations() as $migration) {
            $migration->up($this->db);
        }

        $this->orderItems = new DbOrderItemRepository($this->db, $this->clock);
        $this->withdrawals = new DbWithdrawalRepository($this->db, $this->clock);
        $this->ledger = new DbLedgerRepository($this->db, $this->clock);
        $this->products = new DbProductRepository($this->db, $this->clock);
        $this->rules = new DbCommissionRuleRepository($this->db, $this->clock);
        $this->stores = new DbStoreRepository($this->db, $this->clock);
        $this->vendors = new DbVendorRepository($this->db, $this->clock);
        $this->access = new StaffAccess(new DbStaffRepository($this->db, $this->clock), $this->vendors);
        $this->balance = new VendorBalance($this->orderItems, $this->settings, $this->clock);

        $this->vendors->upsertProfile(VENDOR, 'داروخانهٔ probe', true, false);
        $this->stores->saveBank(VENDOR, 'IR000000000000000000000000', 'دارندهٔ حساب', 0, 'approved', false);
        $this->settings->save($this->settings->load()->withSettlementDelayDays(0));
        $this->rules->setRate(RateScope::General, 'general', CommissionRate::ofBasisPoints(1000));
    }

    /**
     * `information_schema`, not `SHOW TABLES LIKE`: the prefix can contain an
     * underscore and `LIKE` would read it as a wildcard.
     */
    private function dropEverything(\wpdb $wpdb): void
    {
        $stmt = $wpdb->pdo()->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?'
        );
        $stmt->execute([$wpdb->prefix . 'tmc\_%']);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $wpdb->dropTable((string) $table);
        }
    }

    /** One sold line, with the accrual a real capture writes beside it. */
    public function sold(int $orderItemId, int $baseMinor, int $shareMinor): int
    {
        $base = Money::of($baseMinor);
        $this->ledger->record(
            (new LedgerTransaction('order:' . $orderItemId, VENDOR, 'order:' . $orderItemId, (string) $orderItemId))
                ->add(LedgerAccount::CentralPayment, $base, 'item_paid')
                ->add(LedgerAccount::Commission, Money::of($baseMinor - $shareMinor)->negate(), 'commission_due')
                ->add(LedgerAccount::VendorEarning, Money::of($shareMinor)->negate(), 'vendor_earned')
                ->add(LedgerAccount::TaxCollected, $base->zero(), 'tax_collected')
        );
        $id = $this->orderItems->record(new VendorOrderItem(
            0,
            7000 + $orderItemId,
            $orderItemId,
            0,
            VENDOR,
            'کالای probe',
            'SKU-' . $orderItemId,
            1,
            $baseMinor,
            $baseMinor,
            0,
            $baseMinor - $shareMinor,
            $shareMinor,
            1000,
            'general',
            'order:' . $orderItemId,
            OrderItemStatus::Delivered
        ));
        $this->orderItems->recordSettlementCompletion($id, $this->clock->now()->format('Y-m-d H:i:s'), MANAGER);
        return $id;
    }

    /**
     * One line sold through the REAL capture path, which is what the refund
     * probe needs: the amounts and the ledger event both come from the code
     * under test rather than from this file.
     *
     * @return int the marketplace's own order-item id
     */
    public function captured(int $seq, int $totalMinor, int $taxMinor): int
    {
        $this->publish(9400 + $seq, 'P-' . $seq);
        $report = $this->captureService($this->db)->capture(5200 + $seq, [[
            'order_item_id' => 8400 + $seq,
            'wc_product_id' => 9400 + $seq,
            'variation_id' => null,
            'title' => 'کالای probe',
            'sku' => 'P-' . $seq,
            'quantity' => 1,
            'line_total_minor' => $totalMinor,
            'line_tax_minor' => $taxMinor,
        ]]);
        if (($report['captured'] ?? 0) !== 1) {
            throw new \RuntimeException('probe fixture: the line was not captured');
        }
        return (int) ($this->orderItems->findByOrderItem(8400 + $seq)?->id ?? 0);
    }

    public function requestService(\Tecteb\Marketplace\Contracts\DatabaseInterface $db): RequestWithdrawal
    {
        return new RequestWithdrawal(
            new DbWithdrawalRepository($db, $this->clock),
            $this->balance,
            new SettlementGate(new FakeTrialUnlock(true)),
            $this->access,
            $this->stores,
            new WithdrawalStateMachine(),
            $this->audit
        );
    }

    /**
     * The review service, built the way BOTH builds accept.
     *
     * `alpha.39` added an optional sixth argument; five arguments is a legal
     * call on either tree, and the payment probe adds the sixth only when the
     * constructor has it.
     */
    public function reviewService(\Tecteb\Marketplace\Contracts\DatabaseInterface $db, bool $withUnitOfWork = false): ReviewWithdrawals
    {
        $args = [
            new DbWithdrawalRepository($db, $this->clock),
            new DbLedgerRepository($db, $this->clock),
            new WithdrawalStateMachine(),
            $this->audit,
            new FakeCapabilityChecker(MANAGER, [Capabilities::REVIEW_WITHDRAWALS]),
        ];
        $accepts = (new \ReflectionClass(ReviewWithdrawals::class))->getConstructor()?->getNumberOfParameters() ?? 5;
        if ($withUnitOfWork && $accepts >= 6) {
            $args[] = $db;
        }
        return new ReviewWithdrawals(...$args);
    }

    public function captureService(\Tecteb\Marketplace\Contracts\DatabaseInterface $itemsDb): CaptureOrder
    {
        $rates = new ResolveCommissionRate($this->rules);
        return new CaptureOrder(
            new DbOrderItemRepository($itemsDb, $this->clock),
            $this->products,
            new RecordCommission($this->ledger, $rates, new CommissionCalculator(), $this->audit),
            new SyncCatalog(
                $this->products,
                new DbVariationRepository($this->db, $this->clock),
                new FakeCatalogProjector(),
                $this->audit
            ),
            new OrderOperationsGate($rates, $this->ledger, new FakeTrialUnlock(true)),
            $this->audit,
            // The vendor money unit, recorded against a primary key: a capture
            // without it refuses every line as `unit_unreadable` rather than
            // assuming, which is the posture `alpha.40` chose.
            new DbVendorMoneyUnitRegistry($this->db, $this->clock),
            // The unit of work the claim and the accrual share.
            $this->db
        );
    }

    public function returnService(ProbeRefundSpy $spy): ManageReturns
    {
        return new ManageReturns(
            $this->orderItems,
            new DbShipmentRepository($this->db, $this->clock),
            $this->ledger,
            $this->access,
            $this->audit,
            $this->clock,
            new ReturnStateMachine(),
            new ReturnTerms(),
            new RefundScope(),
            $spy,
            $this->products,
            new FakeCatalogProjector(),
            new FakeCapabilityChecker(MANAGER, [Capabilities::REVIEW_VENDOR, Capabilities::REVIEW_WITHDRAWALS])
        );
    }

    public function publish(int $wcProductId, string $sku): int
    {
        return $this->products->create(
            VENDOR,
            new ProductDetails(title: 'کالای probe', categoryKey: 'gloves', priceMinor: 200000, sku: $sku, stock: 20),
            ProductStatus::Published,
            LinkOwnership::Marketplace,
            $wcProductId
        );
    }

    /** @return array<string,mixed> */
    public function itemRow(int $id): array
    {
        $wpdb = $GLOBALS['wpdb'];
        $stmt = $wpdb->pdo()->prepare(
            'SELECT vendor_share_minor, commission_minor, rate_bp, ledger_event FROM `'
            . $wpdb->prefix . 'tmc_order_items` WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }

    public function claimedLines(): int
    {
        $wpdb = $GLOBALS['wpdb'];
        return (int) $wpdb->pdo()
            ->query('SELECT COUNT(*) FROM `' . $wpdb->prefix . 'tmc_order_items` WHERE withdrawal_id IS NOT NULL')
            ->fetchColumn();
    }
}

function say(string $key, mixed $value): void
{
    echo $key, '=', is_bool($value) ? ($value ? 'yes' : 'no') : (string) ($value ?? 'null'), "\n";
}

$verb = (string) ($argv[1] ?? '');

try {
    $world = new ProbeWorld();

    switch ($verb) {
        // §2 — the refund amount. `alpha.38` divided the recorded minor units
        // by 100 unconditionally; the order's unit has no minor part, so every
        // recorded refund was a hundredth of itself.
        case 'refund-amount':
            // 100,000 base plus 10,000 tax, captured at exponent 0 — so the
            // amount handed to the refund recorder must be 110000, and
            // `alpha.38` divided it by a hundred.
            $itemId = $world->captured(401, 100000, 10000);
            $spy = new ProbeRefundSpy();
            $returns = $world->returnService($spy);
            $opened = $returns->open(VENDOR, VENDOR, $itemId, 1, 'آزمون');
            say('open_ok', $opened->ok ? 'yes' : $opened->code);
            $returnId = (int) ($opened->context['return_id'] ?? 0);
            $returns->decide(MANAGER, $returnId, ReturnStatus::Approved);
            $returns->decide(MANAGER, $returnId, ReturnStatus::Received);
            $refunded = $returns->refund(MANAGER, $returnId);
            say('refund_ok', $refunded->ok ? 'yes' : $refunded->code);
            $wc = $returns->recordWooCommerceRefund(MANAGER, $returnId);
            say('wc_ok', $wc->ok ? 'yes' : $wc->code);
            say('recorded_amount', $spy->amounts === [] ? 'none' : (string) $spy->amounts[0]);
            break;

        // §3 — a claim that fails. `alpha.38` threw the UPDATE's answer away,
        // so the request existed and held nothing.
        case 'reserve-claim-failure':
            $itemId = $world->sold(302, 1000000, 900000);
            $failing = new FailingDatabase($world->db);
            $failing->failWhen(['UPDATE', 'tmc_order_items', 'SET withdrawal_id = %d']);
            $result = $world->requestService($failing)->handle(VENDOR, VENDOR);
            say('request_ok', $result->ok ? 'yes' : $result->code);
            say('request_exists', $world->withdrawals->openFor(VENDOR) === null ? 'no' : 'yes');
            say('claimed_lines', $world->claimedLines());
            say('eligible_after', $world->balance->of(VENDOR)['eligible']);
            unset($itemId);
            break;

        // §3 — a release that fails. `alpha.38` dropped the UPDATE's answer
        // and reported a successful rejection over lines it had not freed.
        case 'release-failure':
            $itemId = $world->sold(303, 1000000, 900000);
            $withdrawalId = (int) ($world->requestService($world->db)->handle(VENDOR, VENDOR)->context['withdrawal_id'] ?? 0);
            $failing = new FailingDatabase($world->db);
            $failing->failWhen(['UPDATE', 'tmc_order_items', 'SET withdrawal_id = NULL']);
            $review = $world->reviewService($failing);
            $review->startReview($withdrawalId);
            $rejected = $review->reject($withdrawalId, 'مدارک ناقص');
            say('reject_ok', $rejected->ok ? 'yes' : $rejected->code);
            // Two different tables, and on `alpha.38` they disagree: the
            // `DELETE` on the reserve lines succeeds while the `UPDATE` that
            // unclaims the order items fails, so the record of WHICH request
            // holds the money is gone and the money is still held.
            say('reserve_lines', count($world->withdrawals->lineIds($withdrawalId)));
            say('claimed_items', $world->claimedLines());
            say('eligible_after', $world->balance->of(VENDOR)['eligible']);
            unset($itemId);
            break;

        // §4 — the owner's scenario. The vendor's cancel was validated against
        // «Approved»; the manager moved the request to «PaymentInProgress»
        // first; `alpha.38` wrote with `WHERE id` alone and overwrote it.
        case 'stale-cancel':
            $world->sold(304, 1000000, 900000);
            $withdrawalId = (int) ($world->requestService($world->db)->handle(VENDOR, VENDOR)->context['withdrawal_id'] ?? 0);
            $review = $world->reviewService($world->db);
            $review->startReview($withdrawalId);
            $review->approve($withdrawalId);

            // The manager's move has to land INSIDE the vendor's cancel, after
            // it has read «Approved» and validated against it. Calling
            // `startPayment()` beforehand would not reproduce anything: the
            // cancel re-reads, sees `PaymentInProgress`, and both builds
            // answer `invalid_transition` — a correct refusal about a
            // different question.
            //
            // So the interleaving is scheduled: the manager's write runs
            // immediately before the cancel's own `UPDATE`, on the same
            // connection, through a gateway both builds accept. This is a
            // SCHEDULED interleaving in one process and is not presented as a
            // multi-process race; the suite's two-connection version of this
            // lives in `WithdrawalIntegrityTest`.
            $interfering = new InterferingDatabase($world->db);
            $interfering->before(['UPDATE', 'tmc_withdrawals', 'SET status = %s'], 1, static function () use ($review, $withdrawalId): void {
                $review->startPayment($withdrawalId);
            });
            $cancelled = $world->requestService($interfering)->cancel(VENDOR, VENDOR, $withdrawalId);
            say('interference_fired', $interfering->fired === [] ? 'no' : 'yes');
            say('cancel_ok', $cancelled->ok ? 'yes' : $cancelled->code);
            say('status_after', $world->withdrawals->find($withdrawalId)?->status->value);
            say('claimed_lines', $world->claimedLines());
            break;

        // §4 — the payment document and the status. `alpha.38` wrote the
        // ledger first and the status second, each alone.
        case 'payment-pair':
            $world->sold(305, 1000000, 900000);
            $withdrawalId = (int) ($world->requestService($world->db)->handle(VENDOR, VENDOR)->context['withdrawal_id'] ?? 0);
            $ready = $world->reviewService($world->db);
            $ready->startReview($withdrawalId);
            $ready->approve($withdrawalId);
            $ready->startPayment($withdrawalId);

            $failing = new FailingDatabase($world->db);
            $failing->failWhen(['UPDATE', 'tmc_withdrawals', 'SET status = %s']);
            $paid = $world->reviewService($failing, true)->recordPayment($withdrawalId, 'TRACE-PROBE');
            say('payment_ok', $paid->ok ? 'yes' : $paid->code);
            say('document_lines', count($world->ledger->forEvent('withdrawal:' . $withdrawalId . ':paid')));
            say('status_after', $world->withdrawals->find($withdrawalId)?->status->value);
            break;

        // §5 — the ledger takes the line and the item write fails. On
        // `alpha.38` the retry stored a line with null figures and then
        // skipped it for ever.
        case 'capture-retry':
            $productId = $world->publish(9301, 'P-301');
            $lines = [[
                'order_item_id' => 306,
                'wc_product_id' => 9301,
                'variation_id' => null,
                'title' => 'کالای probe',
                'sku' => 'P-301',
                'quantity' => 1,
                'line_total_minor' => 500000,
                'line_tax_minor' => 0,
            ]];
            $failing = new FailingDatabase($world->db);
            $failing->failWhen(['INSERT INTO', 'tmc_order_items']);
            $first = $world->captureService($failing)->capture(5101, $lines);
            say('first_captured', $first['captured']);
            say('first_failed', $first['failed'] ?? 'absent');

            $retry = $world->captureService($world->db)->capture(5101, $lines);
            say('retry_captured', $retry['captured']);
            say('retry_unrecorded', $retry['unrecorded']);
            $stored = $world->orderItems->forVendor(VENDOR);
            say('lines_stored', count($stored));
            say('share', $stored === [] ? 'none' : ($stored[0]->vendorShareMinor ?? 'null'));
            say('rate_bp', $stored === [] ? 'none' : ($stored[0]->rateBasisPoints ?? 'null'));
            say('event', $stored === [] ? 'none' : ($stored[0]->ledgerEvent === '' ? 'empty' : 'set'));
            unset($productId);
            break;

        // §5 — the row already on disk. The ledger holds the event, the line
        // does not, and a repeated callback is the only thing that looks.
        case 'half-written-line':
            $world->publish(9302, 'P-302');
            $lines = [[
                'order_item_id' => 307,
                'wc_product_id' => 9302,
                'variation_id' => null,
                'title' => 'کالای probe',
                'sku' => 'P-302',
                'quantity' => 1,
                'line_total_minor' => 500000,
                'line_tax_minor' => 0,
            ]];
            $world->captureService($world->db)->capture(5102, $lines);
            $itemId = (int) ($world->orderItems->forVendor(VENDOR)[0]->id ?? 0);
            $wpdb = $GLOBALS['wpdb'];
            $wpdb->pdo()->exec(
                'UPDATE `' . $wpdb->prefix . "tmc_order_items` SET commission_minor = NULL,
                 vendor_share_minor = NULL, rate_bp = NULL, rate_source = '', ledger_event = ''
                 WHERE id = {$itemId}"
            );
            $again = $world->captureService($world->db)->capture(5102, $lines);
            say('repaired', $again['repaired'] ?? 'absent');
            say('incomplete', $again['incomplete'] ?? 'absent');
            $row = $world->itemRow($itemId);
            say('share', $row['vendor_share_minor'] ?? 'null');
            say('rate_bp', $row['rate_bp'] ?? 'null');
            say('event', ((string) ($row['ledger_event'] ?? '')) === '' ? 'empty' : 'set');
            break;

        // ---------------------------------------------------------- controls
        //
        // Both builds must answer these identically. They are the only thing
        // that tells «the defect reproduced» apart from «the revert broke the
        // tree and nothing ran» — `alpha.32`'s trap, and the reason the shell
        // asserts them on BOTH trees.
        case 'control-zero-is-success':
            // `alpha.8`: a DELETE that matches nothing returns 0, not null.
            $rows = $world->db->execute(
                'DELETE FROM `' . $GLOBALS['wpdb']->prefix . 'tmc_order_items` WHERE id = %d',
                [999999]
            );
            say('zero_is_success', $rows === 0 ? 'yes' : var_export($rows, true));
            say('event_key', CaptureOrder::eventKey(1, 2));
            break;

        case 'control-settlement-rules':
            // The rules `SettlementFlowTest` is about, asked of either build:
            // a share is not withdrawable until a manager calls it complete,
            // and a second request while one is open is refused.
            $id = $world->orderItems->record(new VendorOrderItem(
                0, 7999, 399, 0, VENDOR, 'کالای probe', 'SKU-399', 1,
                1000000, 1000000, 0, 100000, 900000, 1000, 'general', 'order:399',
                OrderItemStatus::Delivered
            ));
            say('eligible_before_completion', $world->balance->of(VENDOR)['eligible']);
            $world->orderItems->recordSettlementCompletion($id, $world->clock->now()->format('Y-m-d H:i:s'), MANAGER);
            say('eligible_after_completion', $world->balance->of(VENDOR)['eligible']);
            $service = $world->requestService($world->db);
            say('first_request', $service->handle(VENDOR, VENDOR)->ok ? 'ok' : 'refused');
            say('second_request', $service->handle(VENDOR, VENDOR)->code);
            break;

        case 'control-unpaid-item-share':
            // FIN-02's floor, on either build: a line with no recorded share
            // is not counted as zero.
            $world->orderItems->record(new VendorOrderItem(
                0, 7998, 398, 0, VENDOR, 'کالای probe', 'SKU-398', 1,
                1000000, 1000000, 0, null, null, null, '', '',
                OrderItemStatus::Delivered
            ));
            $balance = $world->balance->of(VENDOR);
            say('earned', $balance['earned']);
            say('unrecorded', $balance['unrecorded']);
            break;

        case 'control-manager-gate':
            // A read that needs a capability refuses without one, on either
            // build. (`incompleteCaptures()` is `alpha.39` only, so the
            // control uses a method both trees have.)
            $orders = new ManageOrderItems(
                $world->orderItems,
                $world->access,
                $world->audit,
                $world->clock,
                new OrderItemStateMachine(),
                new FakeCapabilityChecker(0, [])
            );
            say('settlement_without_capability', $orders->recordSettlementCompletion(1, true)->code);
            break;

        default:
            fwrite(STDERR, "alpha38-probe: unknown verb '{$verb}'\n");
            exit(1);
    }

    say('probe', 'ran');
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'alpha38-probe: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}
