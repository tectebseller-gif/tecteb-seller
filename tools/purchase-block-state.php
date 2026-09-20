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
 *   stop       [reason]           توقف فروش بازارگاه (نتیجه‌ای که به مدیر گفته می‌شود)
 *   resume                        از سرگیری، فقط آنچه شرایطش برقرار است
 *   payable                       سفارش‌های پرداخت‌نشده‌ای که هنوز قابل پرداخت‌اند
 *   release-orders                بازگرداندن سفارش‌های نگه‌داشته، بدون بازکردن فروش
 *   order-status <id>             وضعیت و قابل‌پرداخت‌بودن یک سفارش
 *   stuck                         آنچه غیرفعال‌سازی نتوانست ببندد
 *   settle     <order-item-id>    ثبت «تکمیل برای تسویه» توسط مدیر
 *   balance    <vendor-user-id>   مانده و آنچه قابل برداشت است
 *   withdraw   <vendor-user-id>   درخواست برداشت از سوی خود فروشنده
 *   review     <withdrawal-id> <status> [reference|note]
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
        // The WordPress ACCOUNT can outlive the membership row.
        //
        // `upgrade-rollback-check.sh` drops the plugin's tables by design; it
        // does not drop `wp_users`. So after a rebuild the membership is gone
        // and the account is still there, and `invite()` — which creates an
        // account — answers `username_taken` and the fixture stops. Adopting
        // the existing account is exactly the imported-staff path this release
        // added, and it is the honest verb here too: the person already has an
        // account, and what is missing is their membership.
        if ($existing === null) {
            $knownUser = (int) username_exists($username);
            if ($knownUser > 0) {
                $preset = StaffRolePreset::OrderAndShipping;
                $staffId = $staffRepo->adopt(
                    $vendorUserId,
                    $knownUser,
                    'کارمند ارسال',
                    $username,
                    $username . '@example.test',
                    $preset,
                    $preset->permissions()
                );
                if ($staffId > 0) {
                    $staffRepo->activate($staffId);
                    $existing = $staffRepo->find($staffId);
                }
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

    case 'stop':
        // stopAsResult(), not stop(): what the MANAGER is told is the thing
        // under test. A stop that left one product on sale must not read as
        // success anywhere — not on the screen, not here.
        $service = $c->get(\Tecteb\Marketplace\Modules\Product\Application\StorefrontStop::class);
        $result = $service->stopAsResult((string) ($args[1] ?? 'manager_stopped'), $manager);
        printf(
            "stop ok=%s code=%s withdrawn=%s failed=%s total=%s stuck=%s orders_held=%s orders_stuck=%s stopped=%s\n",
            $result->ok ? 'true' : 'false',
            $result->code,
            (string) ($result->context['withdrawn'] ?? '0'),
            (string) ($result->context['failed'] ?? '0'),
            (string) ($result->context['total'] ?? '0'),
            ($result->context['stuck'] ?? '') === '' ? '-' : (string) $result->context['stuck'],
            (string) ($result->context['orders_held'] ?? '0'),
            ($result->context['orders_stuck'] ?? '') === '' ? '-' : (string) $result->context['orders_stuck'],
            $c->get(\Tecteb\Marketplace\Modules\Product\Application\StorefrontSwitch::class)->isStopped() ? 'true' : 'false'
        );
        break;

    case 'resume':
        $outcome = $c->get(\Tecteb\Marketplace\Modules\Product\Application\StorefrontStop::class)->resume($manager);
        printf(
            "resume published=%d total=%d orders_released=%d\n",
            $outcome['published'],
            $outcome['total'],
            $outcome['orders_released']
        );
        foreach ($outcome['refused'] as $productId => $why) {
            printf("refused product=%d reason=%s\n", $productId, $why);
        }
        break;

    case 'release-orders':
        // The act that is NOT «از سرگیری»: hand the held orders back while the
        // marketplace stays shut. The rollback guide depends on this working
        // on its own.
        $result = $c->get(\Tecteb\Marketplace\Modules\Product\Application\StorefrontStop::class)
            ->releaseOrders($manager);
        printf(
            "release ok=%s code=%s released=%s stuck=%s stopped=%s\n",
            $result->ok ? 'true' : 'false',
            $result->code,
            (string) ($result->context['released'] ?? '0'),
            ($result->context['stuck'] ?? '') === '' ? '-' : (string) $result->context['stuck'],
            $c->get(\Tecteb\Marketplace\Modules\Product\Application\StorefrontSwitch::class)->isStopped() ? 'true' : 'false'
        );
        break;

    case 'order-status':
        $order = wc_get_order((int) ($args[1] ?? 0));
        if (!$order) {
            echo "no such order\n";
            break;
        }
        printf(
            "order=%d status=%s needs_payment=%s items=%d total=%s held_from=%s\n",
            (int) $order->get_id(),
            $order->get_status(),
            $order->needs_payment() ? 'true' : 'false',
            count($order->get_items()),
            (string) $order->get_total(),
            ($order->get_meta('_tmc_stop_prev_status') ?: '-')
        );
        break;

    case 'payable':
        // Unpaid orders whose mailed pay link still takes money, asked without
        // changing anything.
        $open = $c->get(\Tecteb\Marketplace\Modules\Product\Application\StorefrontStop::class)->payableOrders();
        printf("payable count=%d orders=%s\n", count($open), $open === [] ? '-' : implode(',', $open));
        break;

    case 'stuck':
        // What the last stop left behind, read from the options that outlive
        // the run that wrote them — including a deactivation's.
        $stuck = $c->get(\Tecteb\Marketplace\Modules\Product\Application\StorefrontSwitch::class)->stuck();
        printf(
            "stuck products=%s orders=%s\n",
            $stuck['products'] === [] ? '-' : implode(',', $stuck['products']),
            $stuck['orders'] === [] ? '-' : implode(',', $stuck['orders'])
        );
        break;

    case 'settle':
        $itemId = (int) ($args[1] ?? 0);
        $result = $c->get(\Tecteb\Marketplace\Modules\Order\Application\ManageOrderItems::class)
            ->recordSettlementCompletion($itemId, (string) ($args[2] ?? '1') === '1');
        printf("settle item=%d ok=%s code=%s\n", $itemId, $result->ok ? 'true' : 'false', $result->code);
        break;

    case 'balance':
        $vendorUserId = (int) ($args[1] ?? 0);
        $balance = $c->get(\Tecteb\Marketplace\Modules\Finance\Application\VendorBalance::class)->of($vendorUserId);
        $gate = $c->get(\Tecteb\Marketplace\Modules\Finance\Application\SettlementGate::class)->check();
        printf(
            "vendor=%d earned=%d pending=%d eligible=%d reserved=%d paid=%d unrecorded=%d delay_days=%d awaiting_completion=%d awaiting_delay=%d awaiting_return=%d gate=%s\n",
            $vendorUserId,
            $balance['earned'],
            $balance['pending'],
            $balance['eligible'],
            $balance['reserved'],
            $balance['paid'],
            $balance['unrecorded'],
            $balance['delay_days'],
            $balance['awaiting_completion'],
            $balance['awaiting_delay'],
            $balance['awaiting_return'],
            $gate['reason']
        );
        break;

    case 'withdraw':
        $vendorUserId = (int) ($args[1] ?? 0);
        // The VENDOR asks, not the manager: the permission check is part of
        // what this evidence is measuring.
        $result = $c->get(\Tecteb\Marketplace\Modules\Finance\Application\RequestWithdrawal::class)
            ->handle($vendorUserId, $vendorUserId);
        printf(
            "withdraw vendor=%d ok=%s code=%s amount=%s lines=%s\n",
            $vendorUserId,
            $result->ok ? 'true' : 'false',
            $result->code,
            (string) ($result->context['amount_minor'] ?? '-'),
            (string) ($result->context['lines'] ?? '-')
        );
        break;

    case 'review':
        $withdrawalId = (int) ($args[1] ?? 0);
        $target = (string) ($args[2] ?? '');
        $text = (string) ($args[3] ?? '');
        $service = $c->get(\Tecteb\Marketplace\Modules\Finance\Application\ReviewWithdrawals::class);
        $result = match ($target) {
            'reviewing' => $service->startReview($withdrawalId),
            'approved' => $service->approve($withdrawalId),
            'payment_in_progress' => $service->startPayment($withdrawalId),
            'paid' => $service->recordPayment($withdrawalId, $text),
            'rejected' => $service->reject($withdrawalId, $text),
            'reconciliation_required' => $service->needsReconciliation($withdrawalId, $text),
            default => null,
        };
        if ($result === null) {
            echo "unknown target status\n";
            break;
        }
        printf("review withdrawal=%d to=%s ok=%s code=%s\n", $withdrawalId, $target, $result->ok ? 'true' : 'false', $result->code);
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
