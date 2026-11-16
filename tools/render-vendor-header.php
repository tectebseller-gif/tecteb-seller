<?php
/**
 * Renders §8's vendor header outside WordPress, so a real browser can measure
 * it — DEVELOPMENT ONLY, NOT part of the plugin or the ZIP.
 *
 * WHAT THE OUTPUT IS: the real `VendorShell` markup with the real
 * `assets/vendor/tmc-vendor.css`, written to a file a browser can open. Every
 * claim in §8 that is a MEASUREMENT — «هدر کم‌ارتفاع‌تر», «بدون ازدحام یا
 * شکست نامناسب خطوط», «واکنش‌گرایی درست در دسکتاپ، تبلت و موبایل» — is a
 * question about pixels and can only be answered by a layout engine.
 *
 * ⛔ WHAT IT IS NOT: evidence that the panel works on the owner's WordPress.
 * Installing the package, the real theme, a persistent object cache and
 * wp-admin all stay `Not Run`. This shell is a standalone document — it loads
 * no theme and no admin stylesheet, which is exactly WHY it can be measured
 * this way and exactly why doing so proves nothing about anything else.
 *
 * The three account addresses are the shapes `VendorRoutes::accountFor()`
 * produces, written out here rather than built: this file must not call
 * WordPress for them any more than the shell may.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/Core/Autoloader.php';
Tecteb\Marketplace\Core\Autoloader::register($root . '/src');
require $root . '/tools/wp-stubs/load.php';

use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorShell;

// ⛔ ABSOLUTE URLS, AND A SERVER — NOT `file://`.
//
// Measured: the first run of this harness used `./tmc-vendor.css` and
// `./site.html`, and BOTH were wrong in ways that looked like working pages.
// `esc_url()` rejects a protocol-less path that starts with a dot — real
// WordPress does too — so every account link rendered `href=""`; and over
// `file://` a browser treats the `?ver=` query string the shell appends as
// part of the FILENAME, so the stylesheet 404s and the page measures as
// unstyled while still answering «no horizontal scroll» and «no label
// breaks». Both failures passed nine other checks in that run.
//
// So the pages are written with absolute `http://` addresses and served by
// `php -S`, which keeps the markup byte-identical to what WordPress emits —
// `?ver=` and all.
//
// ⛔ AND THE BASE IS REQUIRED, NOT DEFAULTED. A literal loopback address in
// this file reads to `DisposableOnlyToolsTest` as «a host check with no
// database check» — a guard half-built — and the honest answer is not to add
// a database check this tool has no use for but to carry no host literal at
// all. The caller says where the pages will be served from; `php -S` and the
// browser script agree on it, and a missing value is a loud refusal rather
// than a page full of addresses that resolve to nothing.
$base = rtrim((string) getenv('TMC_HEADER_BASE'), '/');
if ($base === '') {
    fwrite(STDERR, "TMC_HEADER_BASE is required, e.g. TMC_HEADER_BASE=http://[::1]:8099\n");
    exit(2);
}
$outDir = $root . '/docs/evidence/alpha107-header';
if (!is_dir($outDir)) {
    mkdir($outDir, 0o755, true);
}
// The stylesheet beside the page, so `file://` resolves it without a server.
copy($root . '/assets/vendor/tmc-vendor.css', $outDir . '/tmc-vendor.css');
copy($root . '/assets/vendor/tmc-vendor.js', $outDir . '/tmc-vendor.js');

// The ten sections the owner's own panel shows — this is the list that was
// wrapping, so the measurement has to be made on all ten and not on three.
$nav = [
    ['slug' => 'dashboard', 'label' => 'پیشخوان فروشنده', 'url' => $base . '/dashboard.html'],
    ['slug' => 'application', 'label' => 'پروندهٔ فروشندگی و مدارک', 'url' => $base . '/application.html'],
    ['slug' => 'products', 'label' => 'محصولات من', 'url' => $base . '/products.html'],
    ['slug' => 'orders', 'label' => 'سفارش‌ها', 'url' => $base . '/orders.html'],
    ['slug' => 'shipments', 'label' => 'ارسال و مرجوعی', 'url' => $base . '/shipments.html'],
    ['slug' => 'finance', 'label' => 'مالی و تسویه', 'url' => $base . '/finance.html'],
    ['slug' => 'reports', 'label' => 'گزارش‌ها', 'url' => $base . '/reports.html'],
    ['slug' => 'store', 'label' => 'تنظیمات فروشگاه', 'url' => $base . '/store.html'],
    ['slug' => 'staff', 'label' => 'پرسنل فروشگاه', 'url' => $base . '/staff.html'],
    ['slug' => 'support', 'label' => 'پشتیبانی و گفتگو با مدیریت', 'url' => $base . '/support.html'],
];

$body = '<section class="tv-card"><h2 class="tv-card__title">نخستین کارت صفحه</h2>'
    . '<p>این بلوک فقط برای اندازه‌گیری است: فاصلهٔ بالای صفحه تا نخستین خط محتوا.</p>'
    . '</section>';

$cases = [
    'with-account' => [
        'store' => 'داروخانه و تجهیزات پزشکی مرکزی تهران',
        'account' => [
            'shop_url' => $base . '/shop.html',
            'site_url' => $base . '/site.html',
            'logout_url' => $base . '/logout.html?action=logout&_wpnonce=stub',
        ],
    ],
    // A shop with no public page: the «مشاهده فروشگاه» link is absent, which
    // is `StorePage::publicShop()` answering `null` rather than a dead link.
    'no-shop-page' => [
        'store' => 'فروشگاه بدون صفحهٔ عمومی',
        'account' => [
            'site_url' => $base . '/site.html',
            'logout_url' => $base . '/logout.html?action=logout&_wpnonce=stub',
        ],
    ],
    // The invitation page: not logged in, no shop, no account menu.
    'no-store' => ['store' => '', 'account' => []],
];

// ⛔ THE ARGUMENT LIST IS BUILT BY REFLECTION, so this one file renders the
// header on the tree BEFORE §8 as well as after it. A harness that passes the
// account array unconditionally fatals on the older shell, which takes eight
// parameters — and then «the before could not be measured» looks exactly like
// «the before had no header» (`alpha.55`, where a new interface method stopped
// the previous round's probe from loading at all).
$arity = (new ReflectionMethod(VendorShell::class, 'render'))->getNumberOfParameters();

foreach ($cases as $name => $case) {
    $args = [
        'پیشخوان فروشنده',
        'products',
        $nav,
        $case['store'],
        $body,
        $base . '/tmc-vendor.css',
        '0.1.0-dev',
        [$base . '/tmc-vendor.js'],
    ];
    if ($arity >= 9) {
        $args[] = $case['account'];
    }
    $html = VendorShell::render(...$args);
    file_put_contents($outDir . '/' . $name . '.html', $html);
    echo $name, ': ', strlen($html), " bytes\n";
}
echo 'render_arity=', $arity, PHP_EOL;
echo "out: ", $outDir, "\n";
