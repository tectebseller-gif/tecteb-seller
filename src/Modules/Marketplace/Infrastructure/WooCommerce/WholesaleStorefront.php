<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Marketplace\Application\ManageWholesale;
use Tecteb\Marketplace\Modules\Marketplace\Presentation\MarketplaceMessages;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;

/**
 * The buyer's half of B2B: where a customer asks to buy wholesale, and where
 * an approved one actually sees the ladder.
 *
 * Until this existed, `ManageWholesale::apply()` had no screen at all and
 * `tiersFor()` was never shown to the person whose price it is. The service
 * was «ساخته‌شده» and unreachable, which is precisely the difference the owner
 * asked to be reported: «وضعیت رابط و اتصال به مسیر واقعی خرید را جدا از وجود
 * سرویس‌ها گزارش کن».
 *
 * **Who sees the ladder is the approved rule, not a preference.** «خریدار عمده
 * پس از تأیید مدیر قیمت پلکانی و حداقل تعداد را می‌بیند» — so an approved buyer
 * sees every step with its price, and everybody else sees one sentence saying
 * wholesale pricing exists for this product and how to ask for it. Printing the
 * whole price list publicly would be a different product than the one approved.
 *
 * **Where it appears needs no rewrite rules.** The application form is hooked
 * onto WooCommerce's own «حساب من» dashboard and the ladder onto the product
 * summary, so installing this plugin adds no endpoint, flushes no permalinks
 * and cannot leave a site with a 404 after deactivation.
 *
 * Nothing here is shown for a product that is not the marketplace's: the
 * shop's own goods and Dokan's vendors' are looked at once, to ask whose they
 * are, and then left alone.
 */
final class WholesaleStorefront
{
    public const ACTION = 'tmc_wholesale_apply';
    public const NONCE_FIELD = 'tmc_wholesale_nonce';

    /** Where the result of an application is read back after the redirect. */
    public const NOTICE_ARG = 'tmc_wholesale';

    public static function register(ContainerInterface $container): void
    {
        // The form's own handler, before anything is rendered, so the answer
        // arrives as a redirect rather than as a re-postable page.
        add_action('template_redirect', static function () use ($container): void {
            self::handle($container);
        }, 5);

        add_action('woocommerce_account_dashboard', static function () use ($container): void {
            echo self::accountSection($container);       // phpcs:ignore WordPress.Security.EscapeOutput
        }, 20);

        add_action('woocommerce_single_product_summary', static function () use ($container): void {
            echo self::productLadder($container);        // phpcs:ignore WordPress.Security.EscapeOutput
        }, 25);
    }

    /**
     * Takes the application, once, and redirects.
     *
     * The capability question is «is this a logged-in customer», and nothing
     * more: asking to buy wholesale is a request, and `apply()` records it as
     * `requested` — the manager's screen is the only place it becomes an
     * approval.
     */
    private static function handle(ContainerInterface $container): void
    {
        $request = Request::capture();
        if (!$request->isPost() || $request->postKey('tmc_action') !== self::ACTION) {
            return;
        }
        if (!$request->nonceOk(self::NONCE_FIELD, self::ACTION)) {
            return;
        }
        $userId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($userId <= 0) {
            return;
        }
        $result = $container->get(ManageWholesale::class)->apply(
            $userId,
            $request->postText('wholesale_company'),
            $request->postText('wholesale_registration'),
            $request->postTextarea('wholesale_note')
        );
        $back = function_exists('wc_get_account_endpoint_url')
            ? wc_get_account_endpoint_url('dashboard')
            : home_url('/');
        wp_safe_redirect(add_query_arg(self::NOTICE_ARG, rawurlencode($result->code), $back));
        exit;
    }

    /** «حساب من» — this buyer's wholesale standing, and the way to ask for it. */
    private static function accountSection(ContainerInterface $container): string
    {
        $userId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($userId <= 0) {
            return '';
        }
        $wholesale = $container->get(ManageWholesale::class);
        $account = $wholesale->accountFor($userId);
        $html = '<section class="tmc-wholesale"><h2>'
            . esc_html__('خرید عمده', 'tecteb-marketplace-core') . '</h2>';

        $notice = Request::capture()->queryText(self::NOTICE_ARG);
        if ($notice !== '') {
            $html .= '<p class="woocommerce-info" role="status">'
                . esc_html(MarketplaceMessages::notice($notice) ?? $notice) . '</p>';
        }

        // ONE short sentence about this buyer's own standing, and the next
        // step. `alpha.56` printed the status, then a second paragraph about
        // where the ladder appears, then the MANAGER'S whole undecided-terms
        // warning with DEC-04 and DEC-05 in it — a wall of prose on the page
        // where somebody wanted to know whether they could buy wholesale.
        // «پیام‌های کاربر کوتاه باشد و وضعیت و اقدام بعدی را بگوید» (§3).
        //
        // Nothing was decided to make it shorter and no gate was opened: the
        // tariff is still the owner's, the vendor still writes every tier by
        // hand, and the manager's own page still says all of it.
        if ($account !== null) {
            return $html
                . '<p>' . esc_html(MarketplaceMessages::wholesaleStanding($account->status)) . '</p>'
                . '</section>';
        }

        return $html
            . '<p>' . esc_html(MarketplaceMessages::wholesaleStanding(null)) . '</p>'
            . '<form method="post" class="woocommerce-form">'
            . wp_nonce_field(self::ACTION, self::NONCE_FIELD, true, false)
            . '<input type="hidden" name="tmc_action" value="' . esc_attr(self::ACTION) . '">'
            . '<p class="woocommerce-form-row"><label for="tmc-wholesale-company">'
            . esc_html__('نام کسب‌وکار', 'tecteb-marketplace-core')
            . '</label><input type="text" id="tmc-wholesale-company" name="wholesale_company" class="woocommerce-Input input-text" required></p>'
            . '<p class="woocommerce-form-row"><label for="tmc-wholesale-registration">'
            . esc_html__('شناسهٔ ثبتی یا کد اقتصادی', 'tecteb-marketplace-core')
            . '</label><input type="text" id="tmc-wholesale-registration" name="wholesale_registration" class="woocommerce-Input input-text" dir="ltr"></p>'
            . '<p class="woocommerce-form-row"><label for="tmc-wholesale-note">'
            . esc_html__('توضیح (اختیاری)', 'tecteb-marketplace-core')
            . '</label><textarea id="tmc-wholesale-note" name="wholesale_note" class="woocommerce-Input input-text" rows="3"></textarea></p>'
            . '<p><button type="submit" class="button">'
            . esc_html__('ثبت درخواست خرید عمده', 'tecteb-marketplace-core')
            . '</button></p></form></section>';
    }

    /**
     * The ladder, on the product page, for the buyer entitled to it.
     *
     * A product with no ladder prints nothing at all — an empty «قیمت عمده»
     * heading on every product in the shop would be noise, and on somebody
     * else's product it would be a lie.
     */
    private static function productLadder(ContainerInterface $container): string
    {
        if (!function_exists('get_the_ID')) {
            return '';
        }
        $wcProductId = (int) get_the_ID();
        if ($wcProductId <= 0) {
            return '';
        }
        $ours = $container->get(ProductRepositoryInterface::class)->findByWcProduct($wcProductId);
        if ($ours === null) {
            return '';      // not the marketplace's product; not our business
        }
        $wholesale = $container->get(ManageWholesale::class);
        $tiers = $wholesale->tiersFor($ours->id);
        if ($tiers === []) {
            return '';
        }
        $userId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($userId <= 0 || !$wholesale->mayBuyWholesale($userId)) {
            // The approved rule is that an approved buyer sees the ladder. The
            // existence of one is not a secret; its prices are.
            return '<p class="tmc-wholesale-hint">'
                . esc_html__('این محصول قیمت عمده دارد. برای دیدن پلکان قیمت، از «حساب من» درخواست خرید عمده ثبت کنید.', 'tecteb-marketplace-core')
                . '</p>';
        }

        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);
        $html = '<div class="tmc-wholesale-ladder"><h3>'
            . esc_html__('قیمت عمده برای شما', 'tecteb-marketplace-core') . '</h3>'
            . '<table><caption class="screen-reader-text">'
            . esc_html__('پلکان قیمت عمدهٔ این محصول', 'tecteb-marketplace-core') . '</caption>'
            . '<thead><tr><th scope="col">' . esc_html__('از این تعداد به بالا', 'tecteb-marketplace-core')
            . '</th><th scope="col">' . esc_html__('قیمت هر واحد', 'tecteb-marketplace-core')
            . '</th></tr></thead><tbody>';
        foreach ($tiers as $tier) {
            $html .= '<tr><td>' . esc_html($fa((string) $tier->minQuantity)) . '</td>'
                . '<td>' . esc_html($fa(number_format($tier->unitPriceMinor))) . ' '
                . esc_html__('تومان', 'tecteb-marketplace-core') . '</td></tr>';
        }
        return $html . '</tbody></table><p class="tmc-wholesale-hint">'
            . esc_html__('همین قیمت در سبد خرید هم حساب می‌شود؛ لازم نیست کاری بکنید.', 'tecteb-marketplace-core')
            . '</p></div>';
    }

    // `openTerms()` and `statusSentence()` were here until `alpha.57`. Both
    // moved into `MarketplaceMessages::wholesaleStanding()`, which answers
    // the buyer's question in one line for every state including «no request
    // yet» — a state the old pair could not express, which is why the page
    // needed a second paragraph beside them.
}
