<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Presentation;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Order\Domain\OrderCustomerView;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStateMachine;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\VendorOrderItem;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorMessages;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorNotice;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUi;

/**
 * The vendor's own orders (UX §7).
 *
 * Every row is one line of one order — this shop's line. There is no order
 * total, no other shop's goods, and no customer e-mail or mobile anywhere on
 * the page, because the object this view is handed does not contain them
 * (PRIV-01). The «سهم» column is the snapshot taken when the sale happened,
 * not today's rate.
 */
final class VendorOrdersView
{
    public const PER_PAGE = 20;

    /**
     * @param list<VendorOrderItem> $items
     * @param array<string,int> $counts
     * @param array<int,OrderCustomerView> $customers order id => what may be shown
     * @param array<int,array{number:string,status:string,created:string}> $orders
     * @param array<string,string> $carriers
     */
    public static function render(
        array $items,
        array $counts,
        string $currentStatus,
        int $page,
        int $total,
        array $customers,
        array $orders,
        array $carriers,
        string $ordersUrl,
        string $nonceField,
        ?VendorNotice $notice = null,
        bool $mayAct = true,
        bool $trialMode = false,
        string $environment = ''
    ): string {
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);
        $states = new OrderItemStateMachine();
        $html = '';

        if ($notice !== null) {
            $html .= VendorUi::notice(
                OrderMessages::isErrorNotice($notice->code) ? 'warning' : 'success',
                OrderMessages::notice($notice->code, $notice->context)
                    ?? VendorMessages::notice($notice->code, $notice->context)
            );
        }
        if ($trialMode) {
            $html .= VendorUi::notice('warning', (string) OrderMessages::notice('order_trial_mode', ['environment' => $environment]));
        }

        $html .= '<section class="tv-card"><h2 class="tv-card__title">' . esc_html__('سفارش‌های فروشگاه', 'tecteb-marketplace-core') . '</h2>'
            . '<p class="tv-hint">' . esc_html__('فقط اقلام همین فروشگاه نمایش داده می‌شوند. شماره تماس و ایمیل مشتری در اختیار فروشنده قرار نمی‌گیرد.', 'tecteb-marketplace-core') . '</p>'
            . self::tabs($counts, $currentStatus, $ordersUrl, $fa);

        if ($items === []) {
            return $html . VendorUi::notice('info', $currentStatus === ''
                ? __('هنوز سفارشی برای این فروشگاه ثبت نشده است.', 'tecteb-marketplace-core')
                : __('در این وضعیت قلمی ندارید.', 'tecteb-marketplace-core')) . '</section>';
        }

        $html .= '<ul class="tv-orders">';
        foreach ($items as $item) {
            $html .= self::row(
                $item,
                $customers[$item->orderId] ?? new OrderCustomerView(),
                $orders[$item->orderId] ?? [],
                $carriers,
                $ordersUrl,
                $nonceField,
                $fa,
                $states,
                $mayAct
            );
        }
        $html .= '</ul>';
        return $html . self::pager($page, $total, $currentStatus, $ordersUrl, $fa) . '</section>';
    }

    /** @param array<string,int> $counts @param callable(string|int):string $fa */
    private static function tabs(array $counts, string $current, string $ordersUrl, callable $fa): string
    {
        $tabs = ['' => __('همه', 'tecteb-marketplace-core')];
        foreach (OrderItemStatus::all() as $status) {
            $tabs[$status->value] = OrderMessages::status($status);
        }
        $all = array_sum($counts);
        $html = '<nav class="tv-tabs" aria-label="' . esc_attr__('وضعیت سفارش', 'tecteb-marketplace-core') . '"><ul>';
        foreach ($tabs as $value => $label) {
            $n = $value === '' ? $all : ($counts[$value] ?? 0);
            $isCurrent = (string) $value === $current;
            $url = $value === '' ? $ordersUrl : add_query_arg('status', (string) $value, $ordersUrl);
            $html .= '<li><a class="tv-tab' . ($isCurrent ? ' is-current' : '') . '"'
                . ($isCurrent ? ' aria-current="page"' : '')
                . ' href="' . esc_url($url) . '">' . esc_html($label)
                . ' <span class="tv-tab__count">' . esc_html($fa($n)) . '</span></a></li>';
        }
        return $html . '</ul></nav>';
    }

    /**
     * @param array{number?:string,status?:string,created?:string} $order
     * @param array<string,string> $carriers
     * @param callable(string|int):string $fa
     */
    private static function row(
        VendorOrderItem $item,
        OrderCustomerView $customer,
        array $order,
        array $carriers,
        string $ordersUrl,
        string $nonce,
        callable $fa,
        OrderItemStateMachine $states,
        bool $mayAct
    ): string {
        $money = static fn (?int $minor): string => $minor === null
            ? __('تعیین‌نشده', 'tecteb-marketplace-core')
            : sprintf(__('%s تومان', 'tecteb-marketplace-core'), $fa(number_format($minor)));

        $created = (string) ($order['created'] ?? '');
        $html = '<li class="tv-order"><div class="tv-order__head">'
            . '<strong>' . esc_html(sprintf(
                __('سفارش %s', 'tecteb-marketplace-core'),
                $fa((string) ($order['number'] ?? $item->orderId))
            )) . '</strong> '
            . VendorUi::chip(OrderMessages::statusTone($item->status), OrderMessages::status($item->status))
            . ($created !== '' ? ' <span class="tv-order__date">' . esc_html($fa($created)) . '</span>' : '');
        $html .= '</div>'
            . '<p class="tv-order__title">' . esc_html($item->title)
            . ($item->sku !== '' ? ' — <bdi class="tv-code">' . esc_html($item->sku) . '</bdi>' : '') . '</p>'
            . '<dl class="tv-product__facts">'
            . '<div><dt>' . esc_html__('تعداد', 'tecteb-marketplace-core') . '</dt><dd>' . esc_html($fa($item->quantity)) . '</dd></div>'
            . '<div><dt>' . esc_html__('مبلغ قلم (پس از تخفیف)', 'tecteb-marketplace-core') . '</dt><dd>' . esc_html($money($item->baseMinor)) . '</dd></div>'
            . '<div><dt>' . esc_html__('کمیسیون بازارگاه', 'tecteb-marketplace-core') . '</dt><dd>' . esc_html($money($item->commissionMinor)) . '</dd></div>'
            . '<div><dt>' . esc_html__('سهم شما', 'tecteb-marketplace-core') . '</dt><dd>' . esc_html($money($item->vendorShareMinor)) . '</dd></div>'
            . '</dl>';

        if (!$item->isRecorded()) {
            $html .= VendorUi::notice('warning', __('سهم مالی این قلم ثبت نشده است؛ تا تعیین نرخ کمیسیون، قابل تسویه نیست.', 'tecteb-marketplace-core'));
        }

        if (!$customer->isEmpty()) {
            $html .= '<div class="tv-order__ship"><h3 class="tv-review__title">' . esc_html__('نشانی ارسال', 'tecteb-marketplace-core') . '</h3>'
                . '<p>' . esc_html($customer->name) . '</p>'
                . '<p>' . esc_html(trim($customer->city . ' ' . $customer->address)) . '</p>'
                . ($customer->postcode !== '' ? '<p><bdi class="tv-code">' . esc_html($customer->postcode) . '</bdi></p>' : '')
                . '<p class="tv-hint">' . esc_html__('شماره تماس و ایمیل مشتری در این نسخه به فروشنده داده نمی‌شود.', 'tecteb-marketplace-core') . '</p>'
                . '</div>';
        }

        if ($item->carrier !== '') {
            $html .= '<p class="tv-hint">' . esc_html(sprintf(
                __('ارسال با %1$s — کد رهگیری: %2$s', 'tecteb-marketplace-core'),
                $carriers[$item->carrier] ?? $item->carrier,
                $item->trackingCode !== '' ? $item->trackingCode : __('ثبت‌نشده', 'tecteb-marketplace-core')
            )) . '</p>';
        }

        if ($mayAct) {
            $html .= self::actions($item, $states, $carriers, $ordersUrl, $nonce);
        }
        return $html . '</li>';
    }

    /** @param array<string,string> $carriers */
    private static function actions(
        VendorOrderItem $item,
        OrderItemStateMachine $states,
        array $carriers,
        string $ordersUrl,
        string $nonce
    ): string {
        $next = $states->nextFrom($item->status);
        if ($next === []) {
            return '<p class="tv-hint">' . esc_html__('این قلم به وضعیت نهایی رسیده است.', 'tecteb-marketplace-core') . '</p>';
        }
        $html = '<div class="tv-order__actions">';
        foreach ($next as $status) {
            $html .= '<form method="post" action="' . esc_url($ordersUrl) . '" class="tv-inline">'
                . $nonce
                . '<input type="hidden" name="tmc_vendor_action" value="move_order_item">'
                . '<input type="hidden" name="item_id" value="' . esc_attr((string) $item->id) . '">'
                . '<input type="hidden" name="to" value="' . esc_attr($status->value) . '">';
            if ($states->requiresCarrier($status)) {
                $options = ['' => __('— شرکت حمل —', 'tecteb-marketplace-core')] + $carriers;
                $html .= VendorUi::select('carrier_' . $item->id, __('شرکت حمل', 'tecteb-marketplace-core'), $options, $item->carrier)
                    . VendorUi::input('tracking_' . $item->id, __('کد رهگیری', 'tecteb-marketplace-core'), $item->trackingCode, true, 'text', 'ltr');
            }
            $html .= VendorUi::submit(OrderMessages::action($status), $status === OrderItemStatus::Cancelled ? 'secondary' : 'primary')
                . '</form>';
        }
        return $html . '</div>';
    }

    /** @param callable(string|int):string $fa */
    private static function pager(int $page, int $total, string $status, string $ordersUrl, callable $fa): string
    {
        $pages = (int) ceil($total / self::PER_PAGE);
        if ($pages <= 1) {
            return '<p class="tv-hint">' . esc_html(sprintf(__('%s قلم', 'tecteb-marketplace-core'), $fa($total))) . '</p>';
        }
        $base = $status === '' ? $ordersUrl : add_query_arg('status', $status, $ordersUrl);
        $html = '<nav class="tv-pager" aria-label="' . esc_attr__('صفحه‌بندی سفارش‌ها', 'tecteb-marketplace-core') . '">';
        if ($page > 1) {
            $html .= '<a class="tv-btn tv-btn--secondary" href="' . esc_url(add_query_arg('paged', $page - 1, $base)) . '">'
                . esc_html__('صفحه قبل', 'tecteb-marketplace-core') . '</a>';
        }
        $html .= '<span class="tv-pager__status">' . esc_html(sprintf(
            __('صفحه %1$s از %2$s — %3$s قلم', 'tecteb-marketplace-core'),
            $fa($page),
            $fa($pages),
            $fa($total)
        )) . '</span>';
        if ($page < $pages) {
            $html .= '<a class="tv-btn tv-btn--secondary" href="' . esc_url(add_query_arg('paged', $page + 1, $base)) . '">'
                . esc_html__('صفحه بعد', 'tecteb-marketplace-core') . '</a>';
        }
        return $html . '</nav>';
    }
}
