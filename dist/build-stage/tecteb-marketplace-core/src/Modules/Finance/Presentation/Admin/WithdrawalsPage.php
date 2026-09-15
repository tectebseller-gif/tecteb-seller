<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Presentation\Admin;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Finance\Application\ReviewWithdrawals;
use Tecteb\Marketplace\Modules\Finance\Application\SettlementGate;
use Tecteb\Marketplace\Modules\Finance\Application\WithdrawalRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Domain\WithdrawalStateMachine;
use Tecteb\Marketplace\Modules\Finance\Domain\WithdrawalStatus;
use Tecteb\Marketplace\Modules\Finance\Presentation\WithdrawalMessages;

/**
 * The manager's settlement queue: request → review → approve → pay.
 *
 * Two things this screen refuses to do, both on purpose:
 *
 *  - It never offers a "retry" on a request that ended in
 *    `reconciliation_required`. FIN-05 forbids automatic retries on an unknown
 *    transfer outcome, and a button that repeats the transfer is exactly that
 *    with a person's finger on it. The two moves offered are "it did happen"
 *    (with the reference) and "it did not" (back to approved).
 *  - It never lets a payment be recorded without a reference. §4.4 says
 *    «پرداخت‌شده با شماره پیگیری», and a paid row with nothing to trace is
 *    indistinguishable from a mistake.
 */
final class WithdrawalsPage
{
    public const SLUG = 'tmc-withdrawals';
    public const CAPABILITY = Capabilities::REVIEW_WITHDRAWALS;
    private const NONCE = 'tmc_withdrawals';

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public static function menuLabel(): string
    {
        return __('تسویه و برداشت', 'tecteb-marketplace-core');
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('دسترسی لازم را ندارید.', 'tecteb-marketplace-core'), '', ['response' => 403]);
        }
        $notice = $this->handleAction(Request::capture());
        $repository = $this->container->get(WithdrawalRepositoryInterface::class);
        $gate = $this->container->get(SettlementGate::class)->check();
        $states = $this->container->get(WithdrawalStateMachine::class);
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);

        echo Components::shellOpen(self::menuLabel(), self::SLUG, __('نسخه آزمایشی', 'tecteb-marketplace-core'));
        if ($notice !== '') {
            echo Components::notice(str_starts_with($notice, 'ok:') ? 'success' : 'error', substr($notice, strpos($notice, ':') + 1));
        }

        echo Components::notice(
            $gate['ready'] ? 'info' : 'warning',
            $gate['ready']
                ? sprintf(
                    /* translators: %s: gate detail */
                    __('تسویه فعال است. %s', 'tecteb-marketplace-core'),
                    esc_html($gate['detail'])
                )
                : sprintf(
                    /* translators: 1: open decisions, 2: delay setting note */
                    __('درخواست برداشت تازه پذیرفته نمی‌شود تا این تصمیم‌ها بسته شوند: %1$s. مهلت چهارروزه تصمیم مصوب است و از تنظیمات خوانده می‌شود؛ آنچه تعریف نشده «لحظهٔ تکمیل سفارش» است. %2$s', 'tecteb-marketplace-core'),
                    esc_html(implode('، ', SettlementGate::OPEN_DECISIONS)),
                    esc_html($gate['detail'])
                )
        );
        echo '<p class="tmc-field__desc">'
            . esc_html__('تا آن زمان، «تکمیل برای تسویه» را مدیر روی هر قلم سفارش ثبت می‌کند (ORDER-01)؛ هیچ فرایند خودکاری این را از وضعیت ارسال حدس نمی‌زند.', 'tecteb-marketplace-core')
            . '</p>';

        $this->renderQueue($repository, $states, $fa);
        echo Components::shellClose();
    }

    private function renderQueue(WithdrawalRepositoryInterface $repository, WithdrawalStateMachine $states, callable $fa): void
    {
        $counts = $repository->countsByStatus();
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('صف درخواست‌ها', 'tecteb-marketplace-core') . '</h2>';
        $summary = [];
        foreach ($counts as $status => $count) {
            if ($count > 0) {
                $withdrawalStatus = WithdrawalStatus::tryFrom($status);
                $summary[] = WithdrawalMessages::status($withdrawalStatus ?? WithdrawalStatus::Requested)
                    . ': ' . $fa((string) $count);
            }
        }
        echo '<p class="tmc-field__desc">' . esc_html($summary === []
            ? __('هیچ درخواستی ثبت نشده است.', 'tecteb-marketplace-core')
            : implode(' · ', $summary)) . '</p>';

        $all = $repository->withStatus(null, 50);
        if ($all === []) {
            echo '</section>';
            return;
        }
        echo '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('شناسه', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('فروشنده', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('مبلغ قفل‌شده', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('اقلام', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('وضعیت', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('شماره پیگیری', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('اقدام', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($all as $withdrawal) {
            echo '<tr><td>' . Components::code((string) $withdrawal->id) . '</td>'
                . '<td>' . Components::code((string) $withdrawal->vendorUserId) . '</td>'
                . '<td>' . esc_html($fa((string) $withdrawal->amountMinor)) . '</td>'
                . '<td>' . esc_html($fa((string) $withdrawal->lineCount)) . '</td>'
                . '<td>' . esc_html(WithdrawalMessages::status($withdrawal->status)) . '</td>'
                . '<td>' . ($withdrawal->reference === '' ? '—' : Components::code($withdrawal->reference)) . '</td>'
                . '<td>' . $this->actionsFor($withdrawal->id, $withdrawal->status, $states) . '</td></tr>';
        }
        echo '</tbody></table></section>';
    }

    private function actionsFor(int $withdrawalId, WithdrawalStatus $status, WithdrawalStateMachine $states): string
    {
        $next = $states->nextFrom($status);
        if ($next === []) {
            return '—';
        }
        $html = '<form method="post" class="tmc-inline-form">';
        $html .= wp_nonce_field(self::NONCE, 'tmc_withdrawals_nonce', true, false);
        $html .= '<input type="hidden" name="withdrawal_id" value="' . esc_attr((string) $withdrawalId) . '">';
        foreach ($next as $target) {
            if ($target === WithdrawalStatus::Paid) {
                $html .= '<label class="tmc-field__label" for="ref-' . esc_attr((string) $withdrawalId) . '">'
                    . esc_html__('شماره پیگیری انتقال', 'tecteb-marketplace-core') . '</label>'
                    . '<input class="tmc-input tmc-input--short" type="text" dir="ltr" name="reference"'
                    . ' id="ref-' . esc_attr((string) $withdrawalId) . '">';
            }
            if (in_array($target, [WithdrawalStatus::Rejected, WithdrawalStatus::ReconciliationRequired], true)) {
                $html .= '<label class="tmc-field__label" for="note-' . esc_attr((string) $withdrawalId . $target->value) . '">'
                    . esc_html__('دلیل', 'tecteb-marketplace-core') . '</label>'
                    . '<input class="tmc-input" type="text" name="note_' . esc_attr($target->value) . '"'
                    . ' id="note-' . esc_attr((string) $withdrawalId . $target->value) . '">';
            }
            $html .= '<button type="submit" class="tmc-button" name="withdrawal_action" value="' . esc_attr($target->value) . '">'
                . esc_html(WithdrawalMessages::action($target)) . '</button> ';
        }
        return $html . '</form>';
    }

    private function handleAction(Request $request): string
    {
        if (!$request->isPost() || !$request->hasPost('withdrawal_action')) {
            return '';
        }
        if (!$request->nonceOk('tmc_withdrawals_nonce', self::NONCE)) {
            return 'err:' . __('درخواست معتبر نبود. صفحه را تازه کنید و دوباره تلاش کنید.', 'tecteb-marketplace-core');
        }
        $target = WithdrawalStatus::tryFrom($request->postKey('withdrawal_action'));
        $withdrawalId = (int) $request->postKey('withdrawal_id');
        if ($target === null || $withdrawalId <= 0) {
            return 'err:' . __('اقدام نامعتبر است.', 'tecteb-marketplace-core');
        }
        $review = $this->container->get(ReviewWithdrawals::class);
        $result = match ($target) {
            WithdrawalStatus::Reviewing => $review->startReview($withdrawalId),
            WithdrawalStatus::Approved => $review->approve($withdrawalId),
            WithdrawalStatus::PaymentInProgress => $review->startPayment($withdrawalId),
            WithdrawalStatus::Paid => $review->recordPayment($withdrawalId, $request->postText('reference')),
            WithdrawalStatus::Rejected => $review->reject($withdrawalId, $request->postText('note_rejected')),
            WithdrawalStatus::ReconciliationRequired => $review->needsReconciliation(
                $withdrawalId,
                $request->postText('note_reconciliation_required')
            ),
            default => null,
        };
        if ($result === null) {
            return 'err:' . __('این اقدام از این صفحه انجام نمی‌شود.', 'tecteb-marketplace-core');
        }
        return ($result->ok ? 'ok:' : 'err:') . WithdrawalMessages::outcome($result->code);
    }
}
