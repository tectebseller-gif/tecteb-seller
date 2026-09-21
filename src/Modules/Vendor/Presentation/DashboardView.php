<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Presentation;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Marketplace\Presentation\ActionQueueMessages;
use Tecteb\Marketplace\Modules\Vendor\Application\TaskState;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorWorkspace;

/**
 * The vendor's first screen: where the application stands, and what they can
 * do about it right now.
 *
 * Every row that has something to do carries a button. Rows that are waiting
 * on someone else say so in a sentence and show no control, because a
 * disabled button teaches nobody anything.
 */
final class DashboardView
{
    /**
     * @param list<array{key:string, count:int, tone:string, url:string}> $queue
     *        «صف اقدام» (UX §6). Empty is the normal case and prints nothing:
     *        a queue that says «۰ سفارش تازه» teaches people to stop reading
     *        it, so only buckets with something in them appear at all.
     */
    public static function render(
        VendorWorkspace $workspace,
        VendorUrls $urls,
        ?VendorNotice $notice = null,
        array $queue = []
    ): string {
        $html = '';
        if ($notice !== null) {
            $html .= VendorUi::notice(
                VendorMessages::isErrorNotice($notice->code) ? 'warning' : 'success',
                VendorMessages::notice($notice->code, $notice->context)
            );
        }

        $status = $workspace->status();
        $hasApplication = $workspace->application !== null;

        // An approved shop opens on its WORK, not on its paperwork.
        //
        // Until `alpha.24` every vendor got the same page: application status
        // first, then the queue, then tasks. That is right for somebody still
        // applying — the application IS their job — and wrong for a shop that
        // has been selling for a month, who opens this page to find out what
        // needs doing today and had to scroll past their own approval notice
        // to see «۲ سفارش تازه».
        $approved = $workspace->isApprovedVendor();
        $statusCard = '';
        $html .= $approved ? self::todayCard($queue, $urls) : '';

        $statusCard .= '<section class="tv-card tv-card--status" aria-labelledby="tv-status">'
            . '<div class="tv-card__head">'
            . '<h2 id="tv-status" class="tv-card__title">' . esc_html__('وضعیت درخواست شما', 'tecteb-marketplace-core') . '</h2>'
            . VendorUi::chip(VendorMessages::statusTone($status), VendorMessages::status($status))
            . '</div>'
            . '<p class="tv-lead">' . esc_html(VendorMessages::statusHeadline($status, $hasApplication)) . '</p>';

        $note = $workspace->application?->reviewNote;
        if ($note !== null && trim($note) !== '' && in_array($status->value, ['changes_requested', 'rejected', 'suspended'], true)) {
            $statusCard .= '<div class="tv-note"><h3>' . esc_html__('یادداشت مدیر بازارگاه', 'tecteb-marketplace-core') . '</h3>'
                . '<p>' . esc_html($note) . '</p></div>';
        }

        if ($approved) {
            $profile = $workspace->profile;
            $statusCard .= '<dl class="tv-facts">'
                . '<div><dt>' . esc_html__('نام فروشگاه', 'tecteb-marketplace-core') . '</dt><dd>' . esc_html($profile->storeName) . '</dd></div>'
                . '<div><dt>' . esc_html__('اجازه فروش', 'tecteb-marketplace-core') . '</dt><dd>'
                . VendorUi::chip($profile->canSell ? 'success' : 'neutral', $profile->canSell ? __('صادر شده', 'tecteb-marketplace-core') : __('صادر نشده', 'tecteb-marketplace-core'))
                . '</dd></div>'
                . '<div><dt>' . esc_html__('انتشار مستقیم محصول', 'tecteb-marketplace-core') . '</dt><dd>'
                . VendorUi::chip($profile->canPublishDirectly ? 'success' : 'neutral', $profile->canPublishDirectly ? __('صادر شده', 'tecteb-marketplace-core') : __('صادر نشده', 'tecteb-marketplace-core'))
                . '</dd></div></dl>';
        }

        $primary = self::primaryAction($workspace, $urls);
        if ($primary !== '') {
            $statusCard .= '<div class="tv-actions">' . $primary . '</div>';
        }
        $statusCard .= '</section>';

        // Applicant: status, then queue. Approved shop: the work came first,
        // and the paperwork goes under the tasks where it can be checked when
        // somebody wants it rather than every time they open the page.
        if (!$approved) {
            $html .= $statusCard . self::actionQueue($queue);
        }

        $html .= '<section class="tv-card" aria-labelledby="tv-tasks">'
            . '<h2 id="tv-tasks" class="tv-card__title">' . esc_html__('کارهای شما', 'tecteb-marketplace-core') . '</h2>'
            . '<ul class="tv-tasks">';
        foreach ($workspace->tasks() as $task) {
            $text = VendorMessages::task($task);
            $html .= '<li class="tv-task tv-task--' . esc_attr($task->state->value) . '">'
                . '<div class="tv-task__head">'
                . '<h3 class="tv-task__title">' . esc_html($text['title']) . '</h3>'
                . VendorUi::chip(VendorMessages::taskStateTone($task->state), VendorMessages::taskStateLabel($task->state))
                . '</div>'
                . '<p class="tv-task__detail">' . esc_html($text['detail']) . '</p>';
            if ($task->action !== null && $text['action'] !== '') {
                $variant = $task->state === TaskState::Todo ? 'primary' : 'secondary';
                $html .= '<div class="tv-actions">' . VendorUi::button($urls->forRoute($task->action), $text['action'], $variant) . '</div>';
            }
            $html .= '</li>';
        }
        $html .= '</ul></section>';

        return $html . ($approved ? $statusCard : '');
    }

    /**
     * «کارِ امروز» — the shop's queue, at the top, with somewhere to go.
     *
     * Built from the same `ActionQueue` rows the card below used to show, so
     * no number here is computed twice; what changed is that a shop sees them
     * before anything else and, when there is nothing waiting, is told so
     * plainly instead of being shown an absence.
     *
     * @param list<array{key:string, count:int, tone:string, url:string}> $queue
     */
    private static function todayCard(array $queue, VendorUrls $urls): string
    {
        $fa = static fn (int $v): string => PersianDigits::toPersian((string) $v);
        $html = '<section class="tv-card tv-card--today" aria-labelledby="tv-today">'
            . '<div class="tv-card__head">'
            . '<h2 id="tv-today" class="tv-card__title">' . esc_html__('کارِ امروز', 'tecteb-marketplace-core') . '</h2>'
            . '</div>';

        if ($queue === []) {
            $html .= '<p class="tv-lead">'
                . esc_html__('هیچ کاری در انتظار نیست: سفارش تازه‌ای نرسیده، محصولی منتظر اصلاح نیست و موجودی هیچ کالایی رو به پایان نیست.', 'tecteb-marketplace-core')
                . '</p>';
        } else {
            $html .= '<ul class="tv-queue tv-queue--today">';
            foreach ($queue as $row) {
                $html .= '<li class="tv-queue__row"><a href="' . esc_url((string) $row['url']) . '">'
                    . VendorUi::chip((string) $row['tone'], $fa((int) $row['count']))
                    . ' ' . esc_html(ActionQueueMessages::label((string) $row['key'])) . '</a></li>';
            }
            $html .= '</ul>';
        }

        return $html . '<div class="tv-actions">'
            . VendorUi::button($urls->forRoute('orders'), __('سفارش‌ها', 'tecteb-marketplace-core'))
            . VendorUi::button($urls->products(), __('محصولات', 'tecteb-marketplace-core'), 'secondary')
            . '</div></section>';
    }

    /**
     * @param list<array{key:string, count:int, tone:string, url:string}> $queue
     */
    private static function actionQueue(array $queue): string
    {
        if ($queue === []) {
            return '';
        }
        $fa = static fn (int $v): string => PersianDigits::toPersian((string) $v);
        $html = '<section class="tv-card" aria-labelledby="tv-queue">'
            . '<h2 id="tv-queue" class="tv-card__title">'
            . esc_html__('صف اقدام', 'tecteb-marketplace-core') . '</h2><ul class="tv-queue">';
        foreach ($queue as $row) {
            $html .= '<li class="tv-queue__row"><a href="' . esc_url((string) $row['url']) . '">'
                . VendorUi::chip((string) $row['tone'], $fa((int) $row['count']))
                . ' ' . esc_html(ActionQueueMessages::label((string) $row['key'])) . '</a></li>';
        }
        return $html . '</ul></section>';
    }

    private static function primaryAction(VendorWorkspace $workspace, VendorUrls $urls): string
    {
        if ($workspace->application === null) {
            return VendorUi::button($urls->application(), __('شروع درخواست فروشندگی', 'tecteb-marketplace-core'));
        }
        return match ($workspace->status()->value) {
            'draft' => VendorUi::button($urls->application(), __('ادامه تکمیل درخواست', 'tecteb-marketplace-core')),
            'changes_requested' => VendorUi::button($urls->application(), __('اصلاح و ارسال دوباره', 'tecteb-marketplace-core')),
            'approved' => VendorUi::button($urls->application(), __('مشاهده اطلاعات ثبت‌شده', 'tecteb-marketplace-core'), 'secondary'),
            default => VendorUi::button($urls->application(), __('مشاهده درخواست', 'tecteb-marketplace-core'), 'secondary'),
        };
    }
}
