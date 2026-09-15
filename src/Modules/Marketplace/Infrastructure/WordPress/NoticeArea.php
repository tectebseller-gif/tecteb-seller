<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Marketplace\Application\Notify;
use Tecteb\Marketplace\Modules\Marketplace\Application\Reports;
use Tecteb\Marketplace\Modules\Marketplace\Presentation\MarketplaceMessages;
use Tecteb\Marketplace\Modules\Marketplace\Presentation\NoticeMessages;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaOutcome;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaView;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUi;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;

/**
 * /vendor/notices/ — «اطلاعیه‌ها» and «گزارش‌ها», the two UX §4.1 rows that had
 * no screen at all.
 *
 * On one page because they answer the same question from two directions: what
 * happened that I should know about, and what do my numbers look like. Neither
 * is big enough for its own tab, and a vendor who opens one almost always
 * wants the other.
 *
 * **Read/unread is per person, not per shop.** Two members of the same shop
 * see the same events and each marks their own copy — a shared row would mark
 * a notice read for five people when one of them looked.
 *
 * **Nothing here is sent anywhere.** Alpha has no sender and no gateway, so
 * «اطلاعیه» means a row this page shows. Saying «برایتان ایمیل شد» would be
 * exactly the kind of claim this plugin refuses to make.
 */
final class NoticeArea
{
    public const SLUG = 'notices';

    /** @var list<string> */
    public const ACTIONS = ['read_notice', 'read_all_notices'];

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
                MarketplaceMessages::notice($view->notice->code, $view->notice->context) ?? $view->notice->code
            );
        }
        return $html
            . $this->noticeSection($view, $fa)
            . $this->reportSection($view, $vendorUserId, $fa);
    }

    /** @param callable(string|int):string $fa */
    private function noticeSection(VendorAreaView $view, callable $fa): string
    {
        $notify = $this->container->get(Notify::class);
        $notices = $notify->inbox($view->userId);
        $unread = $notify->unreadCount($view->userId);

        $html = '<section class="tv-card"><h2 class="tv-card__title">'
            . esc_html__('اطلاعیه‌ها', 'tecteb-marketplace-core') . '</h2>'
            . '<p class="tv-hint">'
            . esc_html__('این اعلان‌ها فقط در همین پنل نشان داده می‌شوند. در این نسخه هیچ ایمیل و پیامکی فرستاده نمی‌شود.', 'tecteb-marketplace-core')
            . '</p>';

        if ($notices === []) {
            return $html . '<p>' . esc_html__('اعلان تازه‌ای ندارید.', 'tecteb-marketplace-core')
                . '</p></section>';
        }

        if ($unread > 0) {
            $html .= '<form method="post" class="tv-inline">' . $view->nonceField
                . '<input type="hidden" name="tmc_vendor_action" value="read_all_notices">'
                . VendorUi::submit(sprintf(
                    /* translators: %s: how many are unread */
                    __('خوانده‌شدن همهٔ %s اعلان', 'tecteb-marketplace-core'),
                    $fa((string) $unread)
                ), 'secondary')
                . '</form>';
        }

        $html .= '<ul class="tv-notices">';
        foreach ($notices as $notice) {
            $html .= '<li class="tv-notice' . ($notice->isUnread() ? ' tv-notice--unread' : '') . '">'
                . '<p class="tv-notice__text">'
                . esc_html(NoticeMessages::sentence($notice->event, $notice->context))
                . '</p>'
                . '<p class="tv-notice__meta"><time datetime="' . esc_attr($notice->createdAt) . '">'
                . esc_html($fa($notice->createdAt)) . '</time>';
            if ($notice->isUnread()) {
                $html .= ' <span class="tv-badge">' . esc_html__('خوانده‌نشده', 'tecteb-marketplace-core') . '</span>';
            }
            $html .= '</p>';
            if ($notice->isUnread()) {
                $html .= '<form method="post" class="tv-inline">' . $view->nonceField
                    . '<input type="hidden" name="tmc_vendor_action" value="read_notice">'
                    . '<input type="hidden" name="notice_id" value="' . esc_attr((string) $notice->id) . '">'
                    . VendorUi::submit(__('خوانده شد', 'tecteb-marketplace-core'), 'secondary')
                    . '</form>';
            }
            $html .= '</li>';
        }
        return $html . '</ul></section>';
    }

    /** @param callable(string|int):string $fa */
    private function reportSection(VendorAreaView $view, int $vendorUserId, callable $fa): string
    {
        $reports = $this->container->get(Reports::class)->forVendor($view->userId, $vendorUserId);
        $html = '<section class="tv-card"><h2 class="tv-card__title">'
            . esc_html__('گزارش‌ها', 'tecteb-marketplace-core') . '</h2>'
            . '<p class="tv-hint">'
            . esc_html__('هر عدد همین لحظه از روی رکوردهای خود شما حساب می‌شود؛ هیچ‌کدام از جای دیگری ذخیره و بازخوانی نمی‌شود.', 'tecteb-marketplace-core')
            . '</p>';
        if ($reports === []) {
            return $html . '<p>' . esc_html__('گزارشی در دسترس نیست.', 'tecteb-marketplace-core') . '</p></section>';
        }

        $hidden = NoticeMessages::hiddenFigures();
        foreach ($reports as $key => $figures) {
            $html .= '<h3 class="tv-subtitle">' . esc_html(NoticeMessages::reportTitle($key)) . '</h3>';
            if ((int) ($figures['available'] ?? 0) !== 1) {
                $html .= '<p class="tv-hint">'
                    . esc_html__('این بخش در حال حاضر اجرا نمی‌شود، پس عددی برایش نیست. صفر نوشتن دروغ می‌شد.', 'tecteb-marketplace-core')
                    . '</p>';
                continue;
            }
            $html .= '<dl class="tv-figures">';
            foreach ($figures as $figure => $value) {
                if (in_array((string) $figure, $hidden, true)) {
                    continue;
                }
                $shown = NoticeMessages::isMoney((string) $figure)
                    ? $fa(number_format((int) $value / 100)) . ' ' . __('تومان', 'tecteb-marketplace-core')
                    : $fa((string) $value);
                $html .= '<div class="tv-figure"><dt>' . esc_html(NoticeMessages::figure((string) $figure))
                    . '</dt><dd>' . esc_html($shown) . '</dd></div>';
            }
            $html .= '</dl>';
            if ($key === Reports::FINANCE && (int) ($figures['unrecorded_lines'] ?? 0) > 0) {
                $html .= '<p class="tv-hint">' . esc_html(NoticeMessages::unrecordedNote()) . '</p>';
            }
            if ($key === Reports::STOCK) {
                $html .= '<p class="tv-hint">' . esc_html(sprintf(
                    /* translators: %s: the low-stock threshold */
                    __('«رو به اتمام» یعنی کمتر از %s عدد مانده.', 'tecteb-marketplace-core'),
                    $fa((string) ($figures['threshold'] ?? ''))
                )) . '</p>';
            }
        }
        return $html . '</section>';
    }

    public function handle(string $action, Request $request, int $userId, VendorUrls $urls): ?VendorAreaOutcome
    {
        if (!in_array($action, self::ACTIONS, true)) {
            return null;
        }
        $notify = $this->container->get(Notify::class);
        // Each arm parenthesised: two ternaries in one expression is the
        // left-associativity PHP 8.0 removed, and the 8.1 compatibility check
        // fails the build for it (tools/lint.sh runs on every version this
        // plugin claims).
        $code = match ($action) {
            'read_notice' => ($notify->markRead($userId, $request->postInt('notice_id'))
                ? 'notice_read'
                : 'not_found'),
            // «همه را خوانده‌شده کن» with nothing unread is a success with
            // zero rows, not a failure — the same rule as `execute()`
            // answering 0.
            'read_all_notices' => 'notices_all_read',
            default => null,
        };
        if ($action === 'read_all_notices') {
            $notify->markAllRead($userId);
        }
        return $code === null ? null : new VendorAreaOutcome($code, $this->noticesUrl());
    }

    public function noticesUrl(): string
    {
        return (string) get_option('permalink_structure', '') !== ''
            ? home_url('/vendor/' . self::SLUG . '/')
            : home_url('/?tmc_vendor=' . self::SLUG);
    }
}
