<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Order\Application\ManageOrderItems;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Application\TrialUnlock;
use Tecteb\Marketplace\Modules\Order\Domain\OrderCustomerView;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Presentation\VendorOrdersView;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorListsInterface;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaOutcome;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaView;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;

/** /vendor/orders/ — this shop's lines, and nothing else's. */
final class OrderArea
{
    public const SLUG = 'orders';

    /** @var list<string> */
    public const ACTIONS = ['move_order_item'];

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function render(VendorAreaView $view): string
    {
        $vendorUserId = $this->container->get(StaffAccess::class)->storeFor($view->userId);
        if ($vendorUserId === null) {
            return '';
        }
        $orders = $this->container->get(ManageOrderItems::class);
        if (!$orders->mayView($view->userId, $vendorUserId)) {
            return \Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUi::notice(
                'warning',
                __('نقش شما به سفارش‌های این فروشگاه دسترسی ندارد.', 'tecteb-marketplace-core')
            );
        }

        $request = $view->request;
        $status = OrderItemStatus::tryFrom($request->queryKey('status'));
        $page = max(1, $request->queryInt('paged'));
        $items = $orders->listFor(
            $view->userId,
            $vendorUserId,
            $status,
            VendorOrdersView::PER_PAGE,
            ($page - 1) * VendorOrdersView::PER_PAGE
        );
        $repository = $this->container->get(OrderItemRepositoryInterface::class);
        $trial = $this->container->get(TrialUnlock::class);

        return VendorOrdersView::render(
            $items,
            $repository->countsByStatus($vendorUserId),
            $status?->value ?? '',
            $page,
            $repository->countForVendor($vendorUserId, $status),
            $this->customersFor($items),
            $this->ordersFor($items),
            $this->container->get(VendorListsInterface::class)->carriers(),
            $this->ordersUrl(),
            $view->nonceField,
            $view->notice,
            $orders->mayAct($view->userId, $vendorUserId),
            $trial->isActive(),
            $trial->environmentName()
        );
    }

    public function handle(string $action, Request $request, int $userId, VendorUrls $urls): ?VendorAreaOutcome
    {
        if ($action !== 'move_order_item') {
            return null;
        }
        $vendorUserId = $this->container->get(StaffAccess::class)->storeFor($userId);
        if ($vendorUserId === null) {
            return new VendorAreaOutcome('not_a_vendor', $urls->dashboard());
        }
        $itemId = $request->postInt('item_id');
        $to = OrderItemStatus::tryFrom($request->postKey('to'));
        if ($to === null) {
            return new VendorAreaOutcome('invalid_transition', $this->ordersUrl());
        }
        $result = $this->container->get(ManageOrderItems::class)->move(
            $userId,
            $vendorUserId,
            $itemId,
            $to,
            $request->postText('carrier_' . $itemId),
            $request->postText('tracking_' . $itemId)
        );
        return new VendorAreaOutcome($result->code, $this->ordersUrl(), $result->context);
    }

    public function ordersUrl(): string
    {
        return (string) get_option('permalink_structure', '') !== ''
            ? home_url('/vendor/' . self::SLUG . '/')
            : home_url('/?tmc_vendor=' . self::SLUG);
    }

    /**
     * The four fields PRIV-01 allows, per order, read once.
     *
     * @param list<\Tecteb\Marketplace\Modules\Order\Domain\VendorOrderItem> $items
     * @return array<int,OrderCustomerView>
     */
    private function customersFor(array $items): array
    {
        if (!function_exists('wc_get_order')) {
            return [];
        }
        $reader = $this->container->get(WcOrderReader::class);
        $out = [];
        foreach ($items as $item) {
            if (isset($out[$item->orderId])) {
                continue;
            }
            $order = wc_get_order($item->orderId);
            $out[$item->orderId] = $order ? $reader->customerView($order) : new OrderCustomerView();
        }
        return $out;
    }

    /**
     * @param list<\Tecteb\Marketplace\Modules\Order\Domain\VendorOrderItem> $items
     * @return array<int,array{number:string,status:string,created:string}>
     */
    private function ordersFor(array $items): array
    {
        if (!function_exists('wc_get_order')) {
            return [];
        }
        $reader = $this->container->get(WcOrderReader::class);
        $out = [];
        foreach ($items as $item) {
            if (isset($out[$item->orderId])) {
                continue;
            }
            $order = wc_get_order($item->orderId);
            if (!$order) {
                continue;
            }
            $summary = $reader->summary($order);
            $out[$item->orderId] = [
                'number' => $summary['number'],
                'status' => $summary['status'],
                'created' => $summary['created'],
            ];
        }
        return $out;
    }
}
