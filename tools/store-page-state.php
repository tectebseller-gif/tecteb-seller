<?php
/**
 * Drives the shop's public page and its temporary closure, through the
 * plugin's own services on a DISPOSABLE WordPress.
 *
 * No `declare(strict_types=1)`: `wp eval-file` wraps this in eval().
 *
 *   wp eval-file tools/store-page-state.php seed <vendor-id>
 *   wp eval-file tools/store-page-state.php close|reopen <vendor-id>
 *   wp eval-file tools/store-page-state.php suspend|reinstate <vendor-id>
 *   wp eval-file tools/store-page-state.php buyable <vendor-id>
 *   wp eval-file tools/store-page-state.php ongoing <vendor-id>
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Application\ShipItems;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\PurchasePolicy;
use Tecteb\Marketplace\Modules\Product\Presentation\PurchaseMessages;
use Tecteb\Marketplace\Modules\Vendor\Application\StoreRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\ReviewApplication;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\StoreSettings;

$command = (string) ($args[0] ?? '');
$vendorId = (int) ($args[1] ?? 0);
$c = Bootstrap::container();
$manager = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
wp_set_current_user($manager);
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

$stores = $c->get(StoreRepositoryInterface::class);
$products = $c->get(ProductRepositoryInterface::class);

/** The shop's first projected product, which is what «buyable» asks about. */
$firstProduct = static function (int $vendorUserId) use ($products): int {
    foreach ($products->allForVendor($vendorUserId) as $product) {
        if ((int) ($product->wcProductId ?? 0) > 0) {
            return (int) $product->wcProductId;
        }
    }
    return 0;
};

switch ($command) {
    case 'seed':
        // REAL values in every private field. The privacy checks assert these
        // exact strings are absent from the page — and a page that omits an
        // empty field proves nothing, so they have to be filled first.
        $current = $stores->find($vendorId) ?? new StoreSettings();
        $stores->save($vendorId, new StoreSettings(
            'فروشگاه آزمایشی نمایش عمومی',
            'اصفهان',
            'معرفی آزمایشی فروشگاه برای صفحهٔ عمومی.',
            $current->logoId,
            $current->bannerId,
            3,
            'انبار خصوصی خیابان شماره ۱۲، پلاک ۴',
            $current->carriers,
            false,
            null,
            null,
            '',
            ['instagram' => 'https://example.test/tecteb-shop']
        ));
        // The applicant's own contact details live on the application, not the
        // store row — written here so their absence from the page is measured
        // too, not assumed from the fact that this page never queries them.
        update_user_meta($vendorId, 'tmc_probe_applicant_email', 'private-store@example.test');
        update_user_meta($vendorId, 'tmc_probe_applicant_mobile', '09121112233');
        update_user_meta($vendorId, 'tmc_probe_applicant_address', 'نشانی شخصی متقاضی');
        update_user_meta($vendorId, 'tmc_probe_iban', 'IR120000000000000000000000');
        $saved = $stores->find($vendorId);
        printf(
            "seed vendor=%d store=%s city=%s warehouse=%s\n",
            $vendorId,
            ($saved?->storeName ?? '') === '' ? 'فروشگاه بازارگاه تک‌طب' : $saved->storeName,
            $saved?->city ?? '-',
            ($saved?->originWarehouse ?? '') === '' ? '-' : 'set'
        );
        break;

    case 'close':
    case 'reopen':
        $current = $stores->find($vendorId) ?? new StoreSettings();
        $closing = $command === 'close';
        $stores->save($vendorId, new StoreSettings(
            $current->storeName,
            $current->city,
            $current->intro,
            $current->logoId,
            $current->bannerId,
            $current->preparationDays,
            $current->originWarehouse,
            $current->carriers,
            $closing,
            $closing ? gmdate('Y-m-d', strtotime('-1 day')) : null,
            $closing ? gmdate('Y-m-d', strtotime('+7 days')) : null,
            $closing ? 'تا هفتهٔ آینده برمی‌گردیم.' : '',
            $current->social
        ));
        $now = gmdate('Y-m-d');
        printf(
            "%s vendor=%d closed=%s today=%s\n",
            $command,
            $vendorId,
            $stores->find($vendorId)?->isClosedOn($now) ? 'true' : 'false',
            $now
        );
        break;

    case 'suspend':
    case 'reinstate':
        // Through the application, because that is what «تعلیق فروشنده» is:
        // the marketplace withdrawing the yes it gave, not a flag on a store.
        $application = $c->get(VendorRepositoryInterface::class)->findApplicationByUser($vendorId);
        if ($application === null) {
            echo "no application for that vendor\n";
            break;
        }
        $service = $c->get(ReviewApplication::class);
        $result = $command === 'suspend'
            ? $service->suspend($application->id, 'آزمون صفحهٔ عمومی')
            : $service->reinstate($application->id);
        printf("%s vendor=%d ok=%s code=%s\n", $command, $vendorId, $result->ok ? 'true' : 'false', $result->code);
        break;

    case 'buyable':
        // Asked of the policy itself, not of a page: what a shopper is told is
        // the SAME answer the cart and the checkout get.
        $wcProductId = $firstProduct($vendorId);
        $decision = $c->get(PurchasePolicy::class)->decide($wcProductId);
        printf(
            "buyable vendor=%d product=%d decision=%s message=%s\n",
            $vendorId,
            $wcProductId,
            $decision['decision'],
            str_replace(' ', '_', (string) PurchaseMessages::shopper($decision['decision']))
        );
        break;

    case 'ongoing':
        // A.5: «سفارش‌های قبلی باید ادامه یابند». The order module is never
        // asked about closure, and this proves it rather than asserting it: a
        // line captured before the shop closed is shipped while it is closed.
        $items = $c->get(OrderItemRepositoryInterface::class);
        $line = null;
        foreach ($items->forVendor($vendorId, null, 50) as $candidate) {
            if ($candidate->status === OrderItemStatus::Placed || $candidate->status === OrderItemStatus::Preparing) {
                $line = $candidate;
            }
        }
        if ($line === null) {
            echo "ongoing ok=false shipped=false reason=no_open_line\n";
            break;
        }
        $result = $c->get(ShipItems::class)->ship($vendorId, $vendorId, $line->id, 1, 'پست پیشتاز', 'TRK-CLOSED-1');
        $after = $items->find($line->id);
        printf(
            "ongoing ok=%s shipped=%s item=%d status=%s code=%s\n",
            $result->ok ? 'true' : 'false',
            $after !== null && $after->status !== OrderItemStatus::Placed ? 'true' : 'false',
            $line->id,
            $after?->status->value ?? '-',
            $result->code
        );
        break;

    default:
        echo "unknown command\n";
        break;
}
