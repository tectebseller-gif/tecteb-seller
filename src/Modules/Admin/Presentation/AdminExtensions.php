<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Admin\Presentation;

/**
 * How another module adds a page under «بازارگاه تک‌طب».
 *
 * One filter feeds BOTH the WordPress submenu and the in-page navigation, so
 * the two can never drift apart, and every such page goes through
 * MenuRegistrar — which is what keeps the promise that plugin styles load on
 * plugin screens only (gate G-08).
 */
final class AdminExtensions
{
    public const FILTER = 'tmc_admin_submenu_pages';

    /**
     * @return list<array{slug:string,page_title:string,menu_label:string,capability:string,render:callable,nav:bool}>
     */
    public static function pages(): array
    {
        $out = [];
        /** @var mixed $raw */
        $raw = apply_filters(self::FILTER, []);
        foreach (is_array($raw) ? $raw : [] as $entry) {
            if (!is_array($entry) || !isset($entry['slug'], $entry['capability'], $entry['render'])) {
                continue;
            }
            if (!is_callable($entry['render'])) {
                continue;
            }
            $out[] = [
                'slug' => (string) $entry['slug'],
                'page_title' => (string) ($entry['page_title'] ?? $entry['menu_label'] ?? $entry['slug']),
                'menu_label' => (string) ($entry['menu_label'] ?? $entry['slug']),
                'capability' => (string) $entry['capability'],
                'render' => $entry['render'],
                'nav' => (bool) ($entry['nav'] ?? true),
            ];
        }
        return $out;
    }
}
