<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorShell;

/**
 * WHAT THIS PROVES: §8's header, from the markup the vendor's browser gets.
 *
 * The brief has two halves and only one of them can be tested here. «مدرن،
 * مینیمال و حرفه‌ای» is a judgement about pixels, measured in the browser and
 * recorded with screenshots; what IS a contract is everything the owner asked
 * for in words:
 *
 *  - the shop's name is clickable and opens an account menu;
 *  - that menu holds view-shop, back-to-site and log-out;
 *  - back-to-site is NOT the log-out address («بازگشت به سایت نباید باعث خروج
 *    شود»), which is a claim about two hrefs and is asserted as one;
 *  - every link, the access each one needs, the menu's active state and the
 *    RTL structure survive the redesign.
 *
 * ⛔ AND THE LAST BULLET IS WHY HALF OF THIS FILE LOOKS LIKE A REGRESSION
 * TEST. «تمام لینک‌ها، دسترسی‌ها، active state منوها و ساختار RTL حفظ شوند»
 * is a promise about things that already worked, and a redesign is exactly the
 * kind of change that keeps a page looking right while quietly dropping
 * `aria-current` or the panel's label. So those are asserted here, on the same
 * render, rather than trusted to the eye.
 */
final class VendorHeaderTest extends ContractTestCase
{
    private const NAV = [
        ['slug' => 'dashboard', 'label' => 'پیشخوان فروشنده', 'url' => 'https://example.test/vendor/'],
        ['slug' => 'products', 'label' => 'محصولات من', 'url' => 'https://example.test/vendor/products/'],
        ['slug' => 'store', 'label' => 'تنظیمات فروشگاه', 'url' => 'https://example.test/vendor/store/'],
    ];

    private const ACCOUNT = [
        'shop_url' => 'https://example.test/?tmc_store=8401',
        'site_url' => 'https://example.test/',
        'logout_url' => 'https://example.test/wp-login.php?action=logout&_wpnonce=abc123',
    ];

    // ------------------------------------------- the store name and its menu

    public function testTheStoreNameIsTheControlThatOpensTheAccountMenu(): void
    {
        $html = self::shell('داروخانهٔ مرکزی', self::ACCOUNT);

        // A `<details>` whose `<summary>` carries the name: clickable, and
        // operable with Enter and Space with no script loaded.
        self::assertStringContainsString('<details class="tv-acct" id="tv-acct">', $html);
        self::assertMatchesRegularExpression(
            '/<summary class="tv-acct__toggle">.*داروخانهٔ مرکزی.*<\/summary>/s',
            $html,
            'the shop name has to be inside the summary, not beside it'
        );
        // ⛔ AND NO `aria-label` ON THAT SUMMARY. One would replace the shop's
        // name with a generic word for a screen reader — the opposite of what
        // «نام فروشگاه در هدر قابل کلیک باشد» asks for.
        self::assertStringNotContainsString('<summary class="tv-acct__toggle" aria-label', $html);
    }

    public function testTheMenuHoldsTheThreeItemsTheOwnerNamed(): void
    {
        $html = self::shell('داروخانهٔ مرکزی', self::ACCOUNT);

        self::assertStringContainsString('مشاهده فروشگاه', $html);
        self::assertStringContainsString('بازگشت به سایت', $html);
        self::assertStringContainsString('خروج از حساب', $html);
        self::assertStringContainsString('href="https://example.test/?tmc_store=8401"', $html);
        self::assertStringContainsString('aria-label="منوی حساب"', $html);
    }

    /**
     * ⛔ THE ONE THE OWNER STATED AS A RULE: «بازگشت به سایت نباید باعث خروج
     * شود». Two different parameters, two different hrefs — and the logout one
     * is the only place WordPress's nonce-carrying address appears.
     */
    public function testGoingBackToTheSiteIsNotTheLogoutAddress(): void
    {
        $html = self::shell('داروخانهٔ مرکزی', self::ACCOUNT);

        $site = self::hrefOf($html, 'بازگشت به سایت');
        $logout = self::hrefOf($html, 'خروج از حساب');

        self::assertSame('https://example.test/', $site);
        self::assertNotSame($site, $logout, 'back to the site must not be the logout URL');
        self::assertStringContainsString('action=logout', (string) $logout);
        self::assertStringContainsString('_wpnonce=', (string) $logout, 'the standard WordPress path carries its nonce');
        self::assertStringNotContainsString('action=logout', (string) $site);
    }

    /**
     * «مشاهده فروشگاه» appears only when the caller has an address to give.
     *
     * `VendorRoutes` takes it from `StorePage::publicShop()`, which answers
     * `null` for a shop whose public page would be a 404 — the `alpha.17`
     * finding, where three addresses were listed and two were 404s. So a shell
     * handed no shop address prints no link rather than a dead one.
     */
    public function testAShopWithNoPublicPageGetsNoViewShopLink(): void
    {
        $html = self::shell('داروخانهٔ مرکزی', [
            'site_url' => 'https://example.test/',
            'logout_url' => 'https://example.test/wp-login.php?action=logout&_wpnonce=abc123',
        ]);

        self::assertStringNotContainsString('مشاهده فروشگاه', $html);
        // …and the other two are still there: one missing address does not
        // take the menu away.
        self::assertStringContainsString('بازگشت به سایت', $html);
        self::assertStringContainsString('خروج از حساب', $html);
    }

    /**
     * No addresses at all → the pre-§8 label, and no control.
     *
     * A control that opens an empty menu teaches the vendor that clicking
     * their shop's name does nothing, which is worse than a label.
     */
    public function testAShellWithNoAccountAddressesPrintsThePlainLabel(): void
    {
        $html = self::shell('داروخانهٔ مرکزی', []);

        self::assertStringContainsString('<p class="tv-head__store">داروخانهٔ مرکزی</p>', $html);
        self::assertStringNotContainsString('tv-acct', $html);
    }

    /**
     * The invitation page renders this shell for somebody who is NOT logged
     * in and has no shop. An account menu there would offer to log out a
     * visitor who never logged in.
     */
    public function testThePageWithNoShopOffersNoAccountMenuAndNoLogout(): void
    {
        $html = self::shell('', self::ACCOUNT);

        self::assertStringNotContainsString('tv-acct', $html);
        self::assertStringNotContainsString('tv-head__store', $html);
        self::assertStringNotContainsString('خروج از حساب', $html);
        self::assertStringNotContainsString('action=logout', $html);
    }

    public function testTheShopNameIsEscaped(): void
    {
        $html = self::shell('<b>داروخانه</b>', self::ACCOUNT);

        self::assertStringNotContainsString('<b>داروخانه</b>', $html);
        self::assertStringContainsString('&lt;b&gt;داروخانه&lt;/b&gt;', $html);
    }

    // ------------------------------------------------- what must not change

    public function testTheNavigationKeepsEveryLinkAndItsActiveState(): void
    {
        $html = self::shell('داروخانهٔ مرکزی', self::ACCOUNT, 'products');

        foreach (self::NAV as $item) {
            self::assertStringContainsString('href="' . $item['url'] . '"', $html, $item['slug'] . ' lost its link');
            self::assertStringContainsString($item['label'], $html);
        }
        // The active item: BOTH halves. `is-current` is what the stylesheet
        // paints and `aria-current` is what a screen reader reads, and a
        // redesign that kept only the first would look right and say nothing.
        self::assertMatchesRegularExpression(
            '/class="tv-nav__link is-current" href="https:\/\/example\.test\/vendor\/products\/" aria-current="page"/',
            $html
        );
        self::assertSame(1, substr_count($html, 'aria-current="page"'), 'exactly one current item');
        self::assertStringContainsString('<nav class="tv-nav__panel" aria-label="بخش‌های پنل فروشنده">', $html);
        // The collapsed toggle still says where you are.
        self::assertStringContainsString('<span class="tv-nav__toggle-current">محصولات من</span>', $html);
    }

    public function testTheShellKeepsItsRtlStructureAndItsSkipLink(): void
    {
        $html = self::shell('داروخانهٔ مرکزی', self::ACCOUNT);

        self::assertStringContainsString('<html lang="fa" dir="rtl">', $html);
        self::assertStringContainsString('<a class="tv-skip" href="#tv-main">', $html);
        self::assertStringContainsString('<main id="tv-main" class="tv-main" tabindex="-1">', $html);
        self::assertStringContainsString('name="robots" content="noindex, nofollow"', $html);
        // The classes the browser checks and the stylesheet both depend on.
        self::assertStringContainsString('<header class="tv-head">', $html);
        self::assertStringContainsString('<details class="tv-nav" id="tv-nav">', $html);
    }

    /**
     * The menu needs no script, which is the whole reason it is a `<details>`.
     *
     * A vendor whose JavaScript is off must still be able to log out, so this
     * asserts that the header's markup carries no handler of its own — the
     * only `<script>` in the page is the one a caller asked for.
     */
    public function testTheHeaderCarriesNoInlineScriptOfItsOwn(): void
    {
        $html = self::shell('داروخانهٔ مرکزی', self::ACCOUNT);

        $header = self::between($html, '<header class="tv-head">', '</header>');
        self::assertNotSame('', $header);
        self::assertStringNotContainsString('<script', $header);
        self::assertStringNotContainsString('onclick', $header);
    }

    // ------------------------------------------------------------- helpers

    /** @param array<string,string> $account */
    private static function shell(string $storeName, array $account, string $current = 'dashboard'): string
    {
        return VendorShell::render(
            'پیشخوان فروشنده',
            $current,
            self::NAV,
            $storeName,
            '<p>بدنه</p>',
            'https://example.test/wp-content/plugins/tmc/assets/vendor/tmc-vendor.css',
            '0.1.0',
            [],
            $account
        );
    }

    private static function hrefOf(string $html, string $label): ?string
    {
        // The anchor whose TEXT is this label — not the first `href` after the
        // word, which would answer about whichever link came next if the
        // label moved outside an anchor (`alpha.33`).
        return preg_match('/<a[^>]*href="([^"]+)"[^>]*>' . preg_quote($label, '/') . '<\/a>/u', $html, $m) === 1
            ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')
            : null;
    }

    private static function between(string $html, string $open, string $close): string
    {
        $start = strpos($html, $open);
        if ($start === false) {
            return '';
        }
        $end = strpos($html, $close, $start);
        return $end === false ? '' : substr($html, $start, $end - $start + strlen($close));
    }
}
