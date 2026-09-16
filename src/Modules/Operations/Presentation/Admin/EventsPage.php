<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Operations\Presentation\Admin;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Contracts\JobRepositoryInterface;
use Tecteb\Marketplace\Contracts\OutboxRepositoryInterface;
use Tecteb\Marketplace\Core\Events\EventSigner;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Infrastructure\WordPress\WpJobScheduler;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Operations\Application\DeliverEventsJob;
use Tecteb\Marketplace\Modules\Operations\Application\RecordEvent;
use Tecteb\Marketplace\Modules\Operations\Infrastructure\Rest\MarketplaceController;
use Tecteb\Marketplace\Modules\Operations\Presentation\OperationsMessages;

/**
 * «رویدادها و API» — the contract this build publishes, and every event it has
 * recorded but not sent.
 *
 * The page says the uncomfortable thing first and in a box nobody can miss:
 * **nothing is delivered outward from this release**. `OutboundPolicy` blocks
 * every channel and cannot be opened by an option, a constant or a filter, so a
 * «بسته» row is the system working, not a fault. A manager who found that out
 * from a support ticket instead of from this page would be right to be angry.
 *
 * What IS real here, today, and visible on the screen: the event types, their
 * fields, the signature over each recorded payload, and a read API scoped to
 * one store. Those are the parts an integrator can build against before there
 * is anything to receive — which is the whole reason the contract is published
 * while delivery is not.
 */
final class EventsPage
{
    public const SLUG = 'tmc-events';
    public const CAPABILITY = Capabilities::MANAGE_API;
    private const NONCE = 'tmc_events_action';
    private const PER_PAGE = 25;

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public static function menuLabel(): string
    {
        return __('رویدادها و API', 'tecteb-marketplace-core');
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('اجازهٔ دیدن این صفحه را ندارید.', 'tecteb-marketplace-core'));
        }
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);
        $request = Request::capture();
        $message = $this->handleAction($request);

        echo Components::shellOpen(self::menuLabel(), self::SLUG);
        if ($message !== '') {
            [$type, $text] = explode(':', $message, 2);
            echo Components::notice($type === 'ok' ? 'success' : 'warning', $text);
        }

        echo Components::notice('warning', __('از این نسخه هیچ رویدادی به بیرون فرستاده نمی‌شود. قفل ارسال بیرونی (CORE-04) نه با گزینه، نه با ثابت و نه با فیلتر باز نمی‌شود؛ پس ردیفی که «ارسال بسته است» می‌گیرد، درست کار کرده. رویداد ثبت و امضا می‌شود و همین‌جا می‌ماند تا روزی که مقصد واقعی و مجوزش وجود داشته باشد.', 'tecteb-marketplace-core'));

        $this->renderContract();
        $this->renderCensus($fa);
        $this->renderList($request, $fa);
        echo Components::shellClose();
    }

    private function renderContract(): void
    {
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('قراردادی که این نسخه منتشر می‌کند', 'tecteb-marketplace-core') . '</h2>';
        echo Components::dataList([
            ['label' => __('فضای نام API', 'tecteb-marketplace-core'), 'value' => MarketplaceController::NAMESPACE],
            ['label' => __('نوع دسترسی', 'tecteb-marketplace-core'), 'value' => __('فقط خواندن', 'tecteb-marketplace-core')],
            ['label' => __('احراز هویت', 'tecteb-marketplace-core'), 'value' => __('کاربر وردپرس (برای اسکریپت: رمز کاربردی)', 'tecteb-marketplace-core')],
            ['label' => __('اجازه', 'tecteb-marketplace-core'), 'value' => __('همان دسترسی پرسنلی پنل، برای همان فروشگاه', 'tecteb-marketplace-core')],
            ['label' => __('الگوریتم امضا', 'tecteb-marketplace-core'), 'value' => EventSigner::ALGORITHM . ' (' . EventSigner::VERSION . ')'],
            ['label' => __('سرایند امضا', 'tecteb-marketplace-core'), 'value' => EventSigner::HEADER],
        ]);
        echo '<p class="tmc-field__desc">'
            . esc_html__('کلید امضا هیچ‌جا نشان داده نمی‌شود — نه در این صفحه، نه در ممیزی، نه در پاسخ API. آنچه یک توسعه‌دهنده لازم دارد الگوریتم است، نه کلید.', 'tecteb-marketplace-core')
            . '</p>';
        echo '<h3 class="tmc-card__title">' . esc_html__('رویدادهایی که ثبت می‌شوند', 'tecteb-marketplace-core') . '</h3>';
        echo '<div class="tmc-scroll" tabindex="0" role="region" aria-label="'
            . esc_attr__('فهرست رویدادها', 'tecteb-marketplace-core') . '">'
            . '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('رویداد', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('فیلدهایی که می‌برد', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach (RecordEvent::SCHEMA as $type => $fields) {
            echo '<tr><td data-label="' . esc_attr__('رویداد', 'tecteb-marketplace-core') . '">'
                . Components::code((string) $type) . '</td>'
                . '<td data-label="' . esc_attr__('فیلدهایی که می‌برد', 'tecteb-marketplace-core') . '">'
                . Components::codes($fields) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="tmc-field__desc">'
            . esc_html__('فیلدی که در این جدول نیست، فرستاده نمی‌شود. هر رویداد فهرست خودش را دارد تا چیزی که ماه بعد به یک شیء اضافه شود، خودبه‌خود روی سیم نرود.', 'tecteb-marketplace-core')
            . '</p></section>';
    }

    /** @param callable(string|int):string $fa */
    private function renderCensus(callable $fa): void
    {
        $census = $this->outbox()->census();
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('وضعیت ثبت و ارسال', 'tecteb-marketplace-core') . '</h2>';
        $rows = [];
        foreach (['recorded', 'blocked', 'sent', 'failed'] as $state) {
            $rows[] = [
                'label' => OperationsMessages::deliveryState($state),
                'value' => $fa((string) ($census[$state] ?? 0)),
            ];
        }
        echo Components::dataList($rows);
        echo '<form method="post" class="tmc-inline-form">'
            . wp_nonce_field(self::NONCE, 'tmc_events_nonce', true, false)
            . '<button type="submit" class="tmc-button" name="events_action" value="queue_delivery">'
            . esc_html__('تعیین تکلیف رویدادهای ثبت‌شده', 'tecteb-marketplace-core') . '</button></form>';
        echo '<p class="tmc-field__desc">'
            . esc_html__('این دکمه رویدادهای ثبت‌شده را از صف می‌گذراند. در این نسخه نتیجهٔ هر کدام «ارسال بسته است» خواهد بود — همان چیزی که باید باشد — و دیگر دوباره تلاش نمی‌شود.', 'tecteb-marketplace-core')
            . '</p></section>';
    }

    /** @param callable(string|int):string $fa */
    private function renderList(Request $request, callable $fa): void
    {
        $page = max(1, $request->queryInt('paged'));
        $rows = $this->outbox()->recent([], self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('رویدادهای اخیر', 'tecteb-marketplace-core') . '</h2>';
        if ($rows === []) {
            echo '<p class="tmc-field__desc">'
                . esc_html__('هنوز رویدادی ثبت نشده است.', 'tecteb-marketplace-core') . '</p></section>';
            return;
        }
        echo '<div class="tmc-scroll" tabindex="0" role="region" aria-label="'
            . esc_attr__('رویدادهای اخیر', 'tecteb-marketplace-core') . '">'
            . '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('زمان', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('رویداد', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('موضوع', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('ارسال', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('امضا', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $state = (string) $row['delivery_state'];
            $reason = (string) $row['delivery_reason'];
            echo '<tr><td data-label="' . esc_attr__('زمان', 'tecteb-marketplace-core') . '">'
                . esc_html($fa((string) $row['created_at'])) . '</td>'
                . '<td data-label="' . esc_attr__('رویداد', 'tecteb-marketplace-core') . '">'
                . Components::code((string) $row['event_type']) . '</td>'
                . '<td data-label="' . esc_attr__('موضوع', 'tecteb-marketplace-core') . '">'
                . Components::code((string) $row['object_type'] . ':' . (string) $row['object_id']) . '</td>'
                . '<td data-label="' . esc_attr__('ارسال', 'tecteb-marketplace-core') . '">'
                . esc_html(OperationsMessages::deliveryState($state)) . '</td>'
                // The signature is shown truncated: enough to compare against a
                // receiver's own computation, short enough not to wrap the row.
                . '<td data-label="' . esc_attr__('امضا', 'tecteb-marketplace-core') . '">'
                . Components::code(mb_substr((string) $row['signature'], 0, 20) . '…') . '</td></tr>';
            if ($reason !== '') {
                echo '<tr><td colspan="5" data-label="' . esc_attr__('دلیل', 'tecteb-marketplace-core') . '">'
                    . '<span class="tmc-field__desc">'
                    . esc_html(OperationsMessages::deliveryReason($reason)) . '</span></td></tr>';
            }
        }
        echo '</tbody></table></div></section>';
    }

    private function handleAction(Request $request): string
    {
        if (!$request->isPost() || !$request->hasPost('events_action')) {
            return '';
        }
        if (!$request->nonceOk('tmc_events_nonce', self::NONCE)) {
            return 'err:' . __('درخواست معتبر نبود. صفحه را تازه کنید و دوباره تلاش کنید.', 'tecteb-marketplace-core');
        }
        if ($request->postKey('events_action') !== 'queue_delivery') {
            return 'err:' . __('این اقدام از این صفحه انجام نمی‌شود.', 'tecteb-marketplace-core');
        }
        /** @var JobRepositoryInterface $jobs */
        $jobs = $this->container->get(JobRepositoryInterface::class);
        $queued = $jobs->enqueue(DeliverEventsJob::TYPE, [], DeliverEventsJob::TYPE, get_current_user_id());
        if ($queued['id'] === 0) {
            return 'err:' . __('کار به صف اضافه نشد. صفحهٔ صف و سلامت اجرا را ببینید.', 'tecteb-marketplace-core');
        }
        WpJobScheduler::nudge();
        return 'ok:' . sprintf(
            /* translators: %s: job id */
            __('کار شمارهٔ %s در صف است. نتیجهٔ هر رویداد در همین فهرست نوشته می‌شود.', 'tecteb-marketplace-core'),
            PersianDigits::toPersian((string) $queued['id'])
        );
    }

    private function outbox(): OutboxRepositoryInterface
    {
        return $this->container->get(OutboxRepositoryInterface::class);
    }
}
