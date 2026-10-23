<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Infrastructure\WordPress;

use Tecteb\Marketplace\Core\Jobs\JobRunner;

/**
 * The only part of the queue that knows what a cron is.
 *
 * **Action Scheduler when WooCommerce provides it, WP-Cron otherwise, and the
 * report says which.** WooCommerce ships Action Scheduler, so on the sites this
 * plugin targets it is nearly always there — but «nearly always» is not a
 * dependency we are allowed to declare, because the plugin must also load with
 * WooCommerce missing. So the binding is resolved at run time, and the queue
 * page prints the name of the mechanism actually in use rather than the one we
 * hoped for. A report that says «Action Scheduler» on a site running WP-Cron is
 * worse than no report.
 *
 * **WP-Cron is not a clock.** It fires on a visitor request, so a shop with no
 * visitors runs nothing, and `DISABLE_WP_CRON` turns it off entirely. That is
 * not a bug to work around here; it is a fact the queue page has to state,
 * next to the «اجرای دستی» button that exists precisely because of it. Action
 * Scheduler has the same visitor dependency but its own retry and its own
 * record, which is why it is preferred when present.
 *
 * Nothing here runs work. It asks for the runner to be called later; the runner
 * does one batch and returns, and what happens if this request dies in between
 * is already answered by the lease on the row.
 */
final class WpJobScheduler
{
    public const HOOK = 'tmc_run_jobs';

    public const RECURRENCE = 'tmc_five_minutes';

    public const OPTION_LAST_TICK = 'tmc_jobs_last_tick';

    /**
     * When a batch last RAN, and what started it.
     *
     * ⛔ Separate from `OPTION_LAST_TICK`, which is when the cron HOOK last
     * fired, and the difference is not pedantry: «اجرای دستی یک دسته» calls
     * the runner straight from the queue page and never goes through the hook,
     * so on `alpha.71` the page kept saying «آخرین اجرا: هنوز اجرا نشده» after
     * any number of manual runs. The owner read that as a dead cron. It was a
     * report that only knew about one of the two ways a batch can run.
     */
    public const OPTION_LAST_RUN = 'tmc_jobs_last_run';

    public static function register(\Closure $runnerFactory): void
    {
        add_filter('cron_schedules', [self::class, 'addSchedule']);
        add_action(self::HOOK, static function () use ($runnerFactory): void {
            self::tick($runnerFactory);
        });

        // The SCHEDULING waits for `init`, and that is not tidiness.
        //
        // This runs on `plugins_loaded`, and Action Scheduler builds its data
        // store on `init`. Asking it «is this already scheduled?» before then
        // produced a real `_doing_it_wrong` notice on the disposable site —
        // «as_has_scheduled_action() was called before the Action Scheduler
        // data store was initialized» — which means the answer came from a
        // store that did not exist yet, so the recurring action would have been
        // scheduled again on every single request.
        //
        // Priority 20 leaves room for Action Scheduler's own `init` work, which
        // runs at 1.
        add_action('init', [self::class, 'ensureScheduled'], 20);
    }

    /**
     * Make sure the tick is on the calendar. Idempotent: both backends are
     * asked the same question first, so running this on every request costs one
     * lookup and schedules nothing twice.
     */
    public static function ensureScheduled(): void
    {
        if (self::usingActionScheduler()) {
            if (!as_has_scheduled_action(self::HOOK)) {
                as_schedule_recurring_action(time() + 60, 300, self::HOOK, [], 'tecteb-marketplace-core');
            }
            return;
        }
        if (wp_next_scheduled(self::HOOK) === false) {
            wp_schedule_event(time() + 60, self::RECURRENCE, self::HOOK);
        }
    }

    public static function unregister(): void
    {
        if (self::usingActionScheduler() && function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::HOOK);
        }
        $timestamp = wp_next_scheduled(self::HOOK);
        if ($timestamp !== false) {
            wp_unschedule_event($timestamp, self::HOOK);
        }
    }

    /**
     * Ask for one batch as soon as possible, without waiting for the tick.
     *
     * Used when a manager queues work by hand: they have just pressed a button
     * and should not be told to come back in five minutes. It is still a
     * request for later, not a run — the POST that queued the job returns
     * immediately either way.
     */
    public static function nudge(): void
    {
        if (self::usingActionScheduler() && function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::HOOK, [], 'tecteb-marketplace-core');
            return;
        }
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + 5, self::HOOK);
        }
    }

    /** @param array<string,mixed> $schedules @return array<string,mixed> */
    public static function addSchedule(array $schedules): array
    {
        $schedules[self::RECURRENCE] = [
            'interval' => 300,
            'display' => __('هر پنج دقیقه (بازارگاه)', 'tecteb-marketplace-core'),
        ];
        return $schedules;
    }

    /**
     * Action Scheduler is only usable once its data store exists.
     *
     * `function_exists()` alone is not enough and finding that out cost a real
     * notice: the functions are declared as soon as WooCommerce loads, but they
     * answer from a store that is not built until `init`. So the readiness test
     * is the store, not the symbol.
     */
    public static function usingActionScheduler(): bool
    {
        if (!function_exists('as_schedule_recurring_action') || !function_exists('as_has_scheduled_action')) {
            return false;
        }
        return class_exists('ActionScheduler_Store', false)
            && did_action('action_scheduler_init') > 0;
    }

    /**
     * Write down that a batch ran, and how it was started.
     *
     * Called by the tick AND by the manual button, because those are the two
     * things that run work and a report that knows about one of them is wrong
     * about the other.
     */
    public static function recordRun(string $how): void
    {
        update_option(self::OPTION_LAST_RUN, ['at' => time(), 'how' => $how === 'cron' ? 'cron' : 'manual'], false);
    }

    /** Machine key for the report; the Persian label lives in Presentation. */
    public static function binding(): string
    {
        if (self::usingActionScheduler()) {
            return 'action_scheduler';
        }
        if (defined('DISABLE_WP_CRON') && constant('DISABLE_WP_CRON')) {
            return 'wp_cron_disabled';
        }
        return 'wp_cron';
    }

    /** @return array{binding:string, next:int, last_tick:int, last_run:int, last_run_how:string, overdue:int} */
    public static function status(): array
    {
        $next = 0;
        if (self::usingActionScheduler() && function_exists('as_next_scheduled_action')) {
            $scheduled = as_next_scheduled_action(self::HOOK);
            $next = is_int($scheduled) ? $scheduled : 0;
        } else {
            $scheduled = wp_next_scheduled(self::HOOK);
            $next = $scheduled === false ? 0 : (int) $scheduled;
        }
        $run = get_option(self::OPTION_LAST_RUN, []);
        return [
            'binding' => self::binding(),
            'next' => $next,
            'last_tick' => (int) get_option(self::OPTION_LAST_TICK, 0),
            'last_run' => is_array($run) ? (int) ($run['at'] ?? 0) : 0,
            'last_run_how' => is_array($run) ? (string) ($run['how'] ?? '') : '',
            // How far the next run is in the PAST. Zero means it is not.
            //
            // `ensureScheduled()` asks «is this already scheduled?», and both
            // backends answer yes for an action whose time passed weeks ago —
            // so an overdue action is never re-scheduled and nothing anywhere
            // says so. On staging it read ۱۴۰۵/۰۷/۰۴، four weeks back, next to
            // a reassuring «زمان‌بندی شده».
            'overdue' => $next > 0 && $next < time() - 300 ? time() - $next : 0,
        ];
    }

    private static function tick(\Closure $runnerFactory): void
    {
        update_option(self::OPTION_LAST_TICK, time(), false);
        self::recordRun('cron');
        try {
            $runner = $runnerFactory();
            if ($runner instanceof JobRunner) {
                // A handful of batches per tick, not the whole queue: this runs
                // inside somebody's page load and their page is not ours to
                // spend.
                $runner->run(5);
            }
        } catch (\Throwable) {
            // A queue that throws must not take the visitor's request with it.
            // The failure is already on the job row; the health page reads it.
        }
    }
}
