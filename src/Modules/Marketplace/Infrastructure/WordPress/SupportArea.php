<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Marketplace\Application\AttachTicketFile;
use Tecteb\Marketplace\Modules\Marketplace\Application\ManageCoupons;
use Tecteb\Marketplace\Modules\Marketplace\Application\ManageTickets;
use Tecteb\Marketplace\Modules\Marketplace\Domain\Coupon;
use Tecteb\Marketplace\Modules\Marketplace\Domain\Ticket;
use Tecteb\Marketplace\Modules\Marketplace\Presentation\MarketplaceMessages;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaOutcome;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaView;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUi;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;

/**
 * /vendor/support/ — this shop's own discount codes, its wholesale ladders,
 * and its conversation with the marketplace.
 *
 * Three things on one page because they are the places a shop talks outward,
 * and none is big enough to earn its own tab in the vendor's navigation.
 *
 * The ladder editor is here rather than on the product form for a reason worth
 * stating: a ladder is replaced whole (`setTiers()` takes every step at once,
 * because the steps only mean anything together), and the product form saves
 * field by field. Putting a whole-replacement control inside a field-by-field
 * form is how somebody ends up deleting a step they never looked at.
 */
final class SupportArea
{
    public const SLUG = 'support';

    /** @var list<string> */
    /**
     * ⛔ `set_tiers` IS GONE FROM HERE, AND ONLY FROM HERE (§6).
     *
     * The ladder's interface moved onto the product's own form, where the
     * product is already known and no picker is needed. The verb, the service
     * and the rows are unchanged — `ProductArea::ACTIONS` carries the name now
     * — so a stored ladder from before this round is the same ladder. Coupons
     * and tickets are untouched: «گفتگو با مدیریت، کد تخفیف و تمام امکانات
     * دیگر پشتیبانی دقیقاً مانند قبل باقی بمانند».
     */
    public const ACTIONS = ['create_coupon', 'disable_coupon', 'open_ticket', 'reply_ticket'];

    /** How many steps one ladder form offers. Three is what the spec's example uses. */

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
        // ONE line, and only the fact a vendor can act on: every tier is
        // theirs to write. The decision codes, the minimum order and the
        // ticket retention period are the owner's business and are on the
        // manager's own page — «در … پیشخوان، کدهایی مثل DEC-04/DEC-05 …
        // نمایش داده نشود» (§3). Nothing was decided and no gate was opened.
        $html .= VendorUi::notice('info', MarketplaceMessages::vendorTierHint());
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
                    // A hidden message hides its files with it: the attachment
                    // belongs to what somebody said, not to the thread.
                    if (!$message->hidden) {
                        $html .= $this->fileList($view->userId, $ticket->id, $message->id, $fa);
                    }
                    $html .= '</li>';
                }
                $html .= '</ul>';
                $html .= $ticket->acceptsReplies()
                    // enctype, because this form can carry a file. Without it
                    // the browser posts only the field NAMES and the upload
                    // arrives as nothing at all.
                    ? '<form method="post" class="tv-form" enctype="multipart/form-data">' . $view->nonceField
                        . '<input type="hidden" name="tmc_vendor_action" value="reply_ticket">'
                        . '<input type="hidden" name="ticket_id" value="' . esc_attr((string) $ticket->id) . '">'
                        . VendorUi::textarea('ticket_body', __('پاسخ شما', 'tecteb-marketplace-core'), '')
                        . $this->fileField()
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

    /**
     * The file input on a reply, and the rules said before they are hit.
     *
     * The limits are printed rather than discovered by failing: a person who
     * has just typed a paragraph and attached a 9MB photo should be told the
     * limit before they press the button, not after.
     */
    private function fileField(): string
    {
        return '<div class="tv-field">'
            . '<label class="tv-label" for="f-ticket_file">'
            . esc_html__('پیوست (اختیاری)', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tv-input" type="file" id="f-ticket_file" name="ticket_file"'
            . ' accept="' . esc_attr(implode(',', AttachTicketFile::ALLOWED_MIME)) . '">'
            . '<p class="tv-hint">'
            . esc_html(sprintf(
                /* translators: 1: size limit in megabytes, 2: how many files per message */
                __('تصویر، PDF یا متن ساده؛ حداکثر %1$s مگابایت و %2$s فایل برای هر پیام. فایل بیرون از دسترس وب ذخیره می‌شود و فقط طرف‌های همین گفتگو بازش می‌کنند.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) (AttachTicketFile::MAX_BYTES / 1024 / 1024)),
                PersianDigits::toPersian((string) AttachTicketFile::MAX_PER_MESSAGE)
            ))
            . '</p></div>';
    }

    /**
     * The files hanging off one message.
     *
     * Each link carries its own nonce and goes through the one route that
     * checks who is asking — there is no path here that prints a file's
     * location, because a private file has no URL to print.
     *
     * @param callable(string|int):string $fa
     */
    private function fileList(int $userId, int $ticketId, int $messageId, callable $fa): string
    {
        $files = $this->container->get(AttachTicketFile::class)->forMessage($ticketId, $messageId);
        if ($files === []) {
            return '';
        }
        $html = '<ul class="tv-files">';
        foreach ($files as $file) {
            if ($file->hidden) {
                $html .= '<li class="tv-file tv-file--hidden"><span class="tv-file__name">'
                    . esc_html__('این فایل توسط مدیر پنهان شده است.', 'tecteb-marketplace-core')
                    . '</span></li>';
                continue;
            }
            $html .= '<li class="tv-file">'
                . '<a class="tv-file__name" href="' . esc_url(TicketFileRoute::url($file->id)) . '">'
                . esc_html($file->visibleName()) . '</a>'
                . '<span class="tv-file__size">'
                . esc_html($fa(number_format((int) ceil($file->sizeBytes / 1024))))
                . ' ' . esc_html__('کیلوبایت', 'tecteb-marketplace-core') . '</span>'
                . '</li>';
        }
        return $html . '</ul>';
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
        // A file is attached to the message that was just written, and only if
        // that write succeeded: an attachment with no message to hang from
        // would be unreachable and unmoderatable. A refused file does NOT undo
        // the message — the person said something and it stands; what they
        // get is the reason their file did not go with it.
        if ($result->ok && in_array($action, ['open_ticket', 'reply_ticket'], true)
            && $request->file('ticket_file')->sizeBytes > 0) {
            $attached = $this->attach($request, $userId, $result);
            if ($attached !== null) {
                return $attached;
            }
        }
        // ⛔ The ladder's own «come back to that product» branch left with it:
        // `ProductArea` sends a tier save to step 5 of the product, which the
        // address already names. Every remaining action on this page is
        // shop-wide and comes back here, as it always did.
        return new VendorAreaOutcome($result->code, $this->supportUrl(), $result->context);
    }

    /**
     * Attaches the posted file to the message this action just created.
     *
     * Returns the outcome to show when the file was REFUSED, and null when it
     * went through — so a successful attachment leaves the caller's own
     * «ثبت شد» message alone rather than replacing it with a second one.
     */
    private function attach(Request $request, int $userId, OperationResult $result): ?VendorAreaOutcome
    {
        $ticketId = (int) ($result->context['ticket_id'] ?? 0);
        $messageId = (int) ($result->context['message_id'] ?? 0);
        if ($ticketId <= 0 || $messageId <= 0) {
            return null;
        }
        $attached = $this->container->get(AttachTicketFile::class)
            ->attach($userId, $ticketId, $messageId, $request->file('ticket_file'));
        if ($attached->ok) {
            return null;
        }
        return new VendorAreaOutcome(
            $attached->code,
            add_query_arg('ticket', (string) $ticketId, $this->supportUrl()),
            $attached->context
        );
    }

    public function supportUrl(): string
    {
        return (string) get_option('permalink_structure', '') !== ''
            ? home_url('/vendor/' . self::SLUG . '/')
            : home_url('/?tmc_vendor=' . self::SLUG);
    }
}
