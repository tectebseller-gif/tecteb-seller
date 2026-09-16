<?php
/**
 * Fixtures for the public-surface evidence, through the plugin's own services
 * on a DISPOSABLE WordPress.
 *
 * No `declare(strict_types=1)`: `wp eval-file` wraps this in eval().
 *
 *   wp eval-file tools/store-surface-state.php touch-product <vendor-id>
 *   wp eval-file tools/store-surface-state.php suspended-vendor
 *   wp eval-file tools/store-surface-state.php drop-suspended
 *   wp eval-file tools/store-surface-state.php dokan-seller|drop-dokan-seller
 *
 * `suspended-vendor` exists because a filter with nothing to filter proves
 * nothing: a sitemap listing «only approved shops» on an install where every
 * shop is approved would pass with the filter deleted.
 */

use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicantDetails;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStatus;

$command = (string) ($args[0] ?? '');
$vendorId = (int) ($args[1] ?? 0);
$c = Bootstrap::container();
$vendors = $c->get(VendorRepositoryInterface::class);

/** The logins these fixtures own, so cleanup can never hit a real account. */
const TMC_SUSPENDED_LOGIN = 'tmc-evidence-suspended-shop';
const TMC_DOKAN_SELLER_LOGIN = 'tmc-evidence-dokan-seller';

switch ($command) {
    case 'touch-product':
        // Saved through WooCommerce, so the invalidation is reached the way a
        // real edit reaches it — `woocommerce_update_product`, not a direct
        // call to forget(). A fixture that called forget() itself would be
        // measuring the fixture.
        $projected = array_values(array_filter(
            $c->get(ProductRepositoryInterface::class)->forVendor($vendorId, null, 50),
            static fn ($p) => (int) $p->wcProductId > 0
        ));
        $product = $projected[0] ?? null;
        if ($product === null || (int) $product->wcProductId <= 0) {
            printf("touch-product vendor=%d result=no_projected_product\n", $vendorId);
            break;
        }
        $wc = wc_get_product((int) $product->wcProductId);
        if (!$wc) {
            printf("touch-product vendor=%d wc=%d result=not_in_woocommerce\n", $vendorId, $product->wcProductId);
            break;
        }
        $wc->save();
        printf(
            "touch-product vendor=%d wc=%d title=%s result=saved\n",
            $vendorId,
            (int) $product->wcProductId,
            $wc->get_name()
        );
        break;

    case 'suspended-vendor':
        $userId = (int) username_exists(TMC_SUSPENDED_LOGIN);
        if ($userId <= 0) {
            $userId = (int) wp_insert_user([
                'user_login' => TMC_SUSPENDED_LOGIN,
                'user_pass' => wp_generate_password(20),
                'user_email' => 'suspended@evidence.invalid',
                'display_name' => 'فروشگاه تعلیق‌شده (شاهد)',
            ]);
        }
        $application = $vendors->findApplicationByUser($userId);
        $applicationId = $application?->id ?? $vendors->saveDraft($userId, new ApplicantDetails(
            'فروشگاه تعلیق‌شده (شاهد)',
            'شخص حقیقی آزمایشی',
            'suspended@evidence.invalid',
            '09120000000',
            'نشانی آزمایشی',
            true
        ));
        // Approved FIRST, then suspended — so the row has genuinely been an
        // approved shop. A row that was never approved would be excluded by
        // a weaker rule than the one under test.
        $vendors->updateStatus($applicationId, ApplicationStatus::Approved, 1, 'شاهد');
        $wasApproved = $vendors->findApplicationByUser($userId)?->status->value;
        $vendors->updateStatus($applicationId, ApplicationStatus::Suspended, 1, 'شاهد');
        printf(
            "suspended-vendor vendor=%d application=%d was=%s now=%s approved_total=%d\n",
            $userId,
            $applicationId,
            (string) $wasApproved,
            (string) $vendors->findApplicationByUser($userId)?->status->value,
            $vendors->countApprovedVendors()
        );
        break;

    case 'drop-suspended':
        $userId = (int) username_exists(TMC_SUSPENDED_LOGIN);
        if ($userId <= 0) {
            print("drop-suspended result=absent\n");
            break;
        }
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'tmc_vendor_applications', ['user_id' => $userId]);
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($userId);
        printf(
            "drop-suspended vendor=%d result=removed approved_total=%d\n",
            $userId,
            $vendors->countApprovedVendors()
        );
        break;

    case 'dokan-seller':
        // A REAL Dokan seller, so «a shop Dokan will serve is left alone» is
        // measured rather than asserted. Nothing of Dokan's own is edited:
        // this is a new user of ours carrying the role Dokan looks for
        // (`dokan_is_user_seller()` is `user_can($id, 'dokandar')`).
        $userId = (int) username_exists(TMC_DOKAN_SELLER_LOGIN);
        if ($userId <= 0) {
            $userId = (int) wp_insert_user([
                'user_login' => TMC_DOKAN_SELLER_LOGIN,
                'user_pass' => wp_generate_password(20),
                'user_email' => 'dokanseller@evidence.invalid',
                'display_name' => 'فروشندهٔ دکان (شاهد)',
                'role' => 'seller',
            ]);
        }
        $user = new WP_User($userId);
        $user->set_role('seller');
        update_user_meta($userId, 'dokan_enable_selling', 'yes');
        update_user_meta($userId, 'dokan_store_name', 'فروشگاه دکان (شاهد)');
        printf(
            "dokan-seller vendor=%d nicename=%s is_seller=%s tmc_status=%s\n",
            $userId,
            get_userdata($userId)->user_nicename,
            function_exists('dokan_is_user_seller') && dokan_is_user_seller($userId) ? 'yes' : 'no',
            (string) ($vendors->findApplicationByUser($userId)?->status->value ?? 'none')
        );
        break;

    case 'drop-dokan-seller':
        $userId = (int) username_exists(TMC_DOKAN_SELLER_LOGIN);
        if ($userId <= 0) {
            print("drop-dokan-seller result=absent\n");
            break;
        }
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($userId);
        printf("drop-dokan-seller vendor=%d result=removed\n", $userId);
        break;

    default:
        print("usage: touch-product <vendor-id> | suspended-vendor | drop-suspended"
            . " | dokan-seller | drop-dokan-seller\n");
}
