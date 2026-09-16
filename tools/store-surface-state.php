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
        $vendors->updateStatus($applicationId, ApplicationStatus::Approved, 1, 'شاهد');
        printf(
            "approve vendor=%d application=%d status=%s approved_total=%d\n",
            $vendorId,
            $applicationId,
            (string) ($vendors->findApplicationByUser($vendorId)?->status->value ?? 'none'),
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
        // Product-shaped placeholder photos for the walkthrough.
        //
        // The catalogue fixture attaches a 64x64 marker, which is the right
        // size for asserting «a thumbnail is present» and the wrong size for
        // showing anybody what a shop looks like: at 64px in a 150px card it
        // reads as a broken image. A walkthrough exists to show the real
        // thing, so it gets images with real proportions.
        //
        // DEMO DATA. It is written on the disposable site and nowhere else;
        // the package ships no demo content and a packaging test says so.
        // Written byte by byte, because this host's PHP has neither GD nor
        // Imagick and the box has no ImageMagick either. A PNG is a signature
        // and three chunks, and `gzcompress` produces exactly the zlib stream
        // PNG wants — so the picture costs thirty lines rather than a
        // dependency that would have to exist on every machine running this.
        $png = static function (int $size, array $ink, array $paper): string {
            $raw = '';
            for ($y = 0; $y < $size; $y++) {
                $raw .= chr(0);                 // filter type 0 for this scanline
                for ($x = 0; $x < $size; $x++) {
                    // A jar: a body and a neck, drawn with two tests rather
                    // than a drawing library.
                    $inBody = $x > $size * 0.18 && $x < $size * 0.82
                        && $y > $size * 0.30 && $y < $size * 0.86;
                    $inNeck = $x > $size * 0.34 && $x < $size * 0.66
                        && $y > $size * 0.14 && $y <= $size * 0.30;
                    $c = ($inBody || $inNeck) ? $ink : $paper;
                    $raw .= chr($c[0]) . chr($c[1]) . chr($c[2]);
                }
            }
            $chunk = static function (string $type, string $data): string {
                return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
            };
            return "\x89PNG\r\n\x1a\n"
                . $chunk('IHDR', pack('NN', $size, $size) . chr(8) . chr(2) . chr(0) . chr(0) . chr(0))
                . $chunk('IDAT', gzcompress($raw, 9))
                . $chunk('IEND', '');
        };

        $uploads = wp_upload_dir();
        $made = 0;
        $reused = 0;
        $palette = [[0x6A, 0xBF, 0xE7], [0x21, 0xD4, 0x83], [0xFF, 0xC6, 0x58], [0x14, 0x3C, 0x4D]];
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
            $file = $uploads['path'] . '/tmc-demo-product-' . $wc . '.png';
            file_put_contents($file, $png(600, $palette[$i % count($palette)], [0xED, 0xF3, 0xF6]));

            $attachmentId = (int) wp_insert_attachment([
                'post_mime_type' => 'image/png',
                'post_title' => 'تصویر نمونهٔ محصول ' . $wc,
                'post_status' => 'inherit',
            ], $file, $wc);
            wp_update_attachment_metadata($attachmentId, wp_generate_attachment_metadata($attachmentId, $file));
            set_post_thumbnail($wc, $attachmentId);
            $made++;
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
