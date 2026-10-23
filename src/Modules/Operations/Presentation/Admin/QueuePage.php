<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Operations\Presentation\Admin;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Contracts\JobRepositoryInterface;
use Tecteb\Marketplace\Core\Jobs\Job;
use Tecteb\Marketplace\Core\Jobs\JobRunner;
use Tecteb\Marketplace\Core\Jobs\JobStatus;
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
            // Two separate facts, because they answer two separate questions
            // and `alpha.71` printed only the first: «آخرین تیکِ زمان‌بند» is
            // about the schedule, «آخرین اجرای یک دسته» is about work. A
            // manual run moves the second and not the first.
            ['label' => __('آخرین تیکِ زمان‌بند', 'tecteb-marketplace-core'), 'value' => $status['last_tick'] > 0
                ? $fa(wp_date('Y/m/d H:i', $status['last_tick']))
                : __('هنوز اجرا نشده', 'tecteb-marketplace-core')],
            ['label' => __('آخرین اجرای یک دسته', 'tecteb-marketplace-core'), 'value' => $status['last_run'] > 0
                ? sprintf(
                    /* translators: 1: date and time, 2: how it was started */
                    __('%1$s (%2$s)', 'tecteb-marketplace-core'),
                    $fa(wp_date('Y/m/d H:i', $status['last_run'])),
                    OperationsMessages::runStarter((string) $status['last_run_how'])
                )
                : __('هنوز اجرا نشده', 'tecteb-marketplace-core')],
        ]);
        if (($status['overdue'] ?? 0) > 0) {
            echo Components::notice('warning', sprintf(
                /* translators: %s: how long ago the next run was due */
                __('زمان «اجرای بعدی» گذشته است (%s پیش) و هنوز اجرا نشده: زمان‌بندِ این سایت کار نمی‌کند. تا وقتی این‌طور است، صف با دکمهٔ «اجرای دستی یک دسته» پیش می‌رود و هر بار یک دسته.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian(human_time_diff(time() - (int) $status['overdue']))
            ));
        }
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
        // «گیر کرده» is counted from the live jobs' own cursors and not with a
        // COUNT over a JSON column: a handler writes `tries` there, and that
        // number — «این کار سه بار گرفته شده و از جایش تکان نخورده» — is the
        // one thing the page owed the owner on `alpha.71` and did not say.
        $stuck = [];
        foreach ($this->jobs()->live(50) as $job) {
            if ((int) ($job->cursor['tries'] ?? 0) > 0) {
                $stuck[] = $job;
            }
        }
        if ($stuck !== []) {
            echo Components::notice('warning', sprintf(
                /* translators: %s: job ids */
                __('این کارها گرفته شده‌اند و از همان نقطه تکان نخورده‌اند: %s. یعنی هر بار که اجرا شده‌اند، همان جا متوقف شده‌اند — معمولاً چون همان یک قلمِ کار از مهلت یک درخواست این میزبان بیشتر طول می‌کشد. چند بار دیگر که اجرا شوند، همان قلم با شماره‌اش نام برده و رد می‌شود و بقیه ادامه می‌یابند.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian(implode('، ', array_map(static fn (Job $j): string => (string) $j->id, $stuck)))
            ));
        }
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
            // The recovery path for a worker that no longer exists. Only
            // offered while the lease still stands, because that is the only
            // state in which nothing else can move the job.
            if ($job->status === JobStatus::Running && $job->lockedUntil !== '' && strtotime($job->lockedUntil) > time()) {
                $out .= '<form method="post" class="tmc-inline-form">'
                    . wp_nonce_field(self::NONCE, 'tmc_jobs_nonce', true, false)
                    . '<input type="hidden" name="job_id" value="' . esc_attr((string) $job->id) . '">'
                    . '<button type="submit" class="tmc-button" name="jobs_action" value="hand_over">'
                    . esc_html__('ادامه از همین نقطه', 'tecteb-marketplace-core') . '</button></form>';
            }
        } else {
            $out .= '<span class="tmc-field__desc">' . esc_html__('—', 'tecteb-marketplace-core') . '</span>';
        }
        $out .= '</td></tr>';

        // WHERE it stopped, for a job that is still live. «۲ از ۱۸۶» says how
        // much; it does not say that three attempts all stopped at the same
        // place, which is the thing somebody can act on.
        if ($job->status->isLive() && $job->cursor !== []) {
            $tries = (int) ($job->cursor['tries'] ?? 0);
            $where = sprintf(
                /* translators: 1: phase, 2: position */
                __('مرحلهٔ %1$s، پس از شمارهٔ %2$s', 'tecteb-marketplace-core'),
                OperationsMessages::importPhase((string) ($job->cursor['phase'] ?? '')),
                $fa((string) (int) ($job->cursor['after'] ?? 0))
            );
            if ($tries > 0) {
                $where .= ' — ' . sprintf(
                    /* translators: %s: how many attempts stopped here */
                    __('%s تلاش روی همین نقطه، بدون پیشرفت', 'tecteb-marketplace-core'),
                    $fa((string) $tries)
                );
            }
            if ($job->lockedUntil !== '' && strtotime($job->lockedUntil) > time()) {
                $where .= ' — ' . sprintf(
                    /* translators: %s: time the lease runs out */
                    __('قفلش تا %s در دست یک کارگر است', 'tecteb-marketplace-core'),
                    $fa(wp_date('H:i', strtotime($job->lockedUntil)))
                );
            }
            $refused = array_values(array_filter((array) ($job->cursor['refused'] ?? []), 'is_string'));
            if ($refused !== []) {
                $where .= ' — ' . sprintf(
                    /* translators: %s: the refused rows, as id:reason */
                    __('قلم‌های ردشده: %s', 'tecteb-marketplace-core'),
                    $fa(implode('، ', $refused))
                );
            }
            $out .= '<tr><td colspan="7" data-label="' . esc_attr__('جای توقف', 'tecteb-marketplace-core') . '">'
                . '<span class="tmc-field__desc">' . esc_html($where) . '</span></td></tr>';
        }

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
        if ($action === 'run_now' || $action === 'hand_over') {
            if ($action === 'hand_over') {
                $id = $request->postInt('job_id');
                if (!$this->jobs()->handOver($id)) {
                    return 'err:' . __('مهلت قفل این کار پایان نیافت؛ شاید همین حالا تمام شده باشد. صفحه را تازه کنید.', 'tecteb-marketplace-core');
                }
            }
            // Both ways of running a batch write down that one ran — the
            // report knew about the cron tick only, and said «هنوز اجرا نشده»
            // after every manual run.
            WpJobScheduler::recordRun('manual');
            $results = $this->container->get(JobRunner::class)->run(1);
            if ($results === []) {
                return 'ok:' . $this->whyNothingRan();
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

    /**
     * Why a run found nothing, when there is plainly something there.
     *
     * ⛔ `alpha.71` said «چیزی در صف نبود» whatever the reason, and on staging
     * it said it while the very same page reported «در حال اجرا ۱». Both
     * sentences were true: `claim()` skips a running job whose lease has not
     * run out, which is right when a worker is alive and useless when the
     * process that held it is gone. The manager was told there was no work and
     * given nothing to press.
     */
    private function whyNothingRan(): string
    {
        $held = [];
        foreach ($this->jobs()->live(50) as $job) {
            if ($job->status === JobStatus::Running && $job->lockedUntil !== '' && strtotime($job->lockedUntil) > time()) {
                $held[] = sprintf(
                    /* translators: 1: job id, 2: time the lease runs out */
                    __('شمارهٔ %1$s تا %2$s', 'tecteb-marketplace-core'),
                    PersianDigits::toPersian((string) $job->id),
                    PersianDigits::toPersian(wp_date('H:i', strtotime($job->lockedUntil)))
                );
            }
        }
        if ($held === []) {
            return __('چیزی در صف نبود.', 'tecteb-marketplace-core');
        }
        return sprintf(
            /* translators: %s: the jobs and the times their leases run out */
            __('چیزی اجرا نشد، ولی صف خالی نیست: قفل این کارها در دست یک کارگر است و تا پایان مهلتش کسی سراغشان نمی‌رود (%s). اگر آن کارگر دیگر وجود ندارد — مثلاً درخواستش نیمه‌کاره قطع شده — دکمهٔ «ادامه از همین نقطه» در همان ردیف مهلت را همین حالا تمام می‌کند و یک دسته را از همان جای ثبت‌شده اجرا می‌کند.', 'tecteb-marketplace-core'),
            implode('، ', $held)
        );
    }

    private function jobs(): JobRepositoryInterface
    {
        return $this->container->get(JobRepositoryInterface::class);
    }
}
