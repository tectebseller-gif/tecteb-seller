<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Admin\Presentation\Pages;

use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Admin\Presentation\AdminNavigation;
use Tecteb\Marketplace\Core\Modules\ModuleLoader;
use Tecteb\Marketplace\Modules\Admin\Presentation\View;
use Tecteb\Marketplace\Modules\Health\Application\HealthReportBuilder;
use Tecteb\Marketplace\Modules\Health\Infrastructure\Rest\HealthController;
use Tecteb\Marketplace\Modules\Finance\Application\MoneyScaleRollbackGate;
use Tecteb\Marketplace\Modules\Migration\Application\ConcludeMigration;
use Tecteb\Marketplace\Modules\Migration\Application\MigrateMinMax;
use Tecteb\Marketplace\Modules\Migration\Application\MigrationToolsVisibility;
use Tecteb\Marketplace\Modules\Migration\Presentation\MigrationToolMessages;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\VendorAccountLink;

final class HealthPage extends AbstractPage
{
    public const SLUG = 'tmc-health';
    public const CAPABILITY = Capabilities::VIEW_HEALTH;
    public const NONCE = 'tmc_health_action';

    public static function menuLabel(): string
    {
        return __('سلامت', 'tecteb-marketplace-core');
    }

    public static function pageTitle(): string
    {
        return __('سلامت بازارگاه', 'tecteb-marketplace-core');
    }

    /**
     * Purchase limits that have silently stopped applying.
     *
     * A Min Max grant is the fingerprint of the three values it was given
     * for, so a rule edited afterwards leaves the grant alive and dead and
     * the product allows any quantity. The exit page says so above its own
     * table; this is the same sentence for somebody who has not opened that
     * page — «هیچ حد خریدی بی هشدار از بین نرود».
     *
     * Wrapped: the Migration module may not be loaded at all, and a health
     * screen must not go blank because a module it is reporting on is
     * absent. An unavailable answer is `null`, which the view says in words
     * rather than drawing as zero.
     *
     * @return ?list<int>
     */
    private function staleGrants(): ?array
    {
        if (!$this->container->has(MigrateMinMax::class)) {
            return null;
        }
        try {
            return $this->container->get(MigrateMinMax::class)->staleGrants();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * WHETHER GOING BACK TO A RELEASE BEFORE `alpha.67` IS STILL SAFE —
     * measured, and shown where an operator already looks.
     *
     * On the health screen rather than only in a document, because the
     * document's own instruction was «inspect the rows», which the owner
     * rejected: a sentence nobody can verify is not a path. `null` when the
     * finance module is not loaded, which the view says in words.
     *
     * @return ?array<string,mixed>
     */
    private function rollbackGate(): ?array
    {
        if (!$this->container->has(MoneyScaleRollbackGate::class)) {
            return null;
        }
        try {
            return $this->container->get(MoneyScaleRollbackGate::class)->check();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The temporary migration section's state, and the way back.
     *
     * Here rather than on a migration screen for one reason: this is the page
     * that is still there when those screens are not. A control to bring back
     * hidden tools that lived on a hidden tool would be the circular gate
     * `alpha.72` describes.
     *
     * `null` when the Migration module is not loaded — said in words by the
     * view, never drawn as «visible» or «hidden», because neither is true.
     *
     * @return array{concluded_at:string, slugs:array<string,string>}|null
     */
    private function migrationTools(): ?array
    {
        if (!$this->container->has(MigrationToolsVisibility::class)) {
            return null;
        }
        try {
            $visibility = $this->container->get(MigrationToolsVisibility::class);
            if (!$visibility instanceof MigrationToolsVisibility) {
                return null;
            }
            $concludedAt = $visibility->concludedAt();
        } catch (\Throwable) {
            return null;
        }
        // The addresses, so a manager can reach a hidden screen without
        // being told to type one. Printed only while they ARE hidden; while
        // they are in the menu, the menu is the link.
        $slugs = [];
        if ($concludedAt !== '') {
            foreach (AdminNavigation::GROUPS[AdminNavigation::MIGRATION] as $slug) {
                $slugs[(string) $slug] = admin_url('admin.php?page=' . (string) $slug);
            }
        }
        return ['concluded_at' => $concludedAt, 'slugs' => $slugs];
    }

    /**
     * The one POST this page takes: show the temporary tools again.
     *
     * Only the reveal direction. Concluding the migration happens on the
     * handover screen, where a manager is already looking at the twelve steps
     * that have to be DONE first — and it is `ConcludeMigration` that checks
     * them, not a button.
     */
    private function handleAction(Request $request): string
    {
        if (!$request->isPost() || !$request->hasPost('health_action')) {
            return '';
        }
        if (!$request->nonceOk('tmc_health_nonce', self::NONCE)) {
            return 'err:' . __('درخواست معتبر نبود. صفحه را تازه کنید و دوباره تلاش کنید.', 'tecteb-marketplace-core');
        }
        if ($request->postKey('health_action') !== 'reveal_migration_tools') {
            return '';
        }
        if (!$this->container->has(ConcludeMigration::class)) {
            return 'err:' . __('ماژول مهاجرت بارگذاری نشده، پس چیزی تغییر نکرد.', 'tecteb-marketplace-core');
        }
        $outcome = $this->container->get(ConcludeMigration::class)->reopen();
        return $outcome->ok
            ? 'ok:' . __('ابزارهای موقت مهاجرت دوباره در منو نمایش داده می‌شوند.', 'tecteb-marketplace-core')
            : 'err:' . MigrationToolMessages::of($outcome->code);
    }

    protected function renderAuthorized(): void
    {
        $notice = $this->handleAction(Request::capture());
        /** @var ModuleLoader $loader */
        $loader = $this->container->get(ModuleLoader::class);
        $loadReport = $loader->report();
        $healthState = $loadReport?->state('health');

        if (!$this->container->has(HealthReportBuilder::class)) {
            View::render('health', [
                'available' => false,
                'module_state' => $healthState,
                'load_report' => $loadReport,
                'rest_path' => '/wp-json/' . HealthController::NAMESPACE . HealthController::ROUTE,
                'account_hooks' => VendorAccountLink::lastSeen(),
                'stale_grants' => $this->staleGrants(),
                'rollback_gate' => $this->rollbackGate(),
                'migration_tools' => $this->migrationTools(),
                'notice' => $notice,
                'nonce' => self::NONCE,
            ]);
            return;
        }
        /** @var HealthReportBuilder $builder */
        $builder = $this->container->get(HealthReportBuilder::class);
        $report = $builder->build();
        View::render('health', [
            'available' => true,
            'report' => $report,
            'module_state' => $healthState,
            'load_report' => $loadReport,
            'rest_path' => '/wp-json/' . HealthController::NAMESPACE . HealthController::ROUTE,
            'modules_url' => admin_url('admin.php?page=' . ModulesPage::SLUG),
            // What the SITE measured about its own theme. Read here rather
            // than claimed anywhere: this plugin cannot see the owner's
            // account page, and «the hooks never ran» and «they ran and we
            // correctly drew nothing» are opposite problems.
            'account_hooks' => VendorAccountLink::lastSeen(),
            'stale_grants' => $this->staleGrants(),
            // THE ROLLBACK GATE, as a count rather than as advice.
            'rollback_gate' => $this->rollbackGate(),
            // AND whether the temporary migration section is still in the
            // menu — with the way back, on the page that survives hiding it.
            'migration_tools' => $this->migrationTools(),
            'notice' => $notice,
            'nonce' => self::NONCE,
        ]);
    }
}
