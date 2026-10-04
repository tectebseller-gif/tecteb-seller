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
     * @return list<array{slug:string,page_title:string,menu_label:string,capability:string,render:callable,nav:bool,bubble:?callable,prepare:?callable}>
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
                // How many things on this page are waiting for somebody, for
                // the red count on the menu. A callable rather than a number
                // because the menu is built on every admin request and the
                // answer is a query: it must not be asked for a user who
                // cannot open the page, and it must not be cached into a
                // number that goes stale the moment a decision is taken.
                'bubble' => isset($entry['bubble']) && is_callable($entry['bubble']) ? $entry['bubble'] : null,
                // Build the page NOW, on `load-{$hook}`, instead of waiting for
                // the render callback. WordPress fires `load-{$hook}` before it
                // requires `admin-header.php`, and the header is what prints
                // the red bubble — so a page that records a view (the review
                // list marking submissions seen) must run before the header or
                // its own number is a request behind. A page with no `prepare`
                // is untouched: nothing runs early, and `render` is still the
                // only thing that prints.
                'prepare' => isset($entry['prepare']) && is_callable($entry['prepare']) ? $entry['prepare'] : null,
            ];
        }
        return $out;
    }
}
