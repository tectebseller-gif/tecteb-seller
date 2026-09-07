<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Admin\Presentation;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Modules\Admin\Presentation\Pages\DashboardPage;
use Tecteb\Marketplace\Modules\Admin\Presentation\Pages\HealthPage;
use Tecteb\Marketplace\Modules\Admin\Presentation\Pages\ModulesPage;
use Tecteb\Marketplace\Modules\Admin\Presentation\Pages\SettingsPage;

/** Top-level «بازارگاه تک‌طب» with exactly four capability-gated pages (CORE-03/05). */
final class MenuRegistrar
{
    /** @var list<string> hook suffixes returned by WordPress; assets load only on these */
    private array $hookSuffixes = [];

    public function __construct(private ContainerInterface $container)
    {
    }

    public function register(): void
    {
        $dashboard = new DashboardPage($this->container);
        $health = new HealthPage($this->container);
        $settings = new SettingsPage($this->container);
        $modules = new ModulesPage($this->container);

        $hook = add_menu_page(
            __('بازارگاه تک‌طب', 'tecteb-marketplace-core'),
            __('بازارگاه تک‌طب', 'tecteb-marketplace-core'),
            DashboardPage::CAPABILITY,
            DashboardPage::SLUG,
            [$dashboard, 'render'],
            'dashicons-store',
            56
        );
        $this->remember($hook);

        foreach ([$dashboard, $health, $settings, $modules] as $page) {
            $hook = add_submenu_page(
                DashboardPage::SLUG,
                $page::pageTitle(),
                $page::menuLabel(),
                $page::CAPABILITY,
                $page::SLUG,
                [$page, 'render']
            );
            $this->remember($hook);
        }
    }

    private function remember(mixed $hook): void
    {
        if (is_string($hook) && $hook !== '' && !in_array($hook, $this->hookSuffixes, true)) {
            $this->hookSuffixes[] = $hook;
        }
    }

    /** @return list<string> */
    public function hookSuffixes(): array
    {
        return $this->hookSuffixes;
    }
}
