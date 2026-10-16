<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\ModuleInterface;
use Tecteb\Marketplace\Contracts\ModuleKind;
use Tecteb\Marketplace\Contracts\ModuleManifest;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Admin\Presentation\AdminExtensions;
use Tecteb\Marketplace\Modules\Finance\Application\CommissionRuleRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Application\LedgerRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Application\RecordCommission;
use Tecteb\Marketplace\Modules\Finance\Application\RecordSupplierPayment;
use Tecteb\Marketplace\Modules\Finance\Application\RequestWithdrawal;
use Tecteb\Marketplace\Modules\Finance\Application\SupplierPaymentRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Application\ResolveCommissionRate;
use Tecteb\Marketplace\Modules\Finance\Application\ReviewWithdrawals;
use Tecteb\Marketplace\Modules\Finance\Application\SettlementGate;
use Tecteb\Marketplace\Modules\Finance\Application\VendorBalance;
use Tecteb\Marketplace\Modules\Finance\Application\WithdrawalRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Application\TectebCommissionSourceInterface;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionCalculator;
use Tecteb\Marketplace\Modules\Finance\Domain\TectebCommissionCalculator;
use Tecteb\Marketplace\Modules\Finance\Domain\WithdrawalStateMachine;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\WooCommerce\TectebCommissionMetaGuard;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\WooCommerce\TectebCommissionPanel;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\WooCommerce\WpTectebCommissionSource;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbCommissionRuleRepository;
use Tecteb\Marketplace\Modules\Finance\Application\VendorMoneyUnitRegistryInterface;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbLedgerRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbSupplierPaymentRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbVendorMoneyUnitRegistry;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbWithdrawalRepository;
use Tecteb\Marketplace\Modules\Finance\Presentation\Admin\CommissionRulesPage;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\WordPress\FinanceArea;
use Tecteb\Marketplace\Modules\Finance\Presentation\Admin\SupplierPaymentsPage;
use Tecteb\Marketplace\Modules\Finance\Presentation\Admin\WithdrawalsPage;
use Tecteb\Marketplace\Modules\Order\Application\ShipmentRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Presentation\Admin\ReturnsPage;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaExtensions;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaOutcome;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaView;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;
use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Config\SettingsService;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Application\OrderTrialInterface;
use Tecteb\Marketplace\Modules\Order\Application\TrialUnlock;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Application\StoreRepositoryInterface;

/**
 * The financial contract: rates, the calculation, and the ledger.
 *
 * What this module deliberately does NOT contain is anything that decides
 * when money moves. Release timing waits on DEC-02 («تکمیل» in a
 * multi-vendor order) and allocation waits on DEC-04 (tax and marketplace
 * coupons), and both are the owner's decisions. The engine those decisions
 * will drive is here, configurable and tested; the trigger is not.
 */
final class FinanceModule implements ModuleInterface
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            'finance',
            $this->version(),
            'مالی',
            ModuleKind::Operational,
            ['core', 'vendor'],
            false,
            'قواعد کمیسیون، محاسبه سهم، دفترکل تغییرناپذیر و تسویه'
        );
    }

    public function register(ContainerInterface $c): void
    {
        $c->bind(CommissionRuleRepositoryInterface::class, static fn (ContainerInterface $c) => new DbCommissionRuleRepository(
            $c->get(DatabaseInterface::class),
            $c->get(ClockInterface::class)
        ));
        $c->bind(LedgerRepositoryInterface::class, static fn (ContainerInterface $c) => new DbLedgerRepository(
            $c->get(DatabaseInterface::class),
            $c->get(ClockInterface::class)
        ));
        $c->bind(CommissionCalculator::class, static fn () => new CommissionCalculator());
        $c->bind(TectebCommissionCalculator::class, static fn () => new TectebCommissionCalculator());
        $c->bind(TectebCommissionSourceInterface::class, static fn () => new WpTectebCommissionSource());
        $c->bind(ResolveCommissionRate::class, static fn (ContainerInterface $c) => new ResolveCommissionRate(
            $c->get(CommissionRuleRepositoryInterface::class)
        ));
        $c->bind(RecordCommission::class, static fn (ContainerInterface $c) => new RecordCommission(
            $c->get(LedgerRepositoryInterface::class),
            $c->get(ResolveCommissionRate::class),
            $c->get(CommissionCalculator::class),
            $c->get(AuditLogger::class),
            // THE OWNER'S MODEL, always wired in a real install. Which one
            // decides a new sale is a decision and not a configuration, so
            // these two are not conditional on anything —
            // `CommissionModelWiringTest` asserts that they are here.
            $c->get(TectebCommissionSourceInterface::class),
            $c->get(TectebCommissionCalculator::class)
        ));

        // Settlement. Bound unconditionally, like the order gate: the screens
        // have to be able to say WHY a withdrawal cannot be made, and a
        // service that is not bound cannot say anything.
        $c->bind(WithdrawalRepositoryInterface::class, static fn (ContainerInterface $c) => new DbWithdrawalRepository(
            $c->get(DatabaseInterface::class),
            $c->get(ClockInterface::class)
        ));
        $c->bind(WithdrawalStateMachine::class, static fn () => new WithdrawalStateMachine());
        $c->bind(SettlementGate::class, static fn (ContainerInterface $c) => new SettlementGate(
            $c->get(TrialUnlock::class)
        ));
        $c->bind(VendorMoneyUnitRegistryInterface::class, static fn (ContainerInterface $c) => new DbVendorMoneyUnitRegistry(
            $c->get(DatabaseInterface::class),
            $c->get(ClockInterface::class)
        ));
        $c->bind(VendorBalance::class, static fn (ContainerInterface $c) => new VendorBalance(
            $c->get(OrderItemRepositoryInterface::class),
            $c->get(SettingsService::class),
            $c->get(ClockInterface::class),
            // So an open return holds its own share back (UX §10.2).
            $c->get(ShipmentRepositoryInterface::class),
            // So a line Tecteb already paid for by hand is not offered to the
            // vendor a second time. ONE obligation, ONE settlement — and the
            // lock for it is in `reserve()`'s own statement, not here.
            $c->get(SupplierPaymentRepositoryInterface::class)
        ));
        $c->bind(RequestWithdrawal::class, static fn (ContainerInterface $c) => new RequestWithdrawal(
            $c->get(WithdrawalRepositoryInterface::class),
            $c->get(VendorBalance::class),
            $c->get(SettlementGate::class),
            $c->get(StaffAccess::class),
            $c->get(StoreRepositoryInterface::class),
            $c->get(WithdrawalStateMachine::class),
            $c->get(AuditLogger::class),
            // The unit of work a cancel needs: the status change and the
            // release of the reserved lines are one operation or neither.
            $c->get(DatabaseInterface::class)
        ));
        // Supplier payments: a RECORD of money already paid by hand, kept
        // apart from the vendor withdrawal cycle on purpose (§4). Bound
        // unconditionally, like the gates, so a screen can always say why
        // something cannot be recorded.
        $c->bind(SupplierPaymentRepositoryInterface::class, static fn (ContainerInterface $c) => new DbSupplierPaymentRepository(
            $c->get(DatabaseInterface::class),
            $c->get(ClockInterface::class)
        ));
        $c->bind(RecordSupplierPayment::class, static fn (ContainerInterface $c) => new RecordSupplierPayment(
            $c->get(SupplierPaymentRepositoryInterface::class),
            $c->get(OrderItemRepositoryInterface::class),
            $c->get(LedgerRepositoryInterface::class),
            $c->get(VendorMoneyUnitRegistryInterface::class),
            $c->get(CapabilityCheckerInterface::class),
            $c->get(AuditLogger::class),
            $c->get(ClockInterface::class),
            // THE SAME gateway object the repository holds, not a second one
            // over the same connection: nesting is counted in the object
            // (`alpha.56`).
            $c->get(DatabaseInterface::class)
        ));

        $c->bind(ReviewWithdrawals::class, static fn (ContainerInterface $c) => new ReviewWithdrawals(
            $c->get(WithdrawalRepositoryInterface::class),
            $c->get(LedgerRepositoryInterface::class),
            $c->get(WithdrawalStateMachine::class),
            $c->get(AuditLogger::class),
            $c->get(CapabilityCheckerInterface::class),
            // The payment document and the request's status are one unit of
            // work (`alpha.39`). The gateway satisfies `TransactionInterface`;
            // asking for the narrow contract is what keeps this service unable
            // to write SQL of its own.
            $c->get(DatabaseInterface::class),
            // The same unit source the capture claims against, so a sale, a
            // refund and a payout cannot disagree about a vendor's money.
            $c->get(VendorMoneyUnitRegistryInterface::class)
        ));
    }

    /**
     * The page is registered through the admin module's filter, which is what
     * keeps plugin styles on plugin screens (gate G-08). No `is_admin()` guard
     * here: MenuRegistrar already runs only on admin_menu, and the filter
     * costs one closure either way.
     */
    public function boot(ContainerInterface $c): void
    {
        $rules = new CommissionRulesPage($c);
        $withdrawals = new WithdrawalsPage($c);
        $supplierPayments = new SupplierPaymentsPage($c);
        // The returns queue is an Order module screen, registered from here on
        // purpose: OrderModule is self-gated and does not load at all while the
        // gate is shut, and a return that is already open must still be
        // decidable on such a day. Its services are bound in Bootstrap for the
        // same reason (F-15).
        $returns = new ReturnsPage($c);
        add_filter(AdminExtensions::FILTER, static function (array $pages) use ($rules, $withdrawals, $returns, $supplierPayments): array {
            $pages[] = [
                'slug' => CommissionRulesPage::SLUG,
                'page_title' => CommissionRulesPage::menuLabel(),
                'menu_label' => CommissionRulesPage::menuLabel(),
                'capability' => CommissionRulesPage::CAPABILITY,
                'render' => [$rules, 'render'],
            ];
            $pages[] = [
                'slug' => WithdrawalsPage::SLUG,
                'page_title' => WithdrawalsPage::menuLabel(),
                'menu_label' => WithdrawalsPage::menuLabel(),
                'capability' => WithdrawalsPage::CAPABILITY,
                'render' => [$withdrawals, 'render'],
            ];
            // Its own page, next to the settlement queue and NOT inside it:
            // «نام‌های مشابه نباید باعث شود چرخهٔ برداشت فروشنده برای خرید
            // نقدی از تأمین‌کننده استفاده شود» (§4).
            $pages[] = [
                'slug' => SupplierPaymentsPage::SLUG,
                'page_title' => SupplierPaymentsPage::menuLabel(),
                'menu_label' => SupplierPaymentsPage::menuLabel(),
                'capability' => SupplierPaymentsPage::CAPABILITY,
                'render' => [$supplierPayments, 'render'],
            ];
            $pages[] = [
                'slug' => ReturnsPage::SLUG,
                'page_title' => ReturnsPage::menuLabel(),
                'menu_label' => ReturnsPage::menuLabel(),
                'capability' => ReturnsPage::CAPABILITY,
                'render' => [$returns, 'render'],
            ];
            return $pages;
        });

        // «کمیسیون تک‌طب» on WooCommerce's own product editor. Registered
        // here rather than in the Product module because the field is money:
        // its capability is the financial-management one, its writes are
        // audited as financial changes, and the rule it stores is read by
        // this module at capture.
        if (class_exists(\WooCommerce::class, false) || did_action('woocommerce_init') > 0) {
            TectebCommissionPanel::register($c);
        }

        // …and the two meta keys it writes are guarded on EVERY write path,
        // not only on the panel's. Registered unconditionally and outside the
        // WooCommerce check above: a REST write or a CSV import arrives on a
        // request where WooCommerce is loaded, but a guard that depends on
        // being loaded in the right order is a guard waiting to be bypassed.
        TectebCommissionMetaGuard::register();

        // …and the vendor's own side of the same money.
        $area = new FinanceArea($c);
        add_filter(VendorAreaExtensions::FILTER, static function (array $views) use ($area): array {
            $views[] = [
                'slug' => FinanceArea::SLUG,
                'label' => __('مالی', 'tecteb-marketplace-core'),
                'title' => __('مالی فروشگاه', 'tecteb-marketplace-core'),
                'requires_vendor' => true,
                'render' => static fn (VendorAreaView $view): string => $area->render($view),
                'actions' => FinanceArea::ACTIONS,
                'handle' => static fn (string $action, Request $request, int $userId, VendorUrls $urls): ?VendorAreaOutcome
                    => $area->handle($action, $request, $userId, $urls),
                'url' => static fn (VendorUrls $urls): string => $area->financeUrl(),
                'nav' => true,
            ];
            return $views;
        });
    }

    private function version(): string
    {
        return class_exists(\Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::class, false)
            ? \Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::pluginVersion()
            : '0.0.0';
    }
}
