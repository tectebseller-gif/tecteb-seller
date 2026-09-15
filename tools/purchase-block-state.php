<?php
/**
 * Flips one piece of marketplace state on a DISPOSABLE WordPress, through the
 * plugin's own services, so the browser probe next to it can ask WooCommerce
 * what a shopper would actually get.
 *
 * Nothing here touches the shop's own products or Dokan's: every command names
 * a marketplace vendor or a marketplace product, and the only global switch it
 * writes is the trial option, which by design means "the MARKETPLACE does not
 * sell" and says nothing about anybody else's catalogue.
 *
 * No `declare(strict_types=1)`: `wp eval-file` wraps this in eval().
 *
 *   wp eval-file tools/purchase-block-state.php <command> [args…]
 *
 *   seed-staff <vendor-user-id> <username> <password>
 *   suspend    <vendor-user-id> <note>
 *   reinstate  <vendor-user-id>
 *   stock      <tmc-product-id> <stock>
 *   trial      <0|1>
 *   decide     <wc-product-id>…
 *   state
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\OptionStoreInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Order\Application\TrialUnlock;
use Tecteb\Marketplace\Modules\Product\Application\ManageProducts;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\ManageStaff;
use Tecteb\Marketplace\Modules\Vendor\Application\ReviewApplication;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicantDetails;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStatus;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffRolePreset;

$command = (string) ($args[0] ?? 'state');
$c = Bootstrap::container();
$manager = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
wp_set_current_user($manager);
// The manager's own capabilities: every command below is a manager action, and
// wp-cli has no logged-in user of its own.
$c->bind(CapabilityCheckerInterface::class, static function () use ($manager) {
    return new class ($manager) implements CapabilityCheckerInterface {
        public function __construct(private $id)
        {
        }

        public function can(string $capability): bool
        {
            return in_array($capability, Capabilities::all(), true);
        }

        public function currentUserId(): ?int
        {
            return $this->id;
        }
    };
});

$vendors = $c->get(VendorRepositoryInterface::class);

/** The application row the manager's review path acts on. */
$applicationFor = static function (int $vendorUserId) use ($vendors) {
    $application = $vendors->findApplicationByUser($vendorUserId);
    return $application?->id ?? 0;
};

switch ($command) {
    case 'seed-staff':
        $vendorUserId = (int) ($args[1] ?? 0);
        $username = (string) ($args[2] ?? '');
        $password = (string) ($args[3] ?? '');
        $profile = $vendors->findProfileByUser($vendorUserId);
        // An application to review. The applicant's own submit path (documents,
        // requirement sets) was proved in the vendor stage; what the ORDER
        // stage needs is a reviewable row, so the draft is stored and moved to
        // «submitted» through the repository and approved through the service.
        if ($applicationFor($vendorUserId) === 0) {
            $vendors->saveDraft($vendorUserId, new ApplicantDetails(
                $profile?->storeName ?: 'فروشگاه آزمایشی',
                'شرکت آزمایشی تک‌طب',
                'vendor' . $vendorUserId . '@example.test',
                '0912000000' . $vendorUserId,
                'تهران، خیابان آزمایش، پلاک ۱',
                true
            ));
            $vendors->updateStatus($applicationFor($vendorUserId), ApplicationStatus::Submitted);
        }
        $application = $vendors->findApplicationByUser($vendorUserId);
        if ($application !== null && $application->status !== ApplicationStatus::Approved) {
            $c->get(ReviewApplication::class)->approve($application->id);
        }
        $staffRepo = $c->get(StaffRepositoryInterface::class);
        $existing = null;
        foreach ($staffRepo->forVendor($vendorUserId) as $member) {
            if ($member->username === $username) {
                $existing = $member;
            }
        }
        if ($existing === null) {
            // The owner invites their own staff — managing a store is the
            // owner's alone (UX §9.2), so the manager cannot stand in here.
            $invited = $c->get(ManageStaff::class)->invite(
                $vendorUserId,
                $vendorUserId,
                'کارمند',
                'ارسال',
                $username,
                $username . '@example.test',
                '09120009090',
                StaffRolePreset::OrderAndShipping
            );
            if (!$invited->ok) {
                echo "invite failed: {$invited->code}\n";
                return;
            }
            $staffId = (int) $invited->context['staff_id'];
            $staffRepo->activate($staffId);
            $existing = $staffRepo->find($staffId);
        }
        wp_set_password($password, $existing->staffUserId);
        printf(
            "staff user=%d login=%s vendor=%d preset=%s status=%s\n",
            $existing->staffUserId,
            $existing->username,
            $vendorUserId,
            $existing->preset->value,
            $existing->status->value
        );
        break;

    case 'suspend':
        $vendorUserId = (int) ($args[1] ?? 0);
        $note = (string) ($args[2] ?? 'آزمایش تعلیق');
        $result = $c->get(ReviewApplication::class)->suspend($applicationFor($vendorUserId), $note);
        printf("suspend vendor=%d ok=%s code=%s\n", $vendorUserId, $result->ok ? 'true' : 'false', $result->code);
        break;

    case 'reinstate':
        $vendorUserId = (int) ($args[1] ?? 0);
        $result = $c->get(ReviewApplication::class)->reinstate($applicationFor($vendorUserId));
        printf("reinstate vendor=%d ok=%s code=%s\n", $vendorUserId, $result->ok ? 'true' : 'false', $result->code);
        break;

    case 'stock':
        $productId = (int) ($args[1] ?? 0);
        $stock = (int) ($args[2] ?? 0);
        $product = $c->get(ProductRepositoryInterface::class)->find($productId);
        if ($product === null) {
            echo "no such product\n";
            return;
        }
        // The vendor's own inventory path, which write-throughs to WooCommerce.
        $result = $c->get(ManageProducts::class)->updateInventory(
            $product->vendorUserId,
            $product->vendorUserId,
            $productId,
            $stock,
            $product->details->sku,
            $product->details->minPurchase,
            $product->details->maxPurchase
        );
        printf("stock product=%d to=%d ok=%s code=%s\n", $productId, $stock, $result->ok ? 'true' : 'false', $result->code);
        break;

    case 'trial':
        $on = (string) ($args[1] ?? '0') === '1';
        $c->get(OptionStoreInterface::class)->set(TrialUnlock::OPTION, $on);
        printf("trial requested=%s\n", $on ? 'true' : 'false');
        break;

    case 'decide':
        // What the catalogue would answer WooCommerce about one storefront
        // product, including the ones that are not ours.
        $policy = $c->get(\Tecteb\Marketplace\Modules\Product\Application\PurchasePolicy::class);
        foreach (array_slice($args, 1) as $wcProductId) {
            $decision = $policy->decide((int) $wcProductId);
            $wcProduct = function_exists('wc_get_product') ? wc_get_product((int) $wcProductId) : null;
            printf(
                "wc=%d decision=%s tmc_product=%s wc_status=%s wc_purchasable=%s wc_in_stock=%s\n",
                (int) $wcProductId,
                $decision['decision'],
                $decision['product'] === null ? 'none' : (string) $decision['product']->id,
                $wcProduct === null ? 'missing' : $wcProduct->get_status(),
                $wcProduct === null ? 'n/a' : ($wcProduct->is_purchasable() ? 'true' : 'false'),
                $wcProduct === null ? 'n/a' : ($wcProduct->is_in_stock() ? 'true' : 'false')
            );
        }
        break;

    default:
        $access = $c->get(StaffAccess::class);
        $products = $c->get(ProductRepositoryInterface::class);
        $trial = $c->get(TrialUnlock::class);
        printf(
            "trial requested=%s permitted=%s active=%s environment=%s\n",
            $trial->isRequested() ? 'true' : 'false',
            $trial->isPermitted() ? 'true' : 'false',
            $trial->isActive() ? 'true' : 'false',
            $trial->environmentName()
        );
        foreach ([1, 2, 3] as $id) {
            $product = $products->find($id);
            if ($product === null) {
                continue;
            }
            printf(
                "product=%d vendor=%d wc=%s status=%s stock=%d vendor_can_trade=%s\n",
                $product->id,
                $product->vendorUserId,
                $product->wcProductId === null ? 'none' : (string) $product->wcProductId,
                $product->status->value,
                $product->details->stock,
                $access->vendorCanTrade($product->vendorUserId) ? 'true' : 'false'
            );
        }
        break;
}
