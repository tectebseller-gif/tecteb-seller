<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Presentation;

/**
 * The vendor area's own page — not wp-admin and not the site theme.
 *
 * Design rules this shell enforces, all of them from the owner's review of
 * the first preview:
 *  - the current menu item is readable (its colours are defined ON this
 *    shell, not inherited from a stylesheet that is not loaded here);
 *  - nothing in the chrome talks about development: no route names, no
 *    "not built yet" chips, no links to documentation;
 *  - only sections that exist appear in the menu.
 */
final class VendorShell
{
    /** @param list<array{slug:string,label:string,url:string}> $nav */
    public static function render(
        string $title,
        string $current,
        array $nav,
        string $storeName,
        string $bodyHtml,
        string $stylesheetUrl,
        string $version,
        array $scriptUrls = []
    ): string {
        $items = '';
        $currentLabel = '';
        foreach ($nav as $item) {
            $isCurrent = $item['slug'] === $current;
            if ($isCurrent) {
                $currentLabel = (string) $item['label'];
            }
            $items .= '<li><a class="tv-nav__link' . ($isCurrent ? ' is-current' : '') . '" href="' . esc_url($item['url']) . '"'
                . ($isCurrent ? ' aria-current="page"' : '') . '>' . esc_html($item['label']) . '</a></li>';
        }
        $navLabel = __('بخش‌های پنل فروشنده', 'tecteb-marketplace-core');
        $store = $storeName !== ''
            ? '<p class="tv-head__store">' . esc_html($storeName) . '</p>'
            : '';

        $html = '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<title>' . esc_html($title) . '</title>'
            . '<link rel="stylesheet" href="' . esc_url($stylesheetUrl . '?ver=' . $version) . '">'
            . '</head><body class="tv">'
            . '<a class="tv-skip" href="#tv-main">' . esc_html__('پرش به محتوای اصلی', 'tecteb-marketplace-core') . '</a>'
            . '<header class="tv-head"><div class="tv-head__bar">'
            . '<div class="tv-head__brand"><span class="tv-head__name">' . esc_html__('بازارگاه تک‌طب', 'tecteb-marketplace-core') . '</span>'
            . '<span class="tv-head__role">' . esc_html__('پنل فروشنده', 'tecteb-marketplace-core') . '</span></div>'
            . $store . '</div>'
            // Collapsed on a phone, and collapsed without a script: a ten-item
            // menu laid out two-up put five rows of buttons above every page.
            // `<details>` is the native disclosure — keyboard operable and
            // announced as expandable with nothing loaded. The script only
            // opens it when the viewport is wide, which was measured to be
            // impossible in CSS (Chromium 141 hides a closed panel through its
            // own shadow slot, so no `display` rule from here reaches it).
            . '<details class="tv-nav" id="tv-nav">'
            . '<summary class="tv-nav__toggle">'
            . '<span class="tv-nav__toggle-icon" aria-hidden="true"></span>'
            . '<span class="tv-nav__toggle-text">' . esc_html($navLabel) . '</span>'
            . ($currentLabel !== ''
                ? '<span class="tv-nav__toggle-current">' . esc_html($currentLabel) . '</span>'
                : '')
            . '</summary>'
            . '<nav class="tv-nav__panel" aria-label="' . esc_attr($navLabel) . '"><ul>' . $items . '</ul></nav>'
            . '</details>'
            . '</header>'
            . '<main id="tv-main" class="tv-main" tabindex="-1"><h1 class="tv-title">' . esc_html($title) . '</h1>'
            . $bodyHtml
            . '</main>';

        // Scripts, when a view asks for one, at the end of the body and
        // deferred. This area works with none: every form is a plain POST and
        // every page is a URL. A script here may only ADD to that — the
        // product form's autosave and its «you have unsaved changes» warning —
        // and a vendor whose JavaScript is off loses those and nothing else.
        foreach ($scriptUrls as $scriptUrl) {
            $html .= '<script src="' . esc_url($scriptUrl . '?ver=' . $version) . '" defer></script>';
        }

        $html .= '</body></html>';

        return $html;
    }
}
