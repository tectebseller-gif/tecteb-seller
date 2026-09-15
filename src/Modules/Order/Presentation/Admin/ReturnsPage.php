<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Presentation\Admin;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Order\Application\ManageReturns;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Application\ReturnTerms;
use Tecteb\Marketplace\Modules\Order\Domain\ReturnRequest;
use Tecteb\Marketplace\Modules\Order\Domain\ReturnStateMachine;
use Tecteb\Marketplace\Modules\Order\Domain\ReturnStatus;
use Tecteb\Marketplace\Modules\Order\Presentation\OrderMessages;
use Tecteb\Marketplace\Modules\Order\Presentation\ReturnMessages;

/**
 * The manager's return queue — the screen that exists BECAUSE the terms are
 * not decided.
 *
 * If DEC-03 had set a window and a fee, most of this could be a rule. It has
 * not, so every request waits for a person, and the page says so at the top
 * rather than burying it: nothing here expires, nothing auto-approves, and the
 * amount offered is the goods and their tax and nothing else.
 *
 * Two refusals are built into the buttons rather than into a validator:
 *
 *  - «ثبت بازگشت مالی» appears only on a return whose goods are back, and
 *    disappears the moment it is used. A refunded return has no next move, so
 *    the page cannot ask for a second one.
 *  - The amount is never typed. It is computed from the line's own snapshot,
 *    so a manager cannot round it, and the last return on a line closes the
 *    line to the cent.
 */
final class ReturnsPage
{
    public const SLUG = 'tmc-returns';
    public const CAPABILITY = Capabilities::REVIEW_WITHDRAWALS;
    private const NONCE = 'tmc_returns';

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public static function menuLabel(): string
    {
        return __('مرجوعی و بازپرداخت', 'tecteb-marketplace-core');
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('دسترسی لازم را ندارید.', 'tecteb-marketplace-core'), '', ['response' => 403]);
        }
        $notice = $this->handleAction(Request::capture());
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);

        echo Components::shellOpen(self::menuLabel(), self::SLUG, __('نسخه آزمایشی', 'tecteb-marketplace-core'));
        if ($notice !== '') {
            echo Components::notice(
                str_starts_with($notice, 'ok:') ? 'success' : 'error',
                substr($notice, strpos($notice, ':') + 1)
            );
        }

        // Two things this page must say before anything else: what is not
        // decided, and what «بازپرداخت» does and does not do.
        echo Components::notice('warning', ReturnMessages::openTermsWarning());
        echo Components::notice('warning', ReturnMessages::refundScopeWarning());
        echo '<p class="tmc-field__desc">'
            . esc_html(sprintf(
                /* translators: %s: the open terms, as codes */
                __('موارد تعیین‌نشده: %s. تا تصمیم مالک، هر درخواست را همین‌جا و دستی تعیین تکلیف کنید.', 'tecteb-marketplace-core'),
                implode('، ', ReturnTerms::OPEN_TERMS)
            ))
            . '</p>';

        $this->renderQueue($fa);
        echo Components::shellClose();
    }

    private function renderQueue(callable $fa): void
    {
        $returns = $this->container->get(ManageReturns::class)->forManager(null, 100);
        $states = $this->container->get(ReturnStateMachine::class);
        $items = $this->container->get(OrderItemRepositoryInterface::class);

        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('درخواست‌های مرجوعی', 'tecteb-marketplace-core') . '</h2>';
        if ($returns === []) {
            echo '<p>' . esc_html__('هیچ درخواست مرجوعی ثبت نشده است.', 'tecteb-marketplace-core') . '</p></section>';
            return;
        }
        echo '<div class="tv-scroll" tabindex="0" role="region" aria-label="'
            . esc_attr__('جدول درخواست‌های مرجوعی', 'tecteb-marketplace-core') . '">';
        echo '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('شناسه', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('قلم سفارش', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('فروشنده', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('تعداد', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('وضعیت', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('بازگشت مالی', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('اقدام', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($returns as $request) {
            $item = $items->find($request->orderItemId);
            echo '<tr><td>' . Components::code((string) $request->id) . '</td>'
                . '<td>' . esc_html($item?->title ?? '—') . ' '
                . Components::code((string) $request->orderItemId) . '</td>'
                . '<td>' . Components::code((string) $request->vendorUserId) . '</td>'
                . '<td>' . esc_html($fa((string) $request->quantity))
                . ($item !== null ? esc_html(sprintf(
                    /* translators: %s: the line's whole quantity */
                    __(' از %s', 'tecteb-marketplace-core'),
                    $fa((string) $item->quantity)
                )) : '') . '</td>'
                . '<td>' . esc_html(ReturnMessages::status($request->status)) . '</td>'
                . '<td>' . $this->moneyCell($request, $fa) . '</td>'
                . '<td>' . $this->actionsFor($request, $states) . '</td></tr>';
        }
        echo '</tbody></table></div></section>';
    }

    /** @param callable(string|int):string $fa */
    private function moneyCell(ReturnRequest $request, callable $fa): string
    {
        if ($request->refundMinor === null) {
            return '<span class="tmc-field__desc">'
                . esc_html__('هنوز ثبت نشده', 'tecteb-marketplace-core') . '</span>';
        }
        return esc_html($fa(number_format($request->refundMinor)))
            . '<br><span class="tmc-field__desc">'
            . esc_html(sprintf(
                /* translators: 1: tax reversed, 2: commission reversed */
                __('مالیات %1$s · کمیسیون %2$s', 'tecteb-marketplace-core'),
                $fa(number_format((int) $request->taxRefundMinor)),
                $fa(number_format((int) $request->commissionReversedMinor))
            ))
            . '</span>';
    }

    private function actionsFor(ReturnRequest $request, ReturnStateMachine $states): string
    {
        $next = $states->nextFrom($request->status);
        if ($next === []) {
            return $request->status === ReturnStatus::Refunded
                ? '<span class="tmc-field__desc">' . esc_html__('بازپرداخت انجام شده؛ اقدام دیگری نیست.', 'tecteb-marketplace-core') . '</span>'
                : '—';
        }
        $html = '<form method="post" class="tmc-inline-form">';
        $html .= wp_nonce_field(self::NONCE, 'tmc_returns_nonce', true, false);
        $html .= '<input type="hidden" name="return_id" value="' . esc_attr((string) $request->id) . '">';
        foreach ($next as $target) {
            if ($target === ReturnStatus::Received) {
                $html .= '<label class="tmc-field__label"><input type="checkbox" name="restock" value="1" checked> '
                    . esc_html__('برگرداندن به موجودی ووکامرس', 'tecteb-marketplace-core') . '</label>';
            }
            if ($target === ReturnStatus::Refunded) {
                $html .= '<label class="tmc-field__label" for="wcref-' . esc_attr((string) $request->id) . '">'
                    . esc_html__('شناسهٔ Refund ووکامرس (اگر خودتان ساخته‌اید)', 'tecteb-marketplace-core') . '</label>'
                    . '<input class="tmc-input tmc-input--short" type="text" dir="ltr" name="wc_refund_id"'
                    . ' id="wcref-' . esc_attr((string) $request->id) . '">';
            }
            if ($target === ReturnStatus::Rejected) {
                $html .= '<label class="tmc-field__label" for="note-' . esc_attr((string) $request->id) . '">'
                    . esc_html__('دلیل', 'tecteb-marketplace-core') . '</label>'
                    . '<input class="tmc-input" type="text" name="note" id="note-' . esc_attr((string) $request->id) . '">';
            }
            $html .= '<button type="submit" class="tmc-button" name="return_action" value="' . esc_attr($target->value) . '">'
                . esc_html(ReturnMessages::action($target)) . '</button> ';
        }
        return $html . '</form>';
    }

    private function handleAction(Request $request): string
    {
        if (!$request->isPost() || !$request->hasPost('return_action')) {
            return '';
        }
        if (!$request->nonceOk('tmc_returns_nonce', self::NONCE)) {
            return 'err:' . __('درخواست معتبر نبود. صفحه را تازه کنید و دوباره تلاش کنید.', 'tecteb-marketplace-core');
        }
        $target = ReturnStatus::tryFrom($request->postKey('return_action'));
        $returnId = $request->postInt('return_id');
        if ($target === null || $returnId <= 0) {
            return 'err:' . __('اقدام نامعتبر است.', 'tecteb-marketplace-core');
        }
        $service = $this->container->get(ManageReturns::class);
        $actorId = get_current_user_id();
        $wcRefundId = $request->postInt('wc_refund_id');
        $result = $target === ReturnStatus::Refunded
            ? $service->refund($actorId, $returnId, $wcRefundId > 0 ? $wcRefundId : null)
            : $service->decide(
                $actorId,
                $returnId,
                $target,
                $request->postText('note'),
                $request->postKey('restock') === '1'
            );
        return ($result->ok ? 'ok:' : 'err:')
            . (OrderMessages::notice($result->code, $result->context)
                ?? __('نتیجه: ', 'tecteb-marketplace-core') . $result->code);
    }
}
