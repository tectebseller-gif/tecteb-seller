<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Operations\Presentation\Admin;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Contracts\JobRepositoryInterface;
use Tecteb\Marketplace\Core\Jobs\Job;
use Tecteb\Marketplace\Core\Jobs\JobRunner;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Infrastructure\WordPress\WpJobScheduler;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Operations\Presentation\OperationsMessages;

/**
 * «صف و سلامت اجرا» — what is queued, what it is doing, and what is actually
 * going to wake it up.
 *
 * The last part is why this page exists rather than a progress bar on the
 * import screen. A queue is only as good as the thing that ticks it, and on
 * WordPress that thing is a visitor: WP-Cron fires on a request, so a shop with
 * no traffic runs nothing, and `DISABLE_WP_CRON` turns it off entirely with no
 * warning anywhere. This page names the mechanism in use, says what it costs,
 * and puts the manual «اجرا» next to it — so a stuck import is a thing somebody
 * can see and fix, instead of a job that silently never ran.
 *
 * **«در حال اجرا» and «بی‌صاحب» are counted separately**, and the difference is
 * the lease. A row still says `running` after the process that claimed it was
 * killed; only the expired `locked_until` distinguishes that from a worker
 * genuinely at work. Reporting them as one number would have made every
 * crashed job look busy for ever.
 */
final class QueuePage
{
    public const SLUG = 'tmc-jobs';
    public const CAPABILITY = Capabilities::MANAGE_JOBS;
    private const NONCE = 'tmc_jobs_action';
    private const PER_PAGE = 25;

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public static function menuLabel(): string
    {
        return __('صف و سلامت اجرا', 'tecteb-marketplace-core');
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

        $this->renderBinding($fa);
        $this->renderCensus($fa);
        $this->renderList($request, $fa);
        echo Components::shellClose();
    }

    /** @param callable(string|int):string $fa */
    private function renderBinding(callable $fa): void
    {
        $status = WpJobScheduler::status();
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('چه چیزی صف را پیش می‌برد', 'tecteb-marketplace-core') . '</h2>';
        echo Components::notice(
            $status['binding'] === 'wp_cron_disabled' ? 'warning' : 'info',
            OperationsMessages::binding($status['binding'])
        );
        echo Components::dataList([
            ['label' => __('سازوکار', 'tecteb-marketplace-core'), 'value' => $status['binding']],
            ['label' => __('اجرای بعدی', 'tecteb-marketplace-core'), 'value' => $status['next'] > 0
                ? $fa(wp_date('Y/m/d H:i', $status['next']))
                : __('زمان‌بندی نشده', 'tecteb-marketplace-core')],
            ['label' => __('آخرین اجرا', 'tecteb-marketplace-core'), 'value' => $status['last_tick'] > 0
                ? $fa(wp_date('Y/m/d H:i', $status['last_tick']))
                : __('هنوز اجرا نشده', 'tecteb-marketplace-core')],
        ]);
        echo '<form method="post" class="tmc-inline-form">'
            . wp_nonce_field(self::NONCE, 'tmc_jobs_nonce', true, false)
            . '<button type="submit" class="tmc-button" name="jobs_action" value="run_now">'
            . esc_html__('اجرای دستی یک دسته', 'tecteb-marketplace-core') . '</button></form>';
        echo '<p class="tmc-field__desc">'
            . esc_html__('«اجرای دستی» یک دسته از کارِ در صف را همین حالا انجام می‌دهد و برمی‌گردد؛ کل صف را یکجا اجرا نمی‌کند، چون همین درخواست هم مهلت اجرای خودش را دارد.', 'tecteb-marketplace-core')
            . '</p></section>';
    }

    /** @param callable(string|int):string $fa */
    private function renderCensus(callable $fa): void
    {
        $census = $this->jobs()->census();
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('وضعیت کلی', 'tecteb-marketplace-core') . '</h2>';
        $rows = [];
        foreach (['pending', 'running', 'stalled', 'done', 'failed', 'cancelled'] as $key) {
            $rows[] = [
                'label' => OperationsMessages::jobStatus($key),
                'value' => $fa((string) ($census[$key] ?? 0)),
            ];
        }
        echo Components::dataList($rows);
        if ((int) ($census['stalled'] ?? 0) > 0) {
            echo Components::notice('warning', __('چند کار «بی‌صاحب» مانده‌اند: مهلت قفلشان تمام شده و هیچ کارگری نگهشان نداشته — معمولاً یعنی اجرا وسط کار قطع شده. با اجرای بعدی خودشان از همان نقطهٔ ثبت‌شده ادامه می‌یابند.', 'tecteb-marketplace-core'));
        }
        if ((int) ($census['failed'] ?? 0) > 0) {
            echo Components::notice('warning', __('کار شکست‌خورده دوباره تلاش نمی‌شود: دستوری که غلط بوده با تکرار درست نمی‌شود. دلیلش را در فهرست زیر ببینید و اگر رفع شد، کار تازه‌ای در صف بگذارید.', 'tecteb-marketplace-core'));
        }
        echo '</section>';
    }

    /** @param callable(string|int):string $fa */
    private function renderList(Request $request, callable $fa): void
    {
        $page = max(1, $request->queryInt('paged'));
        $jobs = $this->jobs()->recent([], self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('کارهای اخیر', 'tecteb-marketplace-core') . '</h2>';
        if ($jobs === []) {
            echo '<p class="tmc-field__desc">'
                . esc_html__('هیچ کاری در صف نبوده است.', 'tecteb-marketplace-core') . '</p></section>';
            return;
        }
        echo '<div class="tmc-scroll" tabindex="0" role="region" aria-label="'
            . esc_attr__('کارهای اخیر', 'tecteb-marketplace-core') . '">'
            . '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('شماره', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('کار', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('وضعیت', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('پیشرفت', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('تلاش', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('آخرین تغییر', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('اقدام', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($jobs as $job) {
            echo $this->row($job, $fa);          // phpcs:ignore WordPress.Security.EscapeOutput
        }
        echo '</tbody></table></div>';
        echo $this->pager($page, count($jobs), $fa);   // phpcs:ignore WordPress.Security.EscapeOutput
        echo '</section>';
    }

    /** @param callable(string|int):string $fa */
    private function row(Job $job, callable $fa): string
    {
        $progress = $job->progress();
        $done = $fa((string) $job->done);
        $shown = $progress === null
            ? sprintf(
                /* translators: 1: done, 2: failed */
                __('%1$s انجام، %2$s رد', 'tecteb-marketplace-core'),
                $done,
                $fa((string) $job->failed)
            )
            : sprintf(
                /* translators: 1: percent, 2: done, 3: total */
                __('%1$s٪ (%2$s از %3$s)', 'tecteb-marketplace-core'),
                $fa((string) $progress),
                $done,
                $fa((string) $job->total)
            );

        $out = '<tr><td data-label="' . esc_attr__('شماره', 'tecteb-marketplace-core') . '">'
            . Components::code((string) $job->id) . '</td>'
            . '<td data-label="' . esc_attr__('کار', 'tecteb-marketplace-core') . '">'
            . esc_html(OperationsMessages::jobType($job->type)) . '</td>'
            . '<td data-label="' . esc_attr__('وضعیت', 'tecteb-marketplace-core') . '">'
            . esc_html(OperationsMessages::jobStatus($job->status->value)) . '</td>'
            . '<td data-label="' . esc_attr__('پیشرفت', 'tecteb-marketplace-core') . '">'
            . esc_html($shown) . '</td>'
            . '<td data-label="' . esc_attr__('تلاش', 'tecteb-marketplace-core') . '">'
            . esc_html($fa((string) $job->attempts)) . '</td>'
            . '<td data-label="' . esc_attr__('آخرین تغییر', 'tecteb-marketplace-core') . '">'
            . esc_html($fa($job->updatedAt)) . '</td>'
            . '<td data-label="' . esc_attr__('اقدام', 'tecteb-marketplace-core') . '">';
        if ($job->status->isLive()) {
            $out .= '<form method="post" class="tmc-inline-form">'
                . wp_nonce_field(self::NONCE, 'tmc_jobs_nonce', true, false)
                . '<input type="hidden" name="job_id" value="' . esc_attr((string) $job->id) . '">'
                . '<button type="submit" class="tmc-button tmc-button--quiet" name="jobs_action" value="cancel">'
                . esc_html__('لغو', 'tecteb-marketplace-core') . '</button></form>';
        } else {
            $out .= '<span class="tmc-field__desc">' . esc_html__('—', 'tecteb-marketplace-core') . '</span>';
        }
        $out .= '</td></tr>';

        if ($job->lastError !== '') {
            // The reason goes on its own full-width row rather than in the
            // action cell: a failure message is a sentence, and a sentence in a
            // narrow column is the starved text box the layout rules forbid.
            $out .= '<tr><td colspan="7" data-label="' . esc_attr__('دلیل', 'tecteb-marketplace-core') . '">'
                . '<span class="tmc-field__desc">' . esc_html($job->lastError) . '</span></td></tr>';
        }
        return $out;
    }

    /** @param callable(string|int):string $fa */
    private function pager(int $page, int $shown, callable $fa): string
    {
        $base = admin_url('admin.php?page=' . self::SLUG);
        $out = '<nav class="tmc-pager" aria-label="' . esc_attr__('صفحه‌بندی کارها', 'tecteb-marketplace-core') . '">';
        if ($page > 1) {
            $out .= '<a class="tmc-button tmc-button--quiet" href="' . esc_url($base . '&paged=' . ($page - 1)) . '">'
                . esc_html__('صفحهٔ قبل', 'tecteb-marketplace-core') . '</a>';
        }
        $out .= '<span class="tmc-field__desc">' . esc_html(sprintf(
            /* translators: %s: page number */
            __('صفحهٔ %s', 'tecteb-marketplace-core'),
            $fa((string) $page)
        )) . '</span>';
        if ($shown >= self::PER_PAGE) {
            $out .= '<a class="tmc-button tmc-button--quiet" href="' . esc_url($base . '&paged=' . ($page + 1)) . '">'
                . esc_html__('صفحهٔ بعد', 'tecteb-marketplace-core') . '</a>';
        }
        return $out . '</nav>';
    }

    private function handleAction(Request $request): string
    {
        if (!$request->isPost() || !$request->hasPost('jobs_action')) {
            return '';
        }
        if (!$request->nonceOk('tmc_jobs_nonce', self::NONCE)) {
            return 'err:' . __('درخواست معتبر نبود. صفحه را تازه کنید و دوباره تلاش کنید.', 'tecteb-marketplace-core');
        }
        $action = $request->postKey('jobs_action');
        if ($action === 'run_now') {
            $results = $this->container->get(JobRunner::class)->run(1);
            if ($results === []) {
                return 'ok:' . __('چیزی در صف نبود.', 'tecteb-marketplace-core');
            }
            $first = $results[0];
            return 'ok:' . sprintf(
                /* translators: 1: job id, 2: outcome */
                __('کار شمارهٔ %1$s اجرا شد: %2$s', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) $first['job']),
                OperationsMessages::jobOutcome((string) $first['outcome'])
            );
        }
        if ($action === 'cancel') {
            $id = $request->postInt('job_id');
            if (!$this->jobs()->cancel($id)) {
                return 'err:' . __('این کار لغو نشد؛ شاید همین حالا تمام شده باشد.', 'tecteb-marketplace-core');
            }
            return 'ok:' . sprintf(
                /* translators: %s: job id */
                __('کار شمارهٔ %s لغو شد. اگر وسط یک دسته بود، همان دسته تمام می‌شود و بعد متوقف می‌ماند.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) $id)
            );
        }
        return 'err:' . __('این اقدام از این صفحه انجام نمی‌شود.', 'tecteb-marketplace-core');
    }

    private function jobs(): JobRepositoryInterface
    {
        return $this->container->get(JobRepositoryInterface::class);
    }
}
