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
 *
 * ⛔ **§8, AND WHAT IT DOES NOT TOUCH.** The owner's brief is about ONE
 * header: «فقط هدر داشبورد فروشنده را بازطراحی کن؛ به هدر سایت اصلی، بخش‌های
 * داخلی دیگر یا پنل مدیریت وردپرس دست نزن». This class renders that header
 * and nothing else renders it, so the change is bounded by the file: the
 * site's own `header.php`, the public store page's chrome and every wp-admin
 * screen are reached by other code and are not read or written here.
 *
 * What §8 asked for and where each piece is:
 *  - **lower** — the name and the role share one line instead of stacking,
 *    and the bar's padding came down. Measured in `assets/vendor/tmc-vendor.css`.
 *  - **balanced corners** — the nav items and the account control use
 *    `--tv-radius-sm`, not the 999px capsules the owner rejected.
 *  - **navy, blue, turquoise, white** — the bar is navy with white text (the
 *    pair `tools/contrast.php` already pins at 12:1) and the turquoise is a
 *    rule and an active marker, never a text background.
 *  - **the store name is the account control** — a `<summary>`, so it opens
 *    with a click, with the keyboard, and with no script at all.
 *
 * ⛔ **AND THE THREE ACCOUNT LINKS ARE GIVEN TO IT, NOT BUILT HERE.**
 * «خروج از حساب باید با مسیر استاندارد و امن وردپرس انجام شود» means
 * `wp_logout_url()`, which carries WordPress's own nonce — and a Presentation
 * class that reached for it would be the one rule this codebase has held since
 * `alpha.9`: this layer does not call WordPress. So `VendorRoutes` builds the
 * three addresses and this renders them. The practical half of that is the
 * owner's other sentence — «بازگشت به سایت نباید باعث خروج شود» — which is
 * true here by construction: two different parameters, printed as two
 * different links, and a test asserts they are not the same URL.
 */
final class VendorShell
{
    /**
     * @param list<array{slug:string,label:string,url:string}>            $nav
     * @param list<string>                                               $scriptUrls
     * @param array{shop_url?:string,site_url?:string,logout_url?:string} $account
     */
    public static function render(
        string $title,
        string $current,
        array $nav,
        string $storeName,
        string $bodyHtml,
        string $stylesheetUrl,
        string $version,
        array $scriptUrls = [],
        array $account = []
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

        $html = '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<title>' . esc_html($title) . '</title>'
            . '<link rel="stylesheet" href="' . esc_url($stylesheetUrl . '?ver=' . $version) . '">'
            . '</head><body class="tv">'
            . '<a class="tv-skip" href="#tv-main">' . esc_html__('پرش به محتوای اصلی', 'tecteb-marketplace-core') . '</a>'
            . '<header class="tv-head"><div class="tv-head__bar">'
            // The mark is drawn in CSS — one shape, no icon font and no sprite,
            // which is what «آیکون‌های ساده و یکپارچه» buys here: nothing to
            // load, nothing to go missing, and it scales with the text.
            . '<div class="tv-head__brand"><span class="tv-head__mark" aria-hidden="true"></span>'
            . '<span class="tv-head__name">' . esc_html__('بازارگاه تک‌طب', 'tecteb-marketplace-core') . '</span>'
            . '<span class="tv-head__role">' . esc_html__('پنل فروشنده', 'tecteb-marketplace-core') . '</span></div>'
            . self::account($storeName, $account) . '</div>'
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

    /**
     * The shop's name, and the account menu it opens.
     *
     * ⛔ **THREE THINGS THIS DELIBERATELY REFUSES TO DO.**
     *
     * 1. **No name, no control.** The invitation page renders this shell for
     *    somebody who is not logged in and has no shop (`$storeName === ''`),
     *    and an account menu there would offer to log out a visitor who never
     *    logged in. So an empty name renders nothing at all, exactly as it did
     *    before §8.
     * 2. **No links, no button.** If the caller supplies no addresses the name
     *    is printed as a label — the pre-§8 markup, byte for byte. A control
     *    that opens an empty menu is worse than a label, because it teaches
     *    the vendor that clicking their shop's name does nothing.
     * 3. **No link it has not earned.** «مشاهده فروشگاه» is printed only when
     *    the caller passes an address, and `VendorRoutes` gets that address
     *    from `StorePage::publicShop()`, which answers `null` for a shop whose
     *    public page would be a 404. That is the `alpha.17` finding in as many
     *    words: three addresses were listed and two of them were 404s.
     *
     * @param array{shop_url?:string,site_url?:string,logout_url?:string} $account
     */
    private static function account(string $storeName, array $account): string
    {
        if ($storeName === '') {
            return '';
        }
        $links = [];
        foreach ([
            ['shop_url', __('مشاهده فروشگاه', 'tecteb-marketplace-core'), 'shop'],
            ['site_url', __('بازگشت به سایت', 'tecteb-marketplace-core'), 'site'],
            ['logout_url', __('خروج از حساب', 'tecteb-marketplace-core'), 'logout'],
        ] as [$key, $label, $kind]) {
            $url = trim((string) ($account[$key] ?? ''));
            if ($url !== '') {
                $links[] = [$url, $label, $kind];
            }
        }
        if ($links === []) {
            return '<p class="tv-head__store">' . esc_html($storeName) . '</p>';
        }

        $items = '';
        foreach ($links as [$url, $label, $kind]) {
            $items .= '<li><a class="tv-acct__link tv-acct__link--' . $kind . '" href="' . esc_url($url) . '"'
                // Logging out is not a page to index or to prefetch, and the
                // address carries a nonce that is nobody else's business.
                . ($kind === 'logout' ? ' rel="nofollow"' : '')
                . '>' . esc_html($label) . '</a></li>';
        }
        $menuLabel = __('منوی حساب', 'tecteb-marketplace-core');

        // The summary's text IS the shop's name, which is what the owner asked
        // for — «نام فروشگاه در هدر قابل کلیک باشد». No `aria-label` on it:
        // one would REPLACE that name for a screen reader with a generic
        // word, and `<details>` already announces itself as expandable.
        return '<details class="tv-acct" id="tv-acct">'
            . '<summary class="tv-acct__toggle">'
            . '<span class="tv-acct__store">' . esc_html($storeName) . '</span>'
            . '<span class="tv-acct__chevron" aria-hidden="true"></span>'
            . '</summary>'
            . '<nav class="tv-acct__menu" aria-label="' . esc_attr($menuLabel) . '"><ul>' . $items . '</ul></nav>'
            . '</details>';
    }
}
