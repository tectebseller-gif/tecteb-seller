<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Presentation;

/**
 * How another module adds a page to /vendor/.
 *
 * The same idea as AdminExtensions, for the front-end area: one filter feeds
 * the routing table, the navigation and the POST dispatch together, so a view
 * that is reachable is also one the router knows how to write to — and the
 * vendor module never has to learn what a product is.
 */
final class VendorAreaExtensions
{
    public const FILTER = 'tmc_vendor_area_views';

    /**
     * @return list<array{slug:string,label:string,title:string,requires_vendor:bool,render:callable,actions:list<string>,handle:?callable,url:?callable,nav:bool}>
     */
    public static function views(): array
    {
        $out = [];
        /** @var mixed $raw */
        $raw = apply_filters(self::FILTER, []);
        foreach (is_array($raw) ? $raw : [] as $entry) {
            if (!is_array($entry) || !isset($entry['slug'], $entry['render']) || !is_callable($entry['render'])) {
                continue;
            }
            $slug = sanitize_key((string) $entry['slug']);
            if ($slug === '') {
                continue;
            }
            $actions = [];
            foreach (is_array($entry['actions'] ?? null) ? $entry['actions'] : [] as $action) {
                $action = sanitize_key((string) $action);
                if ($action !== '') {
                    $actions[] = $action;
                }
            }
            $out[] = [
                'slug' => $slug,
                'label' => (string) ($entry['label'] ?? $slug),
                'title' => (string) ($entry['title'] ?? $entry['label'] ?? $slug),
                'requires_vendor' => (bool) ($entry['requires_vendor'] ?? true),
                'render' => $entry['render'],
                'actions' => $actions,
                'handle' => isset($entry['handle']) && is_callable($entry['handle']) ? $entry['handle'] : null,
                // The module owns its own address: the router knows how to
                // build /vendor/<slug>/, but only the module knows whether
                // that page wants a query argument on the navigation link.
                'url' => isset($entry['url']) && is_callable($entry['url']) ? $entry['url'] : null,
                'nav' => (bool) ($entry['nav'] ?? true),
            ];
        }
        return $out;
    }
}
