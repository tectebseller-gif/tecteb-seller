<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Presentation;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Finance\Domain\Withdrawal;
use Tecteb\Marketplace\Modules\Finance\Domain\WithdrawalStatus;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorNotice;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUi;

/**
 * `/vendor/finance/` — what this shop has earned, and what it may ask for.
 *
 * Four numbers, never merged into one: earned, waiting, locked in a request,
 * and already paid. A vendor looking at a single «مانده» cannot tell whether
 * the marketplace owes them something it has not confirmed yet or something
 * it has confirmed and is holding, and those need different actions from
 * them.
 *
 * The request button carries no amount field on purpose (A.2): one request
 * takes the whole eligible balance, so there is nothing to argue about later.
 */
final class VendorFinanceView
{
    /**
     * @param array<string,mixed> $balance from VendorBalance::of()
     * @param list<Withdrawal> $history
     */
    public static function render(
        array $balance,
        ?Withdrawal $open,
        array $history,
        string $financeUrl,
        string $nonceField,
        ?VendorNotice $notice,
        bool $mayRequest,
        bool $settlementReady,
        string $blockedDetail
    ): string {
        $fa = static fn (int|string $v): string => PersianDigits::toPersian((string) $v);
        $html = $notice === null ? '' : VendorUi::notice(
            WithdrawalMessages::isRefusal($notice->code) ? 'warning' : 'success',
            WithdrawalMessages::outcome($notice->code)
        );

        $html .= '<section class="tv-card"><h2 class="tv-card__title">'
            . esc_html__('مانده شما', 'tecteb-marketplace-core') . '</h2>';
        $html .= '<div class="tv-facts">'
            . self::stat(__('کل درآمد ثبت‌شده', 'tecteb-marketplace-core'), $fa((int) $balance['earned']))
            . self::stat(__('قابل برداشت', 'tecteb-marketplace-core'), $fa((int) $balance['eligible']))
            . self::stat(__('در انتظار', 'tecteb-marketplace-core'), $fa((int) $balance['pending']))
            . self::stat(__('قفل‌شده در درخواست', 'tecteb-marketplace-core'), $fa((int) $balance['reserved']))
            . self::stat(__('پرداخت‌شده', 'tecteb-marketplace-core'), $fa((int) $balance['paid']))
            . '</div>';

        $pending = WithdrawalMessages::pendingReason(
            (int) $balance['awaiting_completion'],
            (int) $balance['awaiting_delay'],
            (int) $balance['delay_days'],
            (int) ($balance['awaiting_return'] ?? 0)
        );
        if ($pending !== '') {
            $html .= '<p class="tv-hint">' . esc_html($pending) . '</p>';
        }
        if ((int) $balance['unrecorded'] > 0) {
            // FIN-02: a share that could not be computed is not zero, and the
            // vendor is told rather than quietly short-changed.
            $html .= VendorUi::notice('warning', sprintf(
                /* translators: %s: number of order lines */
                __('برای %s قلم فروش، سهم شما هنوز ثبت نشده است. این «صفر» نیست؛ با پشتیبانی تماس بگیرید.', 'tecteb-marketplace-core'),
                $fa((int) $balance['unrecorded'])
            ));
        }
        $html .= '</section>';

        $html .= '<section class="tv-card"><h2 class="tv-card__title">'
            . esc_html__('درخواست برداشت', 'tecteb-marketplace-core') . '</h2>';
        if (!$settlementReady) {
            $html .= VendorUi::notice('info', __('برداشت هنوز فعال نشده است. مانده شما محفوظ است و به محض فعال‌شدن قابل درخواست می‌شود.', 'tecteb-marketplace-core'));
            unset($blockedDetail);      // the vendor is not told which decision is open
        } elseif ($open !== null) {
            $html .= VendorUi::notice('info', sprintf(
                /* translators: 1: amount, 2: status */
                __('یک درخواست باز به مبلغ %1$s دارید؛ وضعیت: %2$s. تا تعیین تکلیف آن، درخواست تازه ثبت نمی‌شود.', 'tecteb-marketplace-core'),
                $fa($open->amountMinor),
                WithdrawalMessages::status($open->status)
            ));
            if ($mayRequest && $open->status === WithdrawalStatus::Requested) {
                $html .= '<form method="post" action="' . esc_url($financeUrl) . '">' . $nonceField
                    . '<input type="hidden" name="tmc_vendor_action" value="cancel_withdrawal">'
                    . '<input type="hidden" name="withdrawal_id" value="' . esc_attr((string) $open->id) . '">'
                    . '<button type="submit" class="tv-btn">' . esc_html__('لغو درخواست', 'tecteb-marketplace-core')
                    . '</button></form>';
            }
        } elseif (!$mayRequest) {
            $html .= VendorUi::notice('info', __('نقش شما اجازهٔ درخواست برداشت ندارد.', 'tecteb-marketplace-core'));
        } elseif ((int) $balance['eligible'] <= 0) {
            $html .= '<p>' . esc_html__('در این لحظه مبلغ قابل برداشتی ندارید.', 'tecteb-marketplace-core') . '</p>';
        } else {
            $html .= '<p>' . esc_html(sprintf(
                /* translators: %s: amount */
                __('با یک درخواست، کل مبلغ قابل برداشت (%s) همان لحظه قفل می‌شود. مبلغ دلخواه وارد نمی‌شود.', 'tecteb-marketplace-core'),
                $fa((int) $balance['eligible'])
            )) . '</p>'
                . '<form method="post" action="' . esc_url($financeUrl) . '">' . $nonceField
                . '<input type="hidden" name="tmc_vendor_action" value="request_withdrawal">'
                . '<button type="submit" class="tv-btn tv-btn--primary">'
                . esc_html__('درخواست برداشت کل مانده', 'tecteb-marketplace-core') . '</button></form>';
        }
        $html .= '</section>';

        $html .= self::history($history, $fa);
        return $html;
    }

    /** @param list<Withdrawal> $history */
    private static function history(array $history, callable $fa): string
    {
        if ($history === []) {
            return '';
        }
        $html = '<section class="tv-card"><h2 class="tv-card__title">'
            . esc_html__('تاریخچهٔ برداشت', 'tecteb-marketplace-core') . '</h2>'
            // `tv-scroll` is the stylesheet's existing answer for a table
            // that cannot narrow further: it scrolls on its own instead of
            // pushing the whole page sideways at 320px. It carries tabindex
            // and a label because a region that only a mouse can scroll is
            // unreachable by keyboard — axe's scrollable-region-focusable,
            // which caught exactly this at 320 and 375.
            . '<div class="tv-scroll" tabindex="0" role="region" aria-label="'
            . esc_attr__('تاریخچهٔ برداشت', 'tecteb-marketplace-core') . '">'
            . '<table class="tv-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('تاریخ', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('مبلغ', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('وضعیت', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('شماره پیگیری', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($history as $withdrawal) {
            $html .= '<tr><td>' . esc_html($fa($withdrawal->createdAt)) . '</td>'
                . '<td>' . esc_html($fa($withdrawal->amountMinor)) . '</td>'
                . '<td>' . esc_html(WithdrawalMessages::status($withdrawal->status)) . '</td>'
                . '<td>' . ($withdrawal->reference === '' ? '—' : esc_html($withdrawal->reference)) . '</td></tr>';
        }
        return $html . '</tbody></table></div></section>';
    }

    /** One label/value pair in the `tv-facts` grid the dashboard already uses. */
    private static function stat(string $label, string $value): string
    {
        return '<div><span class="tv-hint">' . esc_html($label) . '</span>'
            . '<strong>' . esc_html($value) . '</strong></div>';
    }
}
