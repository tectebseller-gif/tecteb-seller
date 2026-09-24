<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;

/**
 * «داشبورد فروشنده» where a vendor actually goes looking for it: /my-account/.
 *
 * Until now the panel had no entry point at all from the shop side. A vendor
 * who had not bookmarked `/vendor/` had to be told the address, and the owner
 * had to paste a staging URL into a message — which is exactly how a hardcoded
 * host ends up in a plugin.
 *
 * Three things this deliberately does NOT do:
 *
 *  - **It does not build the address.** It asks `VendorRoutes` for it, which
 *    builds from `home_url()` and already knows whether the site has pretty
 *    permalinks. So the link is right on any domain, including the day the
 *    site moves.
 *  - **It does not guard anything.** Hiding a link is not access control:
 *    `/vendor/` itself refuses anyone without a store, and that refusal is
 *    what this can be tested against. The visibility test here exists so the
 *    menu is not cluttered for buyers, and for no other reason.
 *  - **It does not require WooCommerce's stock template.** The menu filter
 *    covers themes that render the standard navigation; the dashboard hook
 *    covers a custom account page that does not. A site using neither still
 *    has the address, because the address is not the access.
 */
final class VendorAccountLink
{
    /** The key WooCommerce uses to identify our row in its own menu array. */
    public const MENU_KEY = 'tmc-vendor-dashboard';

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public static function register(ContainerInterface $container): void
    {
        $link = new self($container);
        add_filter('woocommerce_account_menu_items', [$link, 'addMenuItem'], 20);
        add_filter('woocommerce_get_endpoint_url', [$link, 'endpointUrl'], 20, 4);
        add_action('woocommerce_account_dashboard', [$link, 'renderPanel'], 5);
    }

    /**
     * @param array<string,string> $items
     * @return array<string,string>
     */
    public function addMenuItem(array $items): array
    {
        if (!$this->actsInAStore()) {
            return $items;
        }
        // Inserted before «خروج», because a menu whose last item is the way
        // out is a menu people stop reading one line early.
        $logout = $items['customer-logout'] ?? null;
        unset($items['customer-logout']);
        $items[self::MENU_KEY] = __('داشبورد فروشنده', 'tecteb-marketplace-core');
        if ($logout !== null) {
            $items['customer-logout'] = $logout;
        }
        return $items;
    }

    /**
     * WooCommerce would otherwise build `/my-account/tmc-vendor-dashboard/`
     * for our row, which is not an endpoint and would 404.
     */
    public function endpointUrl(string $url, string $endpoint, string $value, string $permalink): string
    {
        return $endpoint === self::MENU_KEY ? $this->dashboardUrl() : $url;
    }

    /**
     * A panel on the account dashboard, for the themes that replace the menu.
     *
     * The owner's site has a custom account page; a link that only exists
     * inside WooCommerce's stock navigation would be a link that only exists
     * on a site nobody has.
     */
    public function renderPanel(): void
    {
        if (!$this->actsInAStore()) {
            return;
        }
        echo '<p class="tmc-account-vendor"><a class="button" href="'
            . esc_url($this->dashboardUrl()) . '">'
            . esc_html__('داشبورد فروشنده', 'tecteb-marketplace-core') . '</a> '
            . esc_html__('مدیریت محصول‌ها، سفارش‌ها و تنظیمات فروشگاه شما.', 'tecteb-marketplace-core')
            . '</p>';
    }

    /**
     * Built from the plugin's own route, never from a string in this file.
     *
     * `VendorRoutes::dashboardUrl()` is the single place that knows both the
     * path and whether this site uses pretty permalinks.
     */
    private function dashboardUrl(): string
    {
        return VendorRoutes::dashboardUrl();
    }

    /**
     * «Acts in a store» rather than «owns one»: a staff member with product
     * rights belongs in the panel too, and the panel itself decides what they
     * may see once they are there.
     */
    private function actsInAStore(): bool
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return false;
        }
        return $this->container->get(StaffAccess::class)->storeFor($userId) !== null;
    }
}
