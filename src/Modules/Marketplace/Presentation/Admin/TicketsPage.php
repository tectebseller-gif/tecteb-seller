<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Presentation\Admin;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Marketplace\Application\ManageTickets;
use Tecteb\Marketplace\Modules\Marketplace\Domain\Ticket;
use Tecteb\Marketplace\Modules\Marketplace\Presentation\MarketplaceMessages;

/**
 * The marketplace's side of the vendor conversation.
 *
 * What the manager can do here is deliberately short: reply, close or lock the
 * thread, and hide a message with a reason. There is no edit button, because
 * UX §10.1 says messages are not edited — and a screen that offered one would
 * be the feature, whatever the service underneath refused.
 */
final class TicketsPage
{
    public const SLUG = 'tmc-tickets';
    public const CAPABILITY = Capabilities::REVIEW_VENDOR;
    private const NONCE = 'tmc_tickets';

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public static function menuLabel(): string
    {
        return __('تیکت فروشندگان', 'tecteb-marketplace-core');
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('دسترسی لازم را ندارید.', 'tecteb-marketplace-core'), '', ['response' => 403]);
        }
        $request = Request::capture();
        $notice = $this->handleAction($request);
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);
        $service = $this->container->get(ManageTickets::class);

        echo Components::shellOpen(self::menuLabel(), self::SLUG, __('نسخه آزمایشی', 'tecteb-marketplace-core'));
        if ($notice !== '') {
            echo Components::notice(
                str_starts_with($notice, 'ok:') ? 'success' : 'error',
                substr($notice, strpos($notice, ':') + 1)
            );
        }
        echo Components::notice('info', sprintf(
            /* translators: %s: the decision reference */
            __('مدت نگهداری گفتگوها در %s تعیین نشده است؛ هیچ گفتگویی خودکار حذف نمی‌شود.', 'tecteb-marketplace-core'),
            ManageTickets::DECISION
        ));

        $openId = $request->queryInt('ticket');
        if ($openId > 0) {
            $this->renderThread($service, $openId, $fa);
        }
        $this->renderQueue($service, $fa);
        echo Components::shellClose();
    }

    private function renderQueue(ManageTickets $service, callable $fa): void
    {
        $tickets = $service->forManager();
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('گفتگوها', 'tecteb-marketplace-core') . '</h2>';
        if ($tickets === []) {
            echo '<p>' . esc_html__('هیچ گفتگویی ثبت نشده است.', 'tecteb-marketplace-core') . '</p></section>';
            return;
        }
        echo '<div class="tv-scroll" tabindex="0" role="region" aria-label="'
            . esc_attr__('جدول گفتگوها', 'tecteb-marketplace-core') . '">';
        echo '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('شناسه', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('فروشنده', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('موضوع', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('وضعیت', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('آخرین پاسخ', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($tickets as $ticket) {
            echo '<tr><td>' . Components::code((string) $ticket->id) . '</td>'
                . '<td>' . Components::code((string) $ticket->vendorUserId) . '</td>'
                . '<td><a href="' . esc_url(add_query_arg('ticket', $ticket->id)) . '">'
                . esc_html($ticket->subject) . '</a></td>'
                . '<td>' . esc_html(MarketplaceMessages::ticketStatus($ticket->status))
                . ($ticket->locked ? ' — ' . esc_html__('قفل', 'tecteb-marketplace-core') : '') . '</td>'
                . '<td>' . esc_html($ticket->lastReplyAt === null ? '—' : $fa($ticket->lastReplyAt)) . '</td></tr>';
        }
        echo '</tbody></table></div></section>';
    }

    private function renderThread(ManageTickets $service, int $ticketId, callable $fa): void
    {
        $messages = $service->thread(get_current_user_id(), $ticketId);
        if ($messages === []) {
            return;
        }
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html(sprintf(__('گفتگوی %s', 'tecteb-marketplace-core'), $fa((string) $ticketId)))
            . '</h2><ul class="tmc-thread">';
        foreach ($messages as $message) {
            echo '<li><strong>' . esc_html($message->authorRole === Ticket::ROLE_MANAGER
                ? __('بازارگاه', 'tecteb-marketplace-core')
                : __('فروشنده', 'tecteb-marketplace-core')) . '</strong> '
                . '<span class="tmc-field__desc">' . esc_html($fa($message->createdAt)) . '</span><br>';
            if ($message->hidden) {
                echo '<em>' . esc_html(sprintf(
                    /* translators: %s: the reason a manager gave */
                    __('پنهان‌شده — دلیل: %s', 'tecteb-marketplace-core'),
                    $message->hiddenReason
                )) . '</em>';
            } else {
                echo esc_html($message->body);
                echo '<form method="post" class="tmc-inline-form">'
                    . wp_nonce_field(self::NONCE, 'tmc_tickets_nonce', true, false)
                    . '<input type="hidden" name="ticket_id" value="' . esc_attr((string) $ticketId) . '">'
                    . '<input type="hidden" name="message_id" value="' . esc_attr((string) $message->id) . '">'
                    . '<label class="tmc-field__label" for="hide-' . esc_attr((string) $message->id) . '">'
                    . esc_html__('دلیل پنهان‌کردن', 'tecteb-marketplace-core') . '</label>'
                    . '<input class="tmc-input" type="text" name="hide_reason" id="hide-' . esc_attr((string) $message->id) . '">'
                    . '<button type="submit" class="tmc-button" name="ticket_action" value="hide">'
                    . esc_html__('پنهان‌کردن', 'tecteb-marketplace-core') . '</button>'
                    . '</form>';
            }
            echo '</li>';
        }
        echo '</ul>';
        echo '<form method="post">'
            . wp_nonce_field(self::NONCE, 'tmc_tickets_nonce', true, false)
            . '<input type="hidden" name="ticket_id" value="' . esc_attr((string) $ticketId) . '">'
            . '<label class="tmc-field__label" for="reply-' . esc_attr((string) $ticketId) . '">'
            . esc_html__('پاسخ بازارگاه', 'tecteb-marketplace-core') . '</label>'
            . '<textarea class="tmc-input" name="reply_body" id="reply-' . esc_attr((string) $ticketId) . '" rows="3"></textarea>'
            . '<p><button type="submit" class="tmc-button" name="ticket_action" value="reply">'
            . esc_html__('ارسال پاسخ', 'tecteb-marketplace-core') . '</button> '
            . '<button type="submit" class="tmc-button" name="ticket_action" value="close">'
            . esc_html__('بستن گفتگو', 'tecteb-marketplace-core') . '</button> '
            . '<button type="submit" class="tmc-button" name="ticket_action" value="lock">'
            . esc_html__('قفل‌کردن گفتگو', 'tecteb-marketplace-core') . '</button></p>'
            . '</form></section>';
    }

    private function handleAction(Request $request): string
    {
        if (!$request->isPost() || !$request->hasPost('ticket_action')) {
            return '';
        }
        if (!$request->nonceOk('tmc_tickets_nonce', self::NONCE)) {
            return 'err:' . __('درخواست معتبر نبود. صفحه را تازه کنید و دوباره تلاش کنید.', 'tecteb-marketplace-core');
        }
        $service = $this->container->get(ManageTickets::class);
        $actorId = get_current_user_id();
        $ticketId = $request->postInt('ticket_id');
        $result = match ($request->postKey('ticket_action')) {
            'reply' => $service->reply($actorId, $ticketId, $request->postTextarea('reply_body'), true),
            'close' => $service->setState($actorId, $ticketId, Ticket::CLOSED, false),
            'lock' => $service->setState($actorId, $ticketId, Ticket::OPEN, true),
            'hide' => $service->hideMessage($actorId, $request->postInt('message_id'), $request->postText('hide_reason')),
            default => null,
        };
        if ($result === null) {
            return 'err:' . __('این اقدام از این صفحه انجام نمی‌شود.', 'tecteb-marketplace-core');
        }
        return ($result->ok ? 'ok:' : 'err:')
            . (MarketplaceMessages::notice($result->code, $result->context) ?? $result->code);
    }
}
