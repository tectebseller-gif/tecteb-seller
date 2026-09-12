<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Presentation;

use Tecteb\Marketplace\Core\Support\PersianDigits;
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
    public static function render(VendorWorkspace $workspace, VendorUrls $urls, string $notice = ''): string
    {
        $html = '';
        if ($notice !== '') {
            $html .= VendorUi::notice(
                VendorMessages::isErrorNotice($notice) ? 'warning' : 'success',
                VendorMessages::notice($notice)
            );
        }

        $status = $workspace->status();
        $hasApplication = $workspace->application !== null;
        $html .= '<section class="tv-card tv-card--status" aria-labelledby="tv-status">'
            . '<div class="tv-card__head">'
            . '<h2 id="tv-status" class="tv-card__title">' . esc_html__('وضعیت درخواست شما', 'tecteb-marketplace-core') . '</h2>'
            . VendorUi::chip(VendorMessages::statusTone($status), VendorMessages::status($status))
            . '</div>'
            . '<p class="tv-lead">' . esc_html(VendorMessages::statusHeadline($status, $hasApplication)) . '</p>';

        $note = $workspace->application?->reviewNote;
        if ($note !== null && trim($note) !== '' && in_array($status->value, ['changes_requested', 'rejected', 'suspended'], true)) {
            $html .= '<div class="tv-note"><h3>' . esc_html__('یادداشت مدیر بازارگاه', 'tecteb-marketplace-core') . '</h3>'
                . '<p>' . esc_html($note) . '</p></div>';
        }

        if ($workspace->isApprovedVendor()) {
            $profile = $workspace->profile;
            $html .= '<dl class="tv-facts">'
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
            $html .= '<div class="tv-actions">' . $primary . '</div>';
        }
        $html .= '</section>';

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

        return $html;
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
