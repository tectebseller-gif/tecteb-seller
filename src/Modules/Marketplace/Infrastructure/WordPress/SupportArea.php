<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Marketplace\Application\ManageCoupons;
use Tecteb\Marketplace\Modules\Marketplace\Application\ManageTickets;
use Tecteb\Marketplace\Modules\Marketplace\Domain\Coupon;
use Tecteb\Marketplace\Modules\Marketplace\Domain\Ticket;
use Tecteb\Marketplace\Modules\Marketplace\Presentation\MarketplaceMessages;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaOutcome;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaView;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUi;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;

/**
 * /vendor/support/ — this shop's own discount codes, and its conversation with
 * the marketplace.
 *
 * Two things on one page because they are the two places a shop talks outward,
 * and neither is big enough to earn its own tab in the vendor's navigation.
 */
final class SupportArea
{
    public const SLUG = 'support';

    /** @var list<string> */
    public const ACTIONS = ['create_coupon', 'disable_coupon', 'open_ticket', 'reply_ticket'];

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function render(VendorAreaView $view): string
    {
        $vendorUserId = $this->container->get(StaffAccess::class)->storeFor($view->userId);
        if ($vendorUserId === null) {
            return '';
        }
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);
        $html = '';
        if ($view->notice !== null) {
            $html .= VendorUi::notice(
                MarketplaceMessages::isErrorNotice($view->notice->code) ? 'warning' : 'success',
                MarketplaceMessages::notice($view->notice->code, $view->notice->context)
                    ?? $view->notice->code
            );
        }
        $html .= VendorUi::notice('info', MarketplaceMessages::openTermsWarning());
        return $html
            . $this->couponSection($view, $vendorUserId, $fa)
            . $this->ticketSection($view, $vendorUserId, $fa);
    }

    /** @param callable(string|int):string $fa */
    private function couponSection(VendorAreaView $view, int $vendorUserId, callable $fa): string
    {
        $coupons = $this->container->get(ManageCoupons::class)->forVendor($view->userId, $vendorUserId);
        $html = '<section class="tv-card"><h2 class="tv-card__title">'
            . esc_html__('کدهای تخفیف این فروشگاه', 'tecteb-marketplace-core') . '</h2>'
            . '<p class="tv-hint">'
            . esc_html__('هر کدی که اینجا می‌سازید فقط روی محصولات همین فروشگاه اعمال می‌شود؛ هزینه‌اش هم از سهم همین فروشگاه کم می‌شود. کد سراسری بازارگاه در اختیار مدیر است و هنوز فعال نشده.', 'tecteb-marketplace-core')
            . '</p>';

        if ($coupons !== []) {
            $html .= '<div class="tv-scroll" tabindex="0" role="region" aria-label="'
                . esc_attr__('جدول کدهای تخفیف', 'tecteb-marketplace-core') . '">'
                . '<table class="tv-table"><thead><tr>'
                . '<th scope="col">' . esc_html__('کد', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('مقدار', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('وضعیت', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('اقدام', 'tecteb-marketplace-core') . '</th>'
                . '</tr></thead><tbody>';
            foreach ($coupons as $coupon) {
                $value = $coupon->kind === Coupon::PERCENT
                    ? sprintf(__('%s درصد', 'tecteb-marketplace-core'), $fa((string) ($coupon->value / 100)))
                    : sprintf(__('%s تومان', 'tecteb-marketplace-core'), $fa(number_format($coupon->value)));
                $html .= '<tr><td><bdi class="tv-code">' . esc_html($coupon->code) . '</bdi></td>'
                    . '<td>' . esc_html($value) . '</td>'
                    . '<td>' . esc_html($coupon->status === Coupon::ACTIVE
                        ? __('فعال', 'tecteb-marketplace-core')
                        : __('غیرفعال', 'tecteb-marketplace-core')) . '</td>'
                    . '<td>';
                if ($coupon->status === Coupon::ACTIVE) {
                    $html .= '<form method="post" class="tv-inline">' . $view->nonceField
                        . '<input type="hidden" name="tmc_vendor_action" value="disable_coupon">'
                        . '<input type="hidden" name="coupon_id" value="' . esc_attr((string) $coupon->id) . '">'
                        . VendorUi::submit(__('غیرفعال‌کردن', 'tecteb-marketplace-core'), 'secondary')
                        . '</form>';
                }
                $html .= '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }

        return $html . '<form method="post" class="tv-form">' . $view->nonceField
            . '<input type="hidden" name="tmc_vendor_action" value="create_coupon">'
            . VendorUi::input('coupon_code', __('کد', 'tecteb-marketplace-core'), '', true, 'text', 'ltr',
                __('حروف بزرگ انگلیسی، عدد، خط تیره و زیرخط.', 'tecteb-marketplace-core'))
            . VendorUi::select('coupon_kind', __('نوع', 'tecteb-marketplace-core'), [
                Coupon::PERCENT => __('درصدی', 'tecteb-marketplace-core'),
                Coupon::FIXED => __('مبلغ ثابت', 'tecteb-marketplace-core'),
            ], Coupon::PERCENT)
            . VendorUi::input('coupon_value', __('مقدار', 'tecteb-marketplace-core'), '', true, 'number', 'ltr',
                __('برای درصدی، عدد درصد؛ برای مبلغ ثابت، مبلغ به تومان.', 'tecteb-marketplace-core'))
            . VendorUi::input('coupon_min', __('حداقل مبلغ سبد', 'tecteb-marketplace-core'), '0', true, 'number', 'ltr')
            . VendorUi::input('coupon_limit', __('سقف کل استفاده', 'tecteb-marketplace-core'), '0', true, 'number', 'ltr',
                __('صفر یعنی بی‌نهایت.', 'tecteb-marketplace-core'))
            . VendorUi::submit(__('ساخت کد تخفیف', 'tecteb-marketplace-core'))
            . '</form></section>';
    }

    /** @param callable(string|int):string $fa */
    private function ticketSection(VendorAreaView $view, int $vendorUserId, callable $fa): string
    {
        $service = $this->container->get(ManageTickets::class);
        $tickets = $service->forVendor($view->userId, $vendorUserId);
        $openId = $view->request->queryInt('ticket');

        $html = '<section class="tv-card"><h2 class="tv-card__title">'
            . esc_html__('گفتگو با مدیریت بازارگاه', 'tecteb-marketplace-core') . '</h2>';
        if ($tickets === []) {
            $html .= '<p class="tv-hint">' . esc_html__('هنوز گفتگویی باز نکرده‌اید.', 'tecteb-marketplace-core') . '</p>';
        } else {
            $html .= '<ul class="tv-list">';
            foreach ($tickets as $ticket) {
                $html .= '<li>' . VendorUi::chip(
                    $ticket->status === Ticket::ANSWERED ? 'success' : 'warning',
                    MarketplaceMessages::ticketStatus($ticket->status)
                ) . ' <a href="' . esc_url(add_query_arg('ticket', $ticket->id, $this->supportUrl())) . '">'
                    . esc_html($ticket->subject) . '</a>'
                    . ($ticket->lastReplyAt !== null
                        ? ' <span class="tv-hint">' . esc_html($fa($ticket->lastReplyAt)) . '</span>'
                        : '')
                    . '</li>';
            }
            $html .= '</ul>';
        }

        if ($openId > 0) {
            $ticket = null;
            foreach ($tickets as $candidate) {
                if ($candidate->id === $openId) {
                    $ticket = $candidate;
                }
            }
            if ($ticket !== null) {
                $html .= '<h3 class="tv-review__title">' . esc_html($ticket->subject) . '</h3><ul class="tv-thread">';
                foreach ($service->thread($view->userId, $ticket->id) as $message) {
                    $html .= '<li class="tv-thread__item tv-thread__item--' . esc_attr($message->authorRole) . '">'
                        . '<span class="tv-hint">' . esc_html($fa($message->createdAt)) . '</span> ';
                    $html .= $message->hidden
                        ? '<em>' . esc_html__('این پیام توسط مدیر پنهان شده است.', 'tecteb-marketplace-core') . '</em>'
                        : esc_html($message->visibleBody());
                    $html .= '</li>';
                }
                $html .= '</ul>';
                $html .= $ticket->acceptsReplies()
                    ? '<form method="post" class="tv-form">' . $view->nonceField
                        . '<input type="hidden" name="tmc_vendor_action" value="reply_ticket">'
                        . '<input type="hidden" name="ticket_id" value="' . esc_attr((string) $ticket->id) . '">'
                        . VendorUi::textarea('ticket_body', __('پاسخ شما', 'tecteb-marketplace-core'), '')
                        . VendorUi::submit(__('ارسال پاسخ', 'tecteb-marketplace-core'))
                        . '</form>'
                    : VendorUi::notice('info', $ticket->locked
                        ? __('این گفتگو قفل شده و پیام تازه نمی‌پذیرد.', 'tecteb-marketplace-core')
                        : __('این گفتگو بسته شده است.', 'tecteb-marketplace-core'));
            }
        }

        return $html . '<form method="post" class="tv-form">' . $view->nonceField
            . '<input type="hidden" name="tmc_vendor_action" value="open_ticket">'
            . VendorUi::input('ticket_subject', __('موضوع', 'tecteb-marketplace-core'), '')
            . VendorUi::input('ticket_order', __('شماره سفارش مرتبط (اختیاری)', 'tecteb-marketplace-core'), '', true, 'text', 'ltr')
            . VendorUi::textarea('ticket_body', __('متن پیام', 'tecteb-marketplace-core'), '')
            . '<p class="tv-hint">'
            . esc_html__('پیام‌ها پس از ارسال ویرایش نمی‌شوند. مدیر می‌تواند پیامی را با ذکر دلیل پنهان کند، ولی متن آن پاک نمی‌شود.', 'tecteb-marketplace-core')
            . '</p>'
            . VendorUi::submit(__('ثبت گفتگوی تازه', 'tecteb-marketplace-core'))
            . '</form></section>';
    }

    public function handle(string $action, Request $request, int $userId, VendorUrls $urls): ?VendorAreaOutcome
    {
        if (!in_array($action, self::ACTIONS, true)) {
            return null;
        }
        $vendorUserId = $this->container->get(StaffAccess::class)->storeFor($userId);
        if ($vendorUserId === null) {
            return new VendorAreaOutcome('not_a_vendor', $urls->dashboard());
        }
        $result = match ($action) {
            'create_coupon' => $this->container->get(ManageCoupons::class)->create(
                $userId,
                $vendorUserId,
                $request->postText('coupon_code'),
                $request->postKey('coupon_kind'),
                // Percent arrives as a whole number and is stored in basis
                // points, so «۱۰ درصد» cannot be read as «۱۰ ریال».
                $request->postKey('coupon_kind') === Coupon::PERCENT
                    ? $request->postInt('coupon_value') * 100
                    : $request->postInt('coupon_value'),
                $request->postInt('coupon_min'),
                null,
                $request->postInt('coupon_limit')
            ),
            'disable_coupon' => $this->container->get(ManageCoupons::class)->disable(
                $userId,
                $vendorUserId,
                $request->postInt('coupon_id')
            ),
            'open_ticket' => $this->container->get(ManageTickets::class)->open(
                $userId,
                $vendorUserId,
                $request->postText('ticket_subject'),
                $request->postTextarea('ticket_body'),
                $request->postText('ticket_order')
            ),
            'reply_ticket' => $this->container->get(ManageTickets::class)->reply(
                $userId,
                $request->postInt('ticket_id'),
                $request->postTextarea('ticket_body')
            ),
            default => null,
        };
        if ($result === null) {
            return null;
        }
        return new VendorAreaOutcome($result->code, $this->supportUrl(), $result->context);
    }

    public function supportUrl(): string
    {
        return (string) get_option('permalink_structure', '') !== ''
            ? home_url('/vendor/' . self::SLUG . '/')
            : home_url('/?tmc_vendor=' . self::SLUG);
    }
}
