<?php
/**
 * A minimal Persian pack for the shop strings the demo actually shows.
 *
 * WooCommerce's own fa_IR translation lives on translate.wordpress.org, which
 * this box cannot reach — so a fresh install renders its storefront in
 * English no matter what `WPLANG` says. That is a property of the ENVIRONMENT,
 * not of this plugin: our own surfaces are Persian because the strings are
 * Persian in the source.
 *
 * Rather than hand the owner an English cart and call it a demo, this writes
 * a small `.mo` for the handful of classic-template strings a shopper meets:
 * the shop archive, the product page, the cart, the checkout and My account.
 * It is deliberately small and deliberately labelled — «بسته ترجمه حداقلی
 * آزمایشی», the same phrase the wp-admin pack has carried since phase 1 —
 * because a partial translation presented as the real one is worse than
 * English: somebody eventually ships it.
 *
 * No gettext tooling on this host, so the `.mo` is assembled here. The format
 * is a header, two offset tables and two string blocks; `msgfmt` is not
 * required to produce one, only to produce one carelessly.
 *
 *   php tools/demo-translations.php <languages-dir>
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(2);
}

$dir = $argv[1] ?? '';
if ($dir === '') {
    fwrite(STDERR, "usage: php tools/demo-translations.php <languages-dir>\n");
    exit(2);
}

/**
 * The strings, in the order a shopper meets them. Anything not listed falls
 * back to English, visibly — which is the honest outcome for a pack this size.
 */
$strings = [
    // shop archive and product
    'Add to cart' => 'افزودن به سبد',
    'View cart' => 'مشاهده سبد',
    'Sale!' => 'حراج!',
    'Out of stock' => 'ناموجود',
    'In stock' => 'موجود',
    'Description' => 'توضیحات',
    'Additional information' => 'اطلاعات بیشتر',
    'Related products' => 'محصولات مرتبط',
    'Shop' => 'فروشگاه',
    'Search results' => 'نتیجهٔ جست‌وجو',
    'Showing all %d results' => 'نمایش همهٔ %d نتیجه',
    'Default sorting' => 'مرتب‌سازی پیش‌فرض',
    'Sort by popularity' => 'پرفروش‌ترین',
    'Sort by average rating' => 'بیشترین امتیاز',
    'Sort by latest' => 'تازه‌ترین',
    'Sort by price: low to high' => 'قیمت: کم به زیاد',
    'Sort by price: high to low' => 'قیمت: زیاد به کم',
    'SKU:' => 'کد کالا:',
    'Category:' => 'دسته:',
    'Categories:' => 'دسته‌ها:',
    'Quantity' => 'تعداد',
    // cart
    'Cart' => 'سبد خرید',
    'Product' => 'کالا',
    'Price' => 'قیمت',
    'Subtotal' => 'جمع جزء',
    'Total' => 'مجموع',
    'Cart totals' => 'جمع سبد',
    'Update cart' => 'به‌روزرسانی سبد',
    'Proceed to checkout' => 'ادامه و پرداخت',
    'Remove this item' => 'حذف این کالا',
    'Your cart is currently empty.' => 'سبد خرید شما خالی است.',
    'Return to shop' => 'بازگشت به فروشگاه',
    'Coupon code' => 'کد تخفیف',
    'Apply coupon' => 'اعمال کد تخفیف',
    // checkout
    'Checkout' => 'تکمیل خرید',
    'Billing details' => 'مشخصات خریدار',
    'Additional information' . "\0" => 'اطلاعات بیشتر',
    'Your order' => 'سفارش شما',
    'Place order' => 'ثبت سفارش',
    'First name' => 'نام',
    'Last name' => 'نام خانوادگی',
    'Country / Region' => 'کشور / منطقه',
    'Street address' => 'نشانی',
    'Town / City' => 'شهر',
    'Postcode / ZIP' => 'کد پستی',
    'Phone' => 'شمارهٔ تماس',
    'Email address' => 'نشانی ایمیل',
    'Order notes' => 'یادداشت سفارش',
    'Have a coupon?' => 'کد تخفیف دارید؟',
    // thank you + account
    'Thank you. Your order has been received.' => 'سپاسگزاریم. سفارش شما ثبت شد.',
    'Order number:' => 'شمارهٔ سفارش:',
    'Date:' => 'تاریخ:',
    'Payment method:' => 'روش پرداخت:',
    'Order details' => 'جزئیات سفارش',
    'My account' => 'حساب من',
    'Orders' => 'سفارش‌ها',
    'Dashboard' => 'پیشخوان',
    'Addresses' => 'نشانی‌ها',
    'Account details' => 'جزئیات حساب',
    'Logout' => 'خروج',
    'Order' => 'سفارش',
    'Date' => 'تاریخ',
    'Status' => 'وضعیت',
    'Actions' => 'اقدام',
    'View' => 'مشاهده',
    'No order has been made yet.' => 'هنوز سفارشی ثبت نشده است.',
    'Browse products' => 'دیدن محصولات',

    // my-account login form — the first buyer screen on a fresh install
    'Login' => 'ورود',
    'Register' => 'ثبت‌نام',
    'Username or email address' => 'نام کاربری یا نشانی ایمیل',
    'Username' => 'نام کاربری',
    'Password' => 'گذرواژه',
    'Remember me' => 'مرا به خاطر بسپار',
    'Log in' => 'ورود',
    'Lost your password?' => 'گذرواژه را فراموش کرده‌اید؟',
    'required' => 'الزامی',
    'Required' => 'الزامی',

    // the add-to-cart button's own aria-label and its success message
    'Add to cart: &ldquo;%s&rdquo;' => 'افزودن به سبد: «%s»',
    '&ldquo;%s&rdquo; has been added to your cart.' => '«%s» به سبد شما افزوده شد.',
    '%s has been added to your cart.' => '%s به سبد شما افزوده شد.',

    // A PLURAL entry is ONE msgid whose singular and plural are separated by a
    // NUL, and whose translations are separated the same way; a CONTEXT is a
    // prefix joined with \x04. Written as two ordinary entries — which is what
    // the first version of this pack did — `_n()` finds neither, and the
    // archive keeps saying «Showing all 3 results» in English beside a page
    // that is otherwise entirely Persian.
    //
    // The msgids are copied from `templates/loop/result-count.php`, not
    // guessed: the guessed pair («Showing all %d results») is not a string
    // WooCommerce has, so it matched nothing at all.
    'Showing all %1$d result' . "\0" . 'Showing all %1$d results'
        => 'نمایش %1$d نتیجه' . "\0" . 'نمایش همهٔ %1$d نتیجه',
    'with first and last result' . "\4" . 'Showing %1$d&ndash;%2$d of %3$d result'
        . "\0" . 'Showing %1$d&ndash;%2$d of %3$d results'
        => 'نمایش %1$d تا %2$d از %3$d نتیجه' . "\0" . 'نمایش %1$d تا %2$d از %3$d نتیجه',
];

/**
 * The theme's own strings.
 *
 * «Skip to content» and «Proudly powered by WordPress» are not WooCommerce's —
 * they belong to the active theme's text domain, and a pack written only for
 * WooCommerce leaves them in English on every single page. Two strings, so
 * the buyer's surface has no English left in it at all.
 */
$themeStrings = [
    'Skip to content' => 'رفتن به محتوا',
    'Proudly powered by %s.' => 'با افتخار، نیروگرفته از %s.',
    'Proudly powered by %s' => 'با افتخار، نیروگرفته از %s',
    'Powered by %s' => 'نیروگرفته از %s',
    'Menu' => 'منو',
    'Close' => 'بستن',
    'Search' => 'جست‌وجو',
    'Page' => 'صفحه',
];

/**
 * A `.mo` file: magic, revision, two counts, two table offsets, a hash-table
 * slot we leave empty (the format allows it), then the tables and blocks.
 * Entries must be sorted by original string — the reader binary-searches.
 */
function tmc_write_mo(string $path, array $pairs): int
{
    ksort($pairs, SORT_STRING);
    $originals = array_keys($pairs);
    $count = count($originals);

    $origBlock = '';
    $transBlock = '';
    $origTable = [];
    $transTable = [];
    foreach ($originals as $key) {
        $origTable[] = [strlen($key), strlen($origBlock)];
        $origBlock .= $key . "\0";
        $value = (string) $pairs[$key];
        $transTable[] = [strlen($value), strlen($transBlock)];
        $transBlock .= $value . "\0";
    }

    $header = 28;                       // 7 × uint32
    $origTableOffset = $header;
    $transTableOffset = $origTableOffset + $count * 8;
    $origBlockOffset = $transTableOffset + $count * 8;
    $transBlockOffset = $origBlockOffset + strlen($origBlock);

    $out = pack('V*', 0x950412de, 0, $count, $origTableOffset, $transTableOffset, 0, 0);
    foreach ($origTable as [$len, $off]) {
        $out .= pack('VV', $len, $origBlockOffset + $off);
    }
    foreach ($transTable as [$len, $off]) {
        $out .= pack('VV', $len, $transBlockOffset + $off);
    }
    $out .= $origBlock . $transBlock;

    return (int) file_put_contents($path, $out);
}

if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, "cannot create {$dir}\n");
    exit(1);
}

// The empty msgid carries the metadata header every reader expects.
$strings[''] = "Project-Id-Version: WooCommerce demo (minimal)\nLanguage: fa_IR\n"
    . "MIME-Version: 1.0\nContent-Type: text/plain; charset=UTF-8\n"
    . "Content-Transfer-Encoding: 8bit\nPlural-Forms: nplurals=2; plural=(n > 1);\n";

$themeStrings[''] = $strings[''];

// The argument is the languages ROOT: WordPress looks for a plugin pack under
// `plugins/` and a theme pack under `themes/`, and a file in the wrong one of
// the two is simply never read — which looks exactly like a pack that does
// not translate anything.
$targets = [
    'plugins/woocommerce-fa_IR.mo' => $strings,
    'themes/twentytwentyone-fa_IR.mo' => $themeStrings,
];
$root = rtrim($dir, '/');
foreach ($targets as $name => $pairs) {
    $path = $root . '/' . $name;
    $parent = dirname($path);
    if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
        fwrite(STDERR, "cannot create {$parent}\n");
        exit(1);
    }
    $bytes = tmc_write_mo($path, $pairs);
    printf("wrote %s (%d bytes, %d strings)\n", $path, $bytes, count($pairs) - 1);
}
