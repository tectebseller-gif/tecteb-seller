<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Presentation;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Marketplace\Presentation\ReviewMessages;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Vendor\Domain\StoreSettings;

/**
 * The HTML of a shop's public page — and, more usefully, the list of what may
 * be on it.
 *
 * `PUBLIC_FIELDS` is not documentation. It is the allowlist this class builds
 * from, and the evidence asserts both halves: that every name on it appears,
 * and that every field on `PRIVATE_FIELDS` appears nowhere in the rendered
 * bytes. A future edit that reaches for `originWarehouse` fails a check rather
 * than shipping an address.
 *
 * The one badge v1 has is «فروشندهٔ تأییدشده» (Master A.5: «در نسخه اول فقط
 * نشان «فروشنده تأییدشده» وجود دارد»). It is shown because the page only
 * exists for an approved shop — so the badge states the condition that got the
 * page rendered, rather than a second fact that could disagree with it. No
 * other badge is invented: no «پرفروش», no «ارسال سریع», nothing the
 * marketplace has not defined and cannot substantiate.
 */
final class StorePageView
{
    /**
     * Everything a shopper may see. The whole list, in one place.
     *
     * @var list<string>
     */
    public const PUBLIC_FIELDS = [
        'storeName',
        'city',
        'intro',
        'logoId',
        'bannerId',
        'preparationDays',
        'closed',
        'reopenMessage',
        'social',
    ];

    /**
     * Everything that must never reach this page, named so a test can look.
     *
     * `originWarehouse` is the shop's dispatch address, and the specification
     * calls out «نشانی خصوصی» by name. The rest belongs to the application and
     * the bank tab and has no business on a public URL at all.
     *
     * @var list<string>
     */
    public const PRIVATE_FIELDS = [
        'originWarehouse',
        'iban',
        'bankAccount',
        'nationalId',
        'registrationNumber',
        'applicantEmail',
        'applicantMobile',
        'applicantAddress',
    ];

    /**
     * @param list<Product> $products
     * @param array{product:array{count:int, average_hundredths:int, available:bool},
     *              vendor:array{count:int, average_hundredths:int, distribution:array<int,int>}} $standing
     */
    public static function html(
        int $vendorUserId,
        StoreSettings $store,
        array $products,
        array $standing,
        string $canonical,
        int $page = 1,
        int $pages = 1
    ): string {
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);
        $name = $store->storeName !== ''
            ? $store->storeName
            : __('فروشگاه بازارگاه تک‌طب', 'tecteb-marketplace-core');
        $description = $store->intro !== ''
            ? mb_substr(wp_strip_all_tags($store->intro), 0, 155)
            : sprintf(
                /* translators: %s: the shop's name */
                __('صفحهٔ فروشگاه %s در بازارگاه تک‌طب', 'tecteb-marketplace-core'),
                $name
            );

        $html = '<!DOCTYPE html><html lang="' . esc_attr(get_bloginfo('language')) . '" dir="rtl"><head>'
            . '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . esc_html($name . ' — ' . get_bloginfo('name')) . '</title>'
            . '<meta name="description" content="' . esc_attr($description) . '">'
            . '<link rel="canonical" href="' . esc_url($canonical) . '">'
            // Open Graph, because a shop link pasted into a messenger is how
            // most people will meet this page.
            . '<meta property="og:type" content="website">'
            . '<meta property="og:title" content="' . esc_attr($name) . '">'
            . '<meta property="og:description" content="' . esc_attr($description) . '">'
            . '<meta property="og:url" content="' . esc_url($canonical) . '">';
        if ($store->logoId > 0) {
            $logo = wp_get_attachment_image_url($store->logoId, 'medium');
            if (is_string($logo)) {
                $html .= '<meta property="og:image" content="' . esc_url($logo) . '">';
            }
        }
        $html .= self::jsonLd($name, $description, $canonical, $store, $standing)
            . self::style()
            . '</head><body class="tmc-store">';

        $html .= '<main class="tmc-store__main">';
        if ($store->bannerId > 0) {
            $banner = wp_get_attachment_image_url($store->bannerId, 'large');
            if (is_string($banner)) {
                $html .= '<img class="tmc-store__banner" src="' . esc_url($banner) . '" alt="" loading="lazy">';
            }
        }
        $html .= '<header class="tmc-store__head"><h1>' . esc_html($name) . '</h1>';
        // The only badge v1 has, and it says the condition that rendered the
        // page rather than a second fact that could drift from it.
        $html .= '<p class="tmc-store__badge">'
            . esc_html__('فروشندهٔ تأییدشده', 'tecteb-marketplace-core') . '</p>';
        if ($store->city !== '') {
            $html .= '<p class="tmc-store__city">' . esc_html($store->city) . '</p>';
        }
        $html .= '</header>';

        if ($store->intro !== '') {
            $html .= '<section class="tmc-store__card"><h2>'
                . esc_html__('معرفی', 'tecteb-marketplace-core') . '</h2>'
                . '<p>' . esc_html($store->intro) . '</p></section>';
        }

        $html .= self::standingSection($standing);
        $html .= self::policySection($store, $fa);
        $html .= self::productSection($products, $fa, $page, $pages, $canonical);
        $html .= self::socialSection($store);

        return $html . '</main></body></html>';
    }

    /** @param array<string,mixed> $standing */
    private static function standingSection(array $standing): string
    {
        $vendorCount = (int) ($standing['vendor']['count'] ?? 0);
        $productCount = (int) ($standing['product']['count'] ?? 0);
        // «امتیاز/نظر در صورت فعال بودن» (Master §7): with nothing approved
        // yet there is no section at all, rather than a row of dashes that
        // reads as a bad score.
        if ($vendorCount <= 0 && $productCount <= 0) {
            return '';
        }
        $html = '<section class="tmc-store__card"><h2>'
            . esc_html__('امتیاز', 'tecteb-marketplace-core') . '</h2><dl class="tmc-store__figures">';
        if ($vendorCount > 0) {
            $html .= '<div><dt>' . esc_html__('امتیاز فروشگاه', 'tecteb-marketplace-core') . '</dt><dd>'
                . esc_html(ReviewMessages::stars((int) $standing['vendor']['average_hundredths'])
                    . ' — ' . ReviewMessages::count($vendorCount))
                . '</dd></div>';
        }
        if ($productCount > 0) {
            $html .= '<div><dt>' . esc_html__('امتیاز کالاها', 'tecteb-marketplace-core') . '</dt><dd>'
                . esc_html(ReviewMessages::stars((int) $standing['product']['average_hundredths'])
                    . ' — ' . ReviewMessages::count($productCount))
                . '</dd></div>';
        }
        return $html . '</dl><p class="tmc-store__note">'
            . esc_html(ReviewMessages::separateAveragesNote()) . '</p></section>';
    }

    /** @param callable(string|int):string $fa */
    private static function policySection(StoreSettings $store, callable $fa): string
    {
        $html = '<section class="tmc-store__card"><h2>'
            . esc_html__('سیاست‌های فروشگاه', 'tecteb-marketplace-core') . '</h2><ul>';
        $html .= '<li>' . esc_html(sprintf(
            /* translators: %s: how many days the shop needs to prepare an order */
            _n('زمان آماده‌سازی: %s روز کاری', 'زمان آماده‌سازی: %s روز کاری', $store->preparationDays, 'tecteb-marketplace-core'),
            $fa((string) $store->preparationDays)
        )) . '</li>';
        // The dispatch warehouse is NOT here. It is the shop's own address and
        // A.5 names «نشانی خصوصی» as the thing this page must not show.
        $html .= '</ul>';
        if ($store->closed) {
            $html .= '<p class="tmc-store__closed">'
                . esc_html__('این فروشگاه در حال حاضر موقتاً تعطیل است و سفارش تازه نمی‌گیرد. سفارش‌های قبلی طبق روال ادامه دارند.', 'tecteb-marketplace-core')
                . '</p>';
            if ($store->reopenMessage !== '') {
                $html .= '<p class="tmc-store__note">' . esc_html($store->reopenMessage) . '</p>';
            }
        }
        return $html . '</section>';
    }

    /**
     * @param list<Product> $products
     * @param callable(string|int):string $fa
     */
    private static function productSection(array $products, callable $fa, int $page = 1, int $pages = 1, string $canonical = ''): string
    {
        $html = '<section class="tmc-store__card"><h2>'
            . esc_html__('محصولات', 'tecteb-marketplace-core') . '</h2>';
        if ($products === []) {
            return $html . '<p>' . esc_html__('هنوز محصولی روی این فروشگاه منتشر نشده است.', 'tecteb-marketplace-core')
                . '</p></section>';
        }
        $html .= '<ul class="tmc-store__products" role="list">';
        foreach ($products as $product) {
            $wcId = (int) $product->wcProductId;
            $html .= '<li class="tmc-store__product">'
                . '<a href="' . esc_url((string) get_permalink($wcId)) . '">'
                . esc_html($product->details->title) . '</a>';
            if ($product->details->priceMinor > 0) {
                $html .= ' <span class="tmc-store__price">'
                    . esc_html($fa(number_format((int) $product->details->priceMinor / 100)) . ' '
                        . __('تومان', 'tecteb-marketplace-core'))
                    . '</span>';
            }
            $html .= '</li>';
        }
        $html .= '</ul>';
        if ($pages > 1) {
            $html .= self::pager($page, $pages, $canonical, $fa);
        }
        return $html . '</section>';
    }

    /**
     * Real links, not a script.
     *
     * Every page of this catalogue is a URL somebody can bookmark, send to a
     * colleague or reach with the back button — which is the whole point of a
     * public shop page, and something an «بیشتر» button that appends rows
     * cannot do.
     *
     * @param callable(string|int):string $fa
     */
    private static function pager(int $page, int $pages, string $canonical, callable $fa): string
    {
        $html = '<nav class="tmc-store__pager" aria-label="'
            . esc_attr__('صفحه‌بندی محصولات فروشگاه', 'tecteb-marketplace-core') . '"><ul role="list">';
        if ($page > 1) {
            $html .= '<li><a rel="prev" href="' . esc_url(self::pageUrl($canonical, $page - 1)) . '">'
                . esc_html__('صفحهٔ قبل', 'tecteb-marketplace-core') . '</a></li>';
        }
        $html .= '<li><span>' . esc_html(sprintf(
            /* translators: 1: current page, 2: total pages */
            __('صفحهٔ %1$s از %2$s', 'tecteb-marketplace-core'),
            $fa((string) $page),
            $fa((string) $pages)
        )) . '</span></li>';
        if ($page < $pages) {
            $html .= '<li><a rel="next" href="' . esc_url(self::pageUrl($canonical, $page + 1)) . '">'
                . esc_html__('صفحهٔ بعد', 'tecteb-marketplace-core') . '</a></li>';
        }
        return $html . '</ul></nav>';
    }

    /**
     * The canonical carries this page's own number, so it is stripped before
     * another is added. Appending would give `…&page=2&page=3`, and which one
     * wins is the server's business, not ours to guess.
     */
    private static function pageUrl(string $canonical, int $page): string
    {
        $base = remove_query_arg('tmc_store_page', $canonical);
        return $page > 1 ? add_query_arg('tmc_store_page', (string) $page, $base) : $base;
    }

    private static function socialSection(StoreSettings $store): string
    {
        $links = array_filter($store->social, static fn ($v): bool => is_string($v) && trim($v) !== '');
        if ($links === []) {
            return '';
        }
        // «اطلاعات تماس عمومی انتخاب‌شده» (Master §7): only what the shop chose
        // to publish, and `rel="nofollow ugc"` because these point off-site to
        // addresses the marketplace has not vouched for.
        $html = '<section class="tmc-store__card"><h2>'
            . esc_html__('راه‌های ارتباط', 'tecteb-marketplace-core') . '</h2><ul>';
        foreach ($links as $network => $handle) {
            $html .= '<li><a rel="nofollow ugc noopener" href="' . esc_url((string) $handle) . '">'
                . esc_html((string) $network) . '</a></li>';
        }
        return $html . '</ul></section>';
    }

    /** @param array<string,mixed> $standing */
    private static function jsonLd(
        string $name,
        string $description,
        string $canonical,
        StoreSettings $store,
        array $standing
    ): string {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Store',
            'name' => $name,
            'description' => $description,
            'url' => $canonical,
        ];
        if ($store->city !== '') {
            // The CITY only. `addressLocality` with no street is exactly as
            // much as a shopper needs and as little as the shop has agreed to.
            $data['address'] = ['@type' => 'PostalAddress', 'addressLocality' => $store->city];
        }
        $count = (int) ($standing['vendor']['count'] ?? 0);
        if ($count > 0) {
            $data['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => number_format((int) $standing['vendor']['average_hundredths'] / 100, 2, '.', ''),
                'reviewCount' => $count,
                'bestRating' => 5,
                'worstRating' => 1,
            ];
        }
        return '<script type="application/ld+json">'
            . wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . '</script>';
    }

    /**
     * The page's own styles, inline.
     *
     * Inline rather than an enqueued file because this page is rendered
     * standalone — it is not inside the theme's template, so the theme's
     * stylesheet is not loaded and a `wp_enqueue_style` would go nowhere.
     */
    private static function style(): string
    {
        return '<style>'
            . ':root{--s-ink:#143C4D;--s-sky:#6ABFE7;--s-green:#21D483;--s-page:#EDF3F6;'
            . '--s-surface:#fff;--s-muted:#4F6570;--s-border:#D3DDE3}'
            . 'body{margin:0;background:var(--s-page);color:#1F2A30;'
            . 'font-family:Vazirmatn,Vazir,IRANSans,"Segoe UI",Tahoma,system-ui,sans-serif;line-height:1.7}'
            . '.tmc-store__main{max-inline-size:60rem;margin-inline:auto;padding:16px}'
            . '.tmc-store__banner{inline-size:100%;block-size:auto;border-radius:14px;display:block}'
            . '.tmc-store__head h1{color:var(--s-ink);margin:16px 0 8px}'
            . '.tmc-store__badge{display:inline-block;margin:0 0 8px;padding:4px 12px;border-radius:999px;'
            . 'background:var(--s-green);color:var(--s-ink);font-weight:700;font-size:.9375rem}'
            . '.tmc-store__city{color:var(--s-muted);margin:0 0 16px}'
            . '.tmc-store__card{background:var(--s-surface);border:1px solid var(--s-border);'
            . 'border-radius:14px;padding:16px;margin-block-end:16px}'
            . '.tmc-store__card h2{color:var(--s-ink);margin:0 0 12px;font-size:1.125rem}'
            . '.tmc-store__card p{max-inline-size:68ch;overflow-wrap:anywhere}'
            . '.tmc-store__figures{display:grid;grid-template-columns:repeat(auto-fit,minmax(13rem,1fr));'
            . 'gap:12px;margin:0}'
            . '.tmc-store__figures dt{color:var(--s-muted);font-size:.9375rem}'
            . '.tmc-store__figures dd{margin:4px 0 0;font-weight:700;font-size:1.125rem}'
            . '.tmc-store__note{color:var(--s-muted);font-size:.9375rem}'
            . '.tmc-store__closed{background:#FFF4D6;border-radius:9px;padding:12px}'
            . '.tmc-store__products{list-style:none;margin:0;padding:0;display:grid;gap:12px}'
            // Wraps rather than scrolling: at 320px three pager items on one
            // line squeeze each other into a column of single letters, which is
            // the failure the charts already taught us to measure for.
            . '.tmc-store__pager ul{list-style:none;margin:16px 0 0;padding:0;display:flex;flex-wrap:wrap;gap:12px;align-items:center}'
            . '.tmc-store__pager a{display:inline-block;min-block-size:44px;line-height:44px;padding:0 12px}'
            . '.tmc-store__product a{color:var(--s-ink);text-underline-offset:3px;overflow-wrap:anywhere}'
            . '.tmc-store__price{color:var(--s-muted);white-space:nowrap}'
            . 'a:focus-visible{outline:3px solid var(--s-ink);outline-offset:2px}'
            . '</style>';
    }
}
