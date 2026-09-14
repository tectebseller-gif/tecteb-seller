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
use Tecteb\Marketplace\Modules\Finance\Application\ResolveCommissionRate;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionCalculator;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbCommissionRuleRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbLedgerRepository;
use Tecteb\Marketplace\Modules\Finance\Presentation\Admin\CommissionRulesPage;

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
            'قواعد کمیسیون، محاسبه سهم و دفترکل تغییرناپذیر'
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
        $c->bind(ResolveCommissionRate::class, static fn (ContainerInterface $c) => new ResolveCommissionRate(
            $c->get(CommissionRuleRepositoryInterface::class)
        ));
        $c->bind(RecordCommission::class, static fn (ContainerInterface $c) => new RecordCommission(
            $c->get(LedgerRepositoryInterface::class),
            $c->get(ResolveCommissionRate::class),
            $c->get(CommissionCalculator::class),
            $c->get(AuditLogger::class)
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
        $page = new CommissionRulesPage($c);
        add_filter(AdminExtensions::FILTER, static function (array $pages) use ($page): array {
            $pages[] = [
                'slug' => CommissionRulesPage::SLUG,
                'page_title' => CommissionRulesPage::menuLabel(),
                'menu_label' => CommissionRulesPage::menuLabel(),
                'capability' => CommissionRulesPage::CAPABILITY,
                'render' => [$page, 'render'],
            ];
            return $pages;
        });
    }

    private function version(): string
    {
        return class_exists(\Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::class, false)
            ? \Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::pluginVersion()
            : '0.0.0';
    }
}
