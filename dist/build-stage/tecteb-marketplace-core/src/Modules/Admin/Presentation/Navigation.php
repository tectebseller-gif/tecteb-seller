<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Admin\Presentation;

use Tecteb\Marketplace\Modules\Admin\Presentation\Pages\DashboardPage;
use Tecteb\Marketplace\Modules\Admin\Presentation\Pages\HealthPage;
use Tecteb\Marketplace\Modules\Admin\Presentation\Pages\ModulesPage;
use Tecteb\Marketplace\Modules\Admin\Presentation\Pages\SettingsPage;

/** Real links only — never a disabled menu of things that do not exist (UX §21). */
final class Navigation
{
    /** @return list<array{slug:string,label:string,capability:string,url:string}> */
    public static function items(): array
    {
        $items = [];
        foreach ([DashboardPage::class, HealthPage::class, SettingsPage::class, ModulesPage::class] as $page) {
            if (!current_user_can($page::CAPABILITY)) {
                continue;
            }
            $items[] = [
                'slug' => $page::SLUG,
                'label' => $page::menuLabel(),
                'capability' => $page::CAPABILITY,
                'url' => admin_url('admin.php?page=' . $page::SLUG),
            ];
        }
        // Same source as the WordPress submenu, so the header cannot show a
        // link the menu lacks (or the reverse).
        foreach (AdminExtensions::pages() as $extra) {
            if (!$extra['nav'] || !current_user_can($extra['capability'])) {
                continue;
            }
            $items[] = [
                'slug' => $extra['slug'],
                'label' => $extra['menu_label'],
                'capability' => $extra['capability'],
                'url' => admin_url('admin.php?page=' . $extra['slug']),
            ];
        }
        return $items;
    }
}
