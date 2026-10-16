<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Presentation\Admin;

use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Migration\Domain\CatalogueFacets;

/**
 * RETIRED in `alpha.56`: «ورود محصولات موجود» is now part of «بررسی محصولات».
 *
 * **Why it went.** `alpha.55` gave this screen its own menu entry, and it
 * listed WooCommerce posts while the review page listed marketplace rows. A
 * product that existed on both sides appeared on both screens with nothing on
 * either saying it was one product, and the only way to find out was to open
 * it. The instruction for this round is one list — «هر محصول مرتبط فقط یک
 * بار» — with the intake folded into it, so the two lists became one and this
 * page has nothing left to draw.
 *
 * **What is left here, and why it is not nothing.** Two constants and a
 * redirect:
 *
 *   * `CAPABILITY` is still the permission that decides who may take a
 *     product into the marketplace. It did not move to the review page
 *     because it is not a review permission: taking somebody's product into
 *     this marketplace is a vendor decision, and `REVIEW_PRODUCTS` is not
 *     that. `ProductReviewPage` asks for this one before it draws a single
 *     checkbox and again before it accepts a single POST.
 *   * the old address still works. A manager who bookmarked it, or an
 *     instruction in `docs/` that names it, must not land on «Sorry, you are
 *     not allowed to access this page» — which is what WordPress answers for
 *     a `page=` slug nothing registered. So the slug is still recognised and
 *     redirected, with the filter that comes closest to what this page used
 *     to show: the WooCommerce products with no marketplace row.
 *
 * **The redirect runs before the menu exists, and that is deliberate.**
 * `admin_init` is early enough that nothing has drawn a page yet, and the
 * capability is checked first: a user who may not see either screen gets
 * WordPress's own refusal rather than a redirect that tells them where the
 * thing they may not see has moved to.
 */
final class ProductIntakePage
{
    /** The old address, kept so it can be redirected rather than refused. */
    public const SLUG = 'tmc-product-intake';

    /**
     * Who may take an existing product into the marketplace.
     *
     * `REVIEW_VENDOR` and not `REVIEW_PRODUCTS`: an intake decides that a
     * product belongs to a shop, which is a question about vendors.
     */
    public const CAPABILITY = Capabilities::REVIEW_VENDOR;

    /**
     * Send the old address to the one list.
     *
     * Registered by `MigrationModule`; a no-op on every request that is not
     * asking for this page.
     *
     * @param string $destinationSlug the review page's slug, passed in rather
     *        than imported so this Migration-module file does not reach into
     *        the Product module for a constant
     */
    public static function redirectOldAddress(string $destinationSlug): void
    {
        // Through `Request`, like everything else in this plugin: it is the
        // one reader of a superglobal and an architecture test proves it
        // (`SecurityRulesTest`). A redirect is no excuse for a second one.
        if (Request::capture()->queryKey('page') !== self::SLUG) {
            return;
        }
        if (!current_user_can(self::CAPABILITY)) {
            // Not our refusal to give: WordPress's own screen says it, in the
            // words the rest of wp-admin uses.
            return;
        }
        $target = add_query_arg(
            [
                'page' => $destinationSlug,
                // What this page used to list, said as a filter of the new
                // one. Not «everything»: a manager following an old link was
                // looking for the products that are not in the marketplace
                // yet.
                'source' => CatalogueFacets::SOURCE_WOOCOMMERCE,
            ],
            admin_url('admin.php')
        );
        wp_safe_redirect($target, 302);
        exit;
    }
}
