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
 *   wp eval-file tools/store-surface-state.php approve <vendor-id>
 *   wp eval-file tools/store-surface-state.php demo-name <vendor-id>
 *   wp eval-file tools/store-surface-state.php demo-images <vendor-id>
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

// A manager, because `wp eval-file` runs as nobody and the services that
// matter here check a capability first. Taken from `Capabilities::all()`
// rather than written out, so a new capability does not silently make a
// fixture step refuse while the same call works on a real site.
$manager = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
wp_set_current_user($manager);
$c->bind(
    \Tecteb\Marketplace\Contracts\CapabilityCheckerInterface::class,
    static fn () => new class implements \Tecteb\Marketplace\Contracts\CapabilityCheckerInterface {
        public function can(string $capability): bool
        {
            return in_array($capability, \Tecteb\Marketplace\Core\Lifecycle\Capabilities::all(), true);
        }

        public function currentUserId(): ?int
        {
            return (int) get_current_user_id();
        }
    }
);

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

    case 'approve':
        // An APPROVED application for a vendor that has none.
        //
        // Needed because `tools/upgrade-rollback-check.sh` drops the plugin's
        // tables to build a genuine old-schema site — that is what it is for —
        // and the acceptance path rebuilds stores and products but not the
        // application that makes a shop PUBLIC. Without this, every store-page
        // check afterwards measures a 404 and reports «no approved vendor»,
        // which is true and useless.
        $application = $vendors->findApplicationByUser($vendorId);
        $applicationId = $application?->id ?? $vendors->saveDraft($vendorId, new ApplicantDetails(
            'داروخانهٔ نمونهٔ تک‌طب',
            'شخص حقیقی آزمایشی',
            'vendor@evidence.invalid',
            '09120000001',
            'نشانی آزمایشی',
            true
        ));
        // Through `ReviewApplication`, not `updateStatus()`.
        //
        // The status is the PAPERWORK; the profile's `canSell` is the
        // operational switch, and only the review service writes it. Writing
        // the status directly left every rebuilt shop «approved» and unable to
        // trade — so after `upgrade-rollback-check.sh` (which drops the
        // tables, by design) the acceptance path came back `forbidden` on
        // shipping, returns, withdrawals and reports, and the rebuild
        // procedure had been quietly producing a half-admitted site.
        //
        // The paperwork is moved to «submitted» directly because the
        // application FORM has its own suite; the DECISION goes through the
        // service, because the switch is the thing everything else depends on.
        $vendors->updateStatus($applicationId, ApplicationStatus::Submitted, 1, 'شاهد');
        $review = $c->get(\Tecteb\Marketplace\Modules\Vendor\Application\ReviewApplication::class);
        $approved = $review->approve($applicationId);
        $profile = $vendors->findProfileByUser($vendorId);
        printf(
            "approve vendor=%d application=%d status=%s can_sell=%s ok=%s code=%s approved_total=%d\n",
            $vendorId,
            $applicationId,
            (string) ($vendors->findApplicationByUser($vendorId)?->status->value ?? 'none'),
            $profile?->canSell ? 'true' : 'false',
            $approved->ok ? 'true' : 'false',
            $approved->code,
            $vendors->countApprovedVendors()
        );
        break;

    case 'demo-name':
        // A readable shop name for the walkthrough. `save()` deliberately
        // leaves `store_name` alone — a rename goes through the manager's
        // change-request queue — so this uses the rename path, which is the
        // one that exists for exactly this.
        //
        // DEMO DATA, and it lives only here: the disposable site. Nothing in
        // the package carries it, and a packaging test asserts the package
        // ships no demo content at all.
        $stores = $c->get(\Tecteb\Marketplace\Modules\Vendor\Application\StoreRepositoryInterface::class);
        $stores->renameStore($vendorId, 'داروخانهٔ نمونهٔ تک‌طب');
        printf(
            "demo-name vendor=%d store=%s\n",
            $vendorId,
            (string) ($stores->find($vendorId)?->storeName ?? '')
        );
        break;

    case 'demo-images':
        // Product photos for the walkthrough, drawn rather than blocked out.
        //
        // The catalogue fixture attaches a 64x64 marker, which is the right
        // size for asserting «a thumbnail is present» and the wrong size for
        // showing anybody what a shop looks like: at 64px in a 150px card it
        // reads as a broken image.
        //
        // The pictures themselves used to be one-bit silhouettes, and the
        // owner named them for what they were — «تصاویر هندسیِ آزمون، ارائهٔ
        // نهایی طراحی نیستند». They now come from `demo-image.php`, which
        // paints six pieces of medical equipment with a small rasteriser of
        // its own (this host has no GD, no Imagick and no ImageMagick). They
        // are still illustrations and still meant to read as illustrations: a
        // placeholder that pretends to be a photograph is one somebody
        // eventually ships.
        //
        // DEMO DATA. Written on the disposable site and nowhere else; the
        // package ships no demo content and a packaging test says so.
        require_once ABSPATH . 'demo-image.php';

        $uploads = wp_upload_dir();
        $made = 0;
        $reused = 0;
        // One ink per subject, so a shelf of six cards reads as six products
        // rather than as one product in six colours.
        $subjects = [
            ['stethoscope', [0x14, 0x6B, 0x8C]],
            ['monitor', [0x1E, 0x7A, 0x66]],
            ['syringe', [0x2F, 0x5E, 0x9E]],
            ['thermometer', [0xB0, 0x4A, 0x3C]],
            ['oximeter', [0x5A, 0x3E, 0x8E]],
            ['mask', [0x0F, 0x6E, 0x7A]],
        ];
        $products = array_values($c->get(ProductRepositoryInterface::class)->forVendor($vendorId, null, 60));
        require_once ABSPATH . 'wp-admin/includes/image.php';
        foreach ($products as $i => $row) {
            $wc = (int) $row->wcProductId;
            if ($wc <= 0) {
                continue;
            }
            $existing = (int) get_post_thumbnail_id($wc);
            $meta = $existing > 0 ? wp_get_attachment_metadata($existing) : [];
            if ((int) ($meta['width'] ?? 0) >= 480) {
                $reused++;
                continue;                       // already a usable picture
            }
            // Keyed off the SKU, not the loop index: the catalogue fixture
            // names row N after subject (N-1) mod 6, and `forVendor()` does
            // not promise that order. Indexing by position would hang a
            // picture of a mask on a product called «گوشی پزشکی».
            $n = preg_match('/TMC-PAGE-(\d+)/', $row->details->sku, $m) === 1 ? (int) $m[1] : $i + 1;
            [$subject, $ink] = $subjects[($n - 1) % count($subjects)];
            $made += tmc_demo_attach_image($wc, 'tmc-demo-product-' . $wc, $subject, $ink) > 0 ? 1 : 0;
        }
        printf("demo-images vendor=%d made=%d reused=%d\n", $vendorId, $made, $reused);
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
        print("usage: touch-product <vendor-id> | approve <vendor-id> | suspended-vendor | drop-suspended"
            . " | dokan-seller | drop-dokan-seller\n");
}
