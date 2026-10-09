<?php
declare(strict_types=1);

/*
 * The named defects of THIS round, asked of whatever build is on disk.
 *
 * ### Why a probe and not this round's tests
 *
 * `alpha.32` learned it and `alpha.37` restated it: a test that names a class
 * the old tree does not have is a FATAL at load, **zero tests execute**, and
 * every «the defect reproduced» line is a report about nothing having run. So
 * this file asks only questions BOTH builds can answer — `capture()`,
 * `refund()`, `cancel()`, `reject()`, `handle()`, `completeFinancials()` — and
 * prints `key=value` lines the shell compares. It never names a symbol
 * `alpha.40` introduced (`MoneyUnitClaim`, `DbVendorMoneyUnitRegistry`,
 * `M0022VendorMoneyUnit`, `strandedReservations()`), and where a constructor
 * grew arguments it appends them by REFLECTION so the earlier arity stays a
 * legal call.
 *
 * Two things this file deliberately does NOT do:
 *
 *  - it never presents a stub as WooCommerce. `ProbeRefundSpy` writes nothing
 *    and only remembers the argument it was handed, which is the one thing
 *    §1 asks to be measured (the amount and the tax as passed).
 *  - it never races two writers in one process. Where a condition changes
 *    between a read and a write, the interleaving is SCHEDULED — named as
 *    such, on the same connection — and the real two-connection race is in
 *    `WithdrawalIntegrityTest` and `MoneyUnitContractTest` via
 *    `tests/Support/concurrent-*.php`.
 *
 * Usage:
 *   php tests/Support/alpha39-probe.php <verb>
 *
 * Verbs are listed in `tools/alpha39-reproduction.sh`, the only intended
 * caller. Every verb prints at least one `key=value` line and `probe=ran`; a
 * verb that prints no `probe=ran` did not execute, and the shell treats that as
 * a broken measurement rather than as a result.
 */

require dirname(__DIR__) . '/bootstrap-contract.php';

use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Config\SettingsService;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
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
        fwrite(STDERR, "alpha39-probe: {$var} is not set\n");
        exit(1);
    }
}
if (!str_contains((string) getenv('TMC_TEST_DB_DSN'), 'tmc_test')) {
    fwrite(STDERR, "alpha39-probe: refuses any DSN that is not the disposable tmc_test\n");
    exit(1);
}

const VENDOR = 93;
const MANAGER = 9;

/**
 * A refund recorder that writes nothing and remembers its arguments.
 *
 * `$amount` is `float|string` because the interface declares `string` on both
 * trees this script visits but a union is wider than either and costs nothing.
 * **This is not WooCommerce and is never reported as WooCommerce** — §1 asks
 * for the amount and the tax AS PASSED, and that is all this records.
 */
final class Alpha39RefundSpy implements RefundRecorderInterface
{
    /** @var list<array{amount:float|string, quantity:int, reason:string}> */
    public array $calls = [];

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
        $this->calls[] = ['amount' => $amount, 'quantity' => $quantity, 'reason' => $reason];
        return [
            'ok' => true,
            'reason' => 'refund_recorded',
            'refund_id' => 7101,
            'money_moved' => false,
            'remaining' => '999999999',
        ];
    }
}

/** Everything the probes share, built once per verb. */
final class Alpha39World
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

        // The real chain, from `Bootstrap::migrations()` — never a list this
        // file keeps, which is how the suite's lists went stale. On the
        // `alpha.39` tree that chain stops at 21 and the money-unit table does
        // not exist, which is exactly the state being measured.
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

    /** `information_schema`, not `SHOW TABLES LIKE`: the prefix can hold `_`. */
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

    /**
     * One line sold through the REAL capture path, in a stated unit.
     *
     * The unit travels as an argument on both trees, so a sale in `USD`/2 is
     * something `alpha.39` can record too — it simply then reverses it in
     * `IRR`/0, which is the defect.
     *
     * @return int the marketplace's own order-item id
     */
    public function captured(int $seq, int $totalMinor, int $taxMinor, string $currency = 'IRT', int $exponent = 0): int
    {
        $this->publish(9500 + $seq, 'Q-' . $seq);
        $report = $this->captureService($this->db)->capture(5300 + $seq, [[
            'order_item_id' => 8500 + $seq,
            'wc_product_id' => 9500 + $seq,
            'variation_id' => null,
            'title' => 'کالای probe',
            'sku' => 'Q-' . $seq,
            'quantity' => 1,
            'line_total_minor' => $totalMinor,
            'line_tax_minor' => $taxMinor,
        ]], $currency, $exponent);
        if (($report['captured'] ?? 0) !== 1) {
            throw new \RuntimeException(
                'probe fixture: the line was not captured (' . implode(',', $report['reasons'] ?? []) . ')'
            );
        }
        return (int) ($this->orderItems->findByOrderItem(8500 + $seq)?->id ?? 0);
    }

    /** One sold line with the accrual a real capture writes beside it. */
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
            7100 + $orderItemId,
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

    /** The request service, with the unit of work when the build has one. */
    public function requestService(\Tecteb\Marketplace\Contracts\DatabaseInterface $db): RequestWithdrawal
    {
        $args = [
            new DbWithdrawalRepository($db, $this->clock),
            $this->balance,
            new SettlementGate(new FakeTrialUnlock(true)),
            $this->access,
            $this->stores,
            new WithdrawalStateMachine(),
            $this->audit,
        ];
        $accepts = (new \ReflectionClass(RequestWithdrawal::class))->getConstructor()?->getNumberOfParameters() ?? 7;
        if ($accepts >= 8) {
            $args[] = $db;
        }
        return new RequestWithdrawal(...$args);
    }

    /** The review service, with everything the build accepts. */
    public function reviewService(\Tecteb\Marketplace\Contracts\DatabaseInterface $db): ReviewWithdrawals
    {
        $args = [
            new DbWithdrawalRepository($db, $this->clock),
            new DbLedgerRepository($db, $this->clock),
            new WithdrawalStateMachine(),
            $this->audit,
            new FakeCapabilityChecker(MANAGER, [Capabilities::REVIEW_WITHDRAWALS]),
        ];
        $accepts = (new \ReflectionClass(ReviewWithdrawals::class))->getConstructor()?->getNumberOfParameters() ?? 5;
        if ($accepts >= 6) {
            $args[] = $db;
        }
        return new ReviewWithdrawals(...$args);
    }

    /**
     * The capture, with the registry and the unit of work when they exist.
     *
     * `$unitsDb` is separate on purpose. The unit is read in a different place
     * on each tree — `alpha.39` asked the LEDGER through `RecordCommission`,
     * `alpha.40` asks the money-unit registry — so a verb that wants to break
     * that read has to hand the same broken gateway to both, while the order
     * table keeps working. One argument, both trees, no branching in the verb.
     */
    public function captureService(
        \Tecteb\Marketplace\Contracts\DatabaseInterface $itemsDb,
        ?\Tecteb\Marketplace\Contracts\DatabaseInterface $unitsDb = null
    ): CaptureOrder {
        $unitsDb ??= $this->db;
        $rates = new ResolveCommissionRate($this->rules);
        $ledger = $unitsDb === $this->db ? $this->ledger : new DbLedgerRepository($unitsDb, $this->clock);
        $args = [
            new DbOrderItemRepository($itemsDb, $this->clock),
            $this->products,
            new RecordCommission($ledger, $rates, new CommissionCalculator(), $this->audit),
            new SyncCatalog(
                $this->products,
                new DbVariationRepository($this->db, $this->clock),
                new FakeCatalogProjector(),
                $this->audit
            ),
            new OrderOperationsGate($rates, $this->ledger, new FakeTrialUnlock(true)),
            $this->audit,
        ];
        $accepts = (new \ReflectionClass(CaptureOrder::class))->getConstructor()?->getNumberOfParameters() ?? 6;
        if ($accepts >= 8 && class_exists(DbVendorMoneyUnitRegistry::class)) {
            $args[] = new DbVendorMoneyUnitRegistry($unitsDb, $this->clock);
            $args[] = $this->db;
        }
        return new CaptureOrder(...$args);
    }

    public function returnService(Alpha39RefundSpy $spy): ManageReturns
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

    /** @return array<string,mixed> */
    public function itemRow(int $id): array
    {
        $wpdb = $GLOBALS['wpdb'];
        $stmt = $wpdb->pdo()->prepare(
            'SELECT quantity, base_minor, tax_minor, vendor_share_minor, commission_minor, rate_bp, ledger_event
             FROM `' . $wpdb->prefix . 'tmc_order_items` WHERE id = ?'
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

    /** The units the ledger holds for one event, as `CUR/EXP` strings. */
    public function unitsOfEvent(string $eventKey): string
    {
        $seen = [];
        foreach ($this->ledger->forEvent($eventKey) as $entry) {
            $seen[$entry->amount->currency . '/' . $entry->amount->exponent] = true;
        }
        $keys = array_keys($seen);
        sort($keys);
        return $keys === [] ? 'none' : implode(',', $keys);
    }

    /** The reversal event key `ManageReturns` writes: `return:<item>:<return>`. */
    public function reversalUnits(int $itemId, int $returnId): string
    {
        return $this->unitsOfEvent('return:' . $itemId . ':' . $returnId);
    }

    /** The accrual event key a capture writes: `order:<order>:item:<item>`. */
    public function saleEvent(int $seq): string
    {
        return CaptureOrder::eventKey(5300 + $seq, 8500 + $seq);
    }
}

function say(string $key, mixed $value): void
{
    echo $key, '=', is_bool($value) ? ($value ? 'yes' : 'no') : (string) ($value ?? 'null'), "\n";
}

$verb = (string) ($argv[1] ?? '');

try {
    $world = new Alpha39World();

    switch ($verb) {
        // §1 — the unit of the ledger reversal. `alpha.39` defaulted `refund()`
        // to IRR/0 and `ReturnsPage` called it without a unit, so a sale
        // recorded in anything else was undone in a different kind of money.
        case 'reversal-unit':
            $itemId = $world->captured(501, 10550, 0, 'USD', 2);
            say('sale_units', $world->unitsOfEvent($world->saleEvent(501)));
            $spy = new Alpha39RefundSpy();
            $returns = $world->returnService($spy);
            $opened = $returns->open(VENDOR, VENDOR, $itemId, 1, 'آزمون');
            say('open_ok', $opened->ok ? 'yes' : $opened->code);
            $returnId = (int) ($opened->context['return_id'] ?? 0);
            $returns->decide(MANAGER, $returnId, ReturnStatus::Approved);
            $returns->decide(MANAGER, $returnId, ReturnStatus::Received);
            $refunded = $returns->refund(MANAGER, $returnId);
            say('refund_ok', $refunded->ok ? 'yes' : $refunded->code);
            say('reversal_units', $world->reversalUnits($itemId, $returnId));
            break;

        // §1 — and the refusal. With the accrual's rows gone there is no unit
        // to read, and «the current shop setting» is not allowed to supply one.
        case 'reversal-unit-unknown':
            $itemId = $world->captured(502, 100000, 10000, 'IRT', 0);
            $spy = new Alpha39RefundSpy();
            $returns = $world->returnService($spy);
            $opened = $returns->open(VENDOR, VENDOR, $itemId, 1, 'آزمون');
            $returnId = (int) ($opened->context['return_id'] ?? 0);
            $returns->decide(MANAGER, $returnId, ReturnStatus::Approved);
            $returns->decide(MANAGER, $returnId, ReturnStatus::Received);
            // The line keeps its event reference; the rows it names are gone.
            // This is the shape a site can actually be in — a ledger pruned,
            // restored from an older dump, or written by a build that failed
            // halfway — and it is the one case where no unit can be read.
            $wpdb = $GLOBALS['wpdb'];
            $wpdb->pdo()->exec(
                'DELETE FROM `' . $wpdb->prefix . "tmc_ledger_entries` WHERE event_key = '"
                . $world->saleEvent(502) . "'"
            );
            $refunded = $returns->refund(MANAGER, $returnId);
            say('refund_ok', $refunded->ok ? 'yes' : $refunded->code);
            say('reversal_lines', count($world->ledger->forEvent('return:' . $itemId . ':' . $returnId)));
            break;

        // §1 — the arguments handed to WooCommerce: the amount AND the tax, in
        // the unit the sale was recorded in, as a string that carries its own
        // scale. `'105.50'` is both the amount and the scale; `105.5` is one.
        case 'refund-arguments':
            $itemId = $world->captured(503, 10550, 0, 'USD', 2);
            $spy = new Alpha39RefundSpy();
            $returns = $world->returnService($spy);
            $opened = $returns->open(VENDOR, VENDOR, $itemId, 1, 'آزمون');
            $returnId = (int) ($opened->context['return_id'] ?? 0);
            $returns->decide(MANAGER, $returnId, ReturnStatus::Approved);
            $returns->decide(MANAGER, $returnId, ReturnStatus::Received);
            $returns->refund(MANAGER, $returnId);
            $wc = $returns->recordWooCommerceRefund(MANAGER, $returnId);
            say('wc_ok', $wc->ok ? 'yes' : $wc->code);
            say('passed_amount', $spy->calls === [] ? 'none' : (string) $spy->calls[0]['amount']);
            break;

        // §2 — a cancel whose release fails. `alpha.39` closed the status and
        // then released, so a failed release left a CLOSED request holding
        // money. The release is injected to fail; the question is what is on
        // disk afterwards.
        case 'cancel-atomic':
            $world->sold(601, 1000000, 900000);
            $withdrawalId = (int) ($world->requestService($world->db)->handle(VENDOR, VENDOR)->context['withdrawal_id'] ?? 0);
            $failing = new FailingDatabase($world->db);
            $failing->failWhen(['UPDATE', 'tmc_order_items', 'SET withdrawal_id = NULL']);
            $cancelled = $world->requestService($failing)->cancel(VENDOR, VENDOR, $withdrawalId);
            say('cancel_ok', $cancelled->ok ? 'yes' : $cancelled->code);
            say('status_after', $world->withdrawals->find($withdrawalId)?->status->value);
            say('reserve_lines', count($world->withdrawals->lineIds($withdrawalId)));
            say('claimed_items', $world->claimedLines());
            say('eligible_after', $world->balance->of(VENDOR)['eligible']);
            break;

        // §2 — the same for a manager's rejection, which is the other half of
        // the pair and had the same shape.
        case 'reject-atomic':
            $world->sold(602, 1000000, 900000);
            $withdrawalId = (int) ($world->requestService($world->db)->handle(VENDOR, VENDOR)->context['withdrawal_id'] ?? 0);
            $failing = new FailingDatabase($world->db);
            $failing->failWhen(['UPDATE', 'tmc_order_items', 'SET withdrawal_id = NULL']);
            $review = $world->reviewService($failing);
            $review->startReview($withdrawalId);
            $rejected = $review->reject($withdrawalId, 'مدارک ناقص');
            say('reject_ok', $rejected->ok ? 'yes' : $rejected->code);
            say('status_after', $world->withdrawals->find($withdrawalId)?->status->value);
            say('reserve_lines', count($world->withdrawals->lineIds($withdrawalId)));
            say('claimed_items', $world->claimedLines());
            break;

        // §3 — a condition that changes between reading the balance and
        // finalising the reservation. The item is CANCELLED in between, which
        // `VendorBalance` excludes and `alpha.39`'s claim did not ask about.
        //
        // SCHEDULED, not raced: the change is written immediately before the
        // claim's own `UPDATE`, on the same connection, and is named as such.
        case 'claim-cancelled-item':
            $itemId = $world->sold(603, 1000000, 900000);
            $wpdb = $GLOBALS['wpdb'];
            $interfering = new InterferingDatabase($world->db);
            $interfering->before(['UPDATE', 'tmc_order_items', 'SET withdrawal_id = %d'], 1, static function () use ($wpdb, $itemId): void {
                $wpdb->pdo()->exec(
                    'UPDATE `' . $wpdb->prefix . "tmc_order_items` SET status = 'cancelled' WHERE id = {$itemId}"
                );
            });
            $result = $world->requestService($interfering)->handle(VENDOR, VENDOR);
            say('interference_fired', $interfering->fired === [] ? 'no' : 'yes');
            say('request_ok', $result->ok ? 'yes' : $result->code);
            say('claimed_items', $world->claimedLines());
            say('open_requests', $world->withdrawals->openFor(VENDOR) === null ? 0 : 1);
            break;

        // §3 — and a blocking return, the other condition the balance applies
        // and the claim did not.
        case 'claim-returned-item':
            $itemId = $world->sold(604, 1000000, 900000);
            $spy = new Alpha39RefundSpy();
            $returns = $world->returnService($spy);
            $interfering = new InterferingDatabase($world->db);
            $interfering->before(['UPDATE', 'tmc_order_items', 'SET withdrawal_id = %d'], 1, static function () use ($returns, $itemId): void {
                // Through the real service, so the status is one the rule
                // actually reserves quantity for — not a hand-written row.
                $returns->open(VENDOR, VENDOR, $itemId, 1, 'آزمون');
            });
            $result = $world->requestService($interfering)->handle(VENDOR, VENDOR);
            say('interference_fired', $interfering->fired === [] ? 'no' : 'yes');
            say('request_ok', $result->ok ? 'yes' : $result->code);
            say('claimed_items', $world->claimedLines());
            say('open_requests', $world->withdrawals->openFor(VENDOR) === null ? 0 : 1);
            break;

        // §4 — books that already hold two units. `alpha.39` asked whether ANY
        // recorded unit matched, so a mixed ledger accepted a line in either
        // of them and went on being mixed — the state no balance can be
        // computed over.
        case 'mixed-books':
            $world->captured(605, 100000, 0, 'IRT', 0);
            // A second unit, written straight to the ledger: this is the
            // legacy state §4 asks to be DETECTED, not one a capture should be
            // able to create.
            $world->ledger->record(
                (new LedgerTransaction('legacy:605', VENDOR, 'order:legacy', '605'))
                    ->add(LedgerAccount::CentralPayment, Money::of(5000, 'USD', 2), 'item_paid')
                    ->add(LedgerAccount::VendorEarning, Money::of(5000, 'USD', 2)->negate(), 'vendor_earned')
            );
            $world->publish(9606, 'Q-606');
            $report = $world->captureService($world->db)->capture(5406, [[
                'order_item_id' => 8606,
                'wc_product_id' => 9606,
                'variation_id' => null,
                'title' => 'کالای probe',
                'sku' => 'Q-606',
                'quantity' => 1,
                'line_total_minor' => 200000,
                'line_tax_minor' => 0,
            ]], 'IRT', 0);
            say('captured', $report['captured'] ?? 'absent');
            say('refused_unit', $report['refused_unit'] ?? 'absent');
            say('reasons', implode(',', $report['reasons'] ?? []));
            say('line_stored', $world->orderItems->findByOrderItem(8606) === null ? 'no' : 'yes');
            break;

        // §4 — a read that FAILS is not an empty ledger. `alpha.39` asked with
        // `getResults()`, which answers `[]` for both, so a broken read looked
        // like a first sale and silently fixed the unit of a whole ledger.
        case 'unit-read-failure':
            $world->publish(9607, 'Q-607');
            $failing = new FailingDatabase($world->db);
            // Whatever either build asks the ledger about units, this is the
            // read: a SELECT over the ledger entries naming currency.
            $failing->failReadWhen(['tmc_ledger_entries', 'currency']);
            // The order table stays on the working gateway; only the unit read
            // is broken, which is the whole question.
            $report = $world->captureService($world->db, $failing)->capture(5407, [[
                'order_item_id' => 8607,
                'wc_product_id' => 9607,
                'variation_id' => null,
                'title' => 'کالای probe',
                'sku' => 'Q-607',
                'quantity' => 1,
                'line_total_minor' => 200000,
                'line_tax_minor' => 0,
            ]], 'IRT', 0);
            say('captured', $report['captured'] ?? 'absent');
            say('refused_unit', $report['refused_unit'] ?? 'absent');
            say('reasons', implode(',', $report['reasons'] ?? []));
            break;

        // §5 — an order edited between the failed line write and the retry.
        // `alpha.39` recovered the share and the rate from the event and wrote
        // `base_minor`, `tax_minor` and `quantity` from the FRESH input, so the
        // line and the ledger disagreed and the pass reported success.
        case 'changed-line-retry':
            $world->publish(9608, 'Q-608');
            $line = static fn (int $total, int $tax, int $qty): array => [[
                'order_item_id' => 8608,
                'wc_product_id' => 9608,
                'variation_id' => null,
                'title' => 'کالای probe',
                'sku' => 'Q-608',
                'quantity' => $qty,
                'line_total_minor' => $total,
                'line_tax_minor' => $tax,
            ]];
            $failing = new FailingDatabase($world->db);
            $failing->failWhen(['INSERT INTO', 'tmc_order_items']);
            $first = $world->captureService($failing)->capture(5408, $line(1000000, 0, 1));
            say('first_captured', $first['captured'] ?? 'absent');
            say('first_failed', $first['failed'] ?? 'absent');
            // The order is edited: the same line, a different amount.
            $retry = $world->captureService($world->db)->capture(5408, $line(2000000, 0, 1));
            say('retry_captured', $retry['captured'] ?? 'absent');
            say('retry_incomplete', $retry['incomplete'] ?? 'absent');
            say('reasons', implode(',', $retry['reasons'] ?? []));
            $stored = $world->orderItems->findByOrderItem(8608);
            say('line_stored', $stored === null ? 'no' : 'yes');
            if ($stored !== null) {
                $row = $world->itemRow($stored->id);
                // The figure that matters: the stored base beside the share,
                // which on `alpha.39` came from two different moments.
                say('stored_base', $row['base_minor'] ?? 'null');
                say('stored_share', $row['vendor_share_minor'] ?? 'null');
            }
            break;

        // §5 — a row that HOLDS its figures and lacks only the event link.
        // `alpha.39` guarded the repair on «share IS NULL OR event is empty»,
        // so this row matched on its second half and had its FIGURES rewritten.
        case 'figures-not-overwritten':
            $itemId = $world->captured(609, 1000000, 0, 'IRT', 0);
            $wpdb = $GLOBALS['wpdb'];
            // A share the event does NOT say, and no link. One of the two is
            // wrong and only a person can say which.
            $wpdb->pdo()->exec(
                'UPDATE `' . $wpdb->prefix . "tmc_order_items`
                 SET ledger_event = '', vendor_share_minor = 440000 WHERE id = {$itemId}"
            );
            $repaired = $world->orderItems->completeFinancials(
                $itemId,
                100000,
                900000,
                1000,
                'general',
                $world->saleEvent(609)
            );
            say('repair_ok', $repaired ? 'yes' : 'no');
            $row = $world->itemRow($itemId);
            say('share_after', $row['vendor_share_minor'] ?? 'null');
            say('event_after', ((string) ($row['ledger_event'] ?? '')) === '' ? 'empty' : 'set');
            break;

        // §5 — and a line may never be repointed at another document.
        case 'no-repointing':
            $itemId = $world->captured(610, 1000000, 0, 'IRT', 0);
            $wpdb = $GLOBALS['wpdb'];
            $wpdb->pdo()->exec(
                'UPDATE `' . $wpdb->prefix . "tmc_order_items`
                 SET vendor_share_minor = NULL, commission_minor = NULL WHERE id = {$itemId}"
            );
            $repaired = $world->orderItems->completeFinancials(
                $itemId,
                100000,
                900000,
                1000,
                'general',
                CaptureOrder::eventKey(999999, 1)
            );
            say('repair_ok', $repaired ? 'yes' : 'no');
            $row = $world->itemRow($itemId);
            say('event_after', (string) ($row['ledger_event'] ?? ''));
            say('share_after', $row['vendor_share_minor'] ?? 'null');
            break;

        // ---------------------------------------------------------- controls
        //
        // Both builds must answer these identically. They are the only thing
        // that tells «the defect reproduced» apart from «the revert broke the
        // tree and nothing ran» — `alpha.32`'s trap.
        case 'control-zero-is-success':
            $rows = $world->db->execute(
                'DELETE FROM `' . $GLOBALS['wpdb']->prefix . 'tmc_order_items` WHERE id = %d',
                [999999]
            );
            say('zero_is_success', $rows === 0 ? 'yes' : var_export($rows, true));
            say('event_key', CaptureOrder::eventKey(1, 2));
            break;

        case 'control-settlement-rules':
            $id = $world->orderItems->record(new VendorOrderItem(
                0, 7899, 699, 0, VENDOR, 'کالای probe', 'SKU-699', 1,
                1000000, 1000000, 0, 100000, 900000, 1000, 'general', 'order:699',
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
                0, 7898, 698, 0, VENDOR, 'کالای probe', 'SKU-698', 1,
                1000000, 1000000, 0, null, null, null, '', '',
                OrderItemStatus::Delivered
            ));
            $balance = $world->balance->of(VENDOR);
            say('earned', $balance['earned']);
            say('unrecorded', $balance['unrecorded']);
            break;

        case 'control-manager-gate':
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

        case 'control-complete-line-not-repairable':
            // The `alpha.39` rule that must still hold: a line that is already
            // complete refuses a repair, whatever figures are offered.
            $itemId = $world->captured(611, 1000000, 0, 'IRT', 0);
            say('repair_of_complete_line', $world->orderItems->completeFinancials(
                $itemId,
                999999,
                1,
                9999,
                'invented',
                'order:0'
            ) ? 'accepted' : 'refused');
            $row = $world->itemRow($itemId);
            say('share_after', $row['vendor_share_minor'] ?? 'null');
            break;

        default:
            fwrite(STDERR, "alpha39-probe: unknown verb '{$verb}'\n");
            exit(2);
    }

    say('probe', 'ran');
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'alpha39-probe: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}
