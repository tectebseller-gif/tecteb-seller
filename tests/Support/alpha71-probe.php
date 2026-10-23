<?php
/**
 * The Dokan import that stopped at «۲ از ۱۸۶», measured.
 *
 * On staging, `alpha.71` queued the import, finished the vendors phase, and
 * then never moved again: three attempts, the same `2/186`, status `running`,
 * «بی‌صاحب مانده ۰», and «اجرای دستی یک دسته» answering «چیزی در صف نبود». No
 * fatal, no database error and no memory message in `debug.log` — so the cause
 * is not something the log would have named, and this probe exists to say what
 * it is instead of guessing at cron or the host.
 *
 * It measures four things on whichever tree it is loaded against:
 *
 *  - `state` — what the queue page would print, and what a manual run would
 *    say, for a job in exactly the row state staging reports. Both at once:
 *    one job «در حال اجرا» and nothing claimable.
 *  - `kill` — one batch in a SEPARATE process, SIGKILLed part way through. The
 *    only honest model of a request the host gives up on: an exception is
 *    caught and recorded by `JobRunner`, and an `exit` still runs shutdown
 *    functions, so neither of them is the thing that happened.
 *  - `complete` — batches until the import is done, so «it resumes» is a
 *    number and not a promise.
 *  - `cost` — how long one page of products actually takes, because a batch
 *    that cannot fit in a request is the whole defect.
 *
 * It loads on BOTH trees (`alpha.32`): everything this round adds is asked for
 * with `method_exists`/`property_exists`/`array_key_exists` and reported
 * `__absent__` on the base. A probe that fatals on the bytes it was written to
 * measure measures nothing.
 *
 * And it gives back everything it spends (`alpha.45`). The fixture is
 * Dokan-SHAPED data, not Dokan: two sellers carrying `dokan_enable_selling`,
 * their products, their images, and an empty `wp_dokan_orders` so the real
 * `WpDokanReader` reports itself available. Dokan Lite cannot run in this
 * environment at all — `abzarwp/` is SourceGuardian-encrypted (`alpha.53`) —
 * so the reader is the real one and only its DATA is ours. `reset` undoes the
 * marketplace rows through the documented `rollback()`, then deletes exactly
 * the users, posts, attachments and table it created, and nothing else.
 *
 * Prints ONE key per line (the `alpha.41` rule).
 *
 *   wp eval-file tests/Support/alpha71-probe.php <verb> [args]
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\JobRepositoryInterface;
use Tecteb\Marketplace\Core\Jobs\JobRunner;
use Tecteb\Marketplace\Core\Jobs\JobStatus;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Migration\Application\DokanImportJob;
use Tecteb\Marketplace\Modules\Migration\Application\DokanReaderInterface;
use Tecteb\Marketplace\Modules\Migration\Application\ImportFromDokan;

if (!defined('DB_NAME') || (DB_NAME !== 'tmc_wp_test' && DB_NAME !== 'tmc_wp_demo')) {
    fwrite(STDERR, "refused: DB_NAME is not one of the disposable databases.\n");
    echo "refused=1 reason=database_is_not_the_disposable_one\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local.\n");
    echo "refused=1 reason=home_url_is_not_local\n";
    return;
}

$FIXTURE = 'tmc_p71_fixture';
$say = static function (string $key, mixed $value): void {
    if (is_bool($value)) {
        $value = $value ? 'yes' : 'no';
    }
    if (is_array($value)) {
        $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $value = (string) $value;
    echo $key . '=' . ($value === '' ? '__empty__' : $value) . "\n";
};

$command = (string) ($args[0] ?? 'report');
$c = Bootstrap::container();
$manager = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
wp_set_current_user($manager);
$c->bind(CapabilityCheckerInterface::class, static function () use ($manager) {
    return new class ($manager) implements CapabilityCheckerInterface {
        public function __construct(private $id)
        {
        }

        public function can(string $capability): bool
        {
            return in_array($capability, Capabilities::all(), true);
        }

        public function currentUserId(): ?int
        {
            return $this->id;
        }
    };
});

/** @var ImportFromDokan $service */
$service = $c->get(ImportFromDokan::class);
/** @var JobRepositoryInterface $jobs */
$jobs = $c->get(JobRepositoryInterface::class);
global $wpdb;

$fixture = static function () use ($FIXTURE): array {
    $raw = get_option($FIXTURE, []);
    return is_array($raw) ? $raw + ['vendors' => [], 'products' => [], 'images' => [], 'table' => false, 'run' => ''] : ['vendors' => [], 'products' => [], 'images' => [], 'table' => false, 'run' => ''];
};
$remember = static function (array $f) use ($FIXTURE): void {
    update_option($FIXTURE, $f, false);
};

/** The marketplace rows this import made, counted from the table itself. */
$observed = static function () use ($wpdb): int {
    return (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}tmc_products WHERE link_ownership = 'observed'"
    );
};
$profiles = static function () use ($wpdb): int {
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tmc_vendor_profiles");
};
/** The live import job, whatever state it is in. */
$liveJob = static function () use ($jobs) {
    foreach ($jobs->recent([], 50) as $job) {
        if ($job->type === DokanImportJob::TYPE) {
            return $job;
        }
    }
    return null;
};
$sayJob = static function ($job) use ($say): void {
    if ($job === null) {
        $say('job', '__none__');
        return;
    }
    $say('job_id', $job->id);
    $say('job_status', $job->status->value);
    $say('job_attempts', $job->attempts);
    $say('job_done', $job->done);
    $say('job_failed', $job->failed);
    $say('job_total', $job->total);
    $say('job_cursor', $job->cursor);
    $say('job_progress', $job->progress() === null ? '__none__' : $job->progress() . '%');
    $say('job_last_error', $job->lastError);
    $say('job_locked_until', $job->lockedUntil === '' ? '__none__' : $job->lockedUntil);
    // Added this round; `__absent__` on the base tree.
    foreach (['cursorAttempts', 'budget'] as $field) {
        $say('job_' . strtolower(preg_replace('~([a-z])([A-Z])~', '$1_$2', $field)), property_exists($job, $field) ? $job->{$field} : '__absent__');
    }
};

switch ($command) {
    // ----------------------------------------------------------- the fixture
    case 'arrange': {
        $wanted = max(1, (int) ($args[1] ?? 184));
        $px = max(16, (int) ($args[2] ?? 1400));
        $f = $fixture();

        // `WpDokanReader::isAvailable()` is satisfied by the table, so the real
        // reader can be measured without the plugin that normally creates it.
        $table = $wpdb->prefix . 'dokan_orders';
        if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            $wpdb->query("CREATE TABLE `{$table}` (
                `order_id` BIGINT UNSIGNED NOT NULL,
                `seller_id` BIGINT UNSIGNED NOT NULL,
                `order_total` DECIMAL(19,4) NOT NULL DEFAULT 0,
                `net_amount` DECIMAL(19,4) NOT NULL DEFAULT 0,
                `order_status` VARCHAR(30) NOT NULL DEFAULT '',
                PRIMARY KEY (`order_id`)
            ) DEFAULT CHARSET=utf8mb4");
            $f['table'] = true;
        }

        // One source image, 1400px by default, so `duplicateForVendor()` does
        // the work it does on the owner's site: a file copy and a full
        // `wp_generate_attachment_metadata()` over every registered size.
        $uploads = wp_upload_dir();
        $path = trailingslashit($uploads['path']) . 'tmc-p71-source.png';
        if (!file_exists($path)) {
            $im = imagecreatetruecolor($px, $px);
            imagefill($im, 0, 0, imagecolorallocate($im, 240, 244, 248));
            for ($i = 0; $i < 60; $i++) {
                imagefilledellipse(
                    $im,
                    (int) ($px * (0.1 + 0.8 * ($i % 10) / 10)),
                    (int) ($px * (0.1 + 0.8 * intdiv($i, 10) / 6)),
                    (int) ($px / 9),
                    (int) ($px / 9),
                    imagecolorallocate($im, 40 + $i * 3, 90, 160)
                );
            }
            imagepng($im, $path);
            imagedestroy($im);
        }
        $sourceAttachment = (int) ($f['source_attachment'] ?? 0);
        if ($sourceAttachment === 0 || get_post($sourceAttachment) === null) {
            $sourceAttachment = (int) wp_insert_attachment([
                'post_mime_type' => 'image/png',
                'post_title' => 'tmc-p71-source',
                'post_status' => 'inherit',
            ], $path);
            $f['source_attachment'] = $sourceAttachment;
            $f['images'][] = $sourceAttachment;
        }

        for ($v = 1; $v <= 2; $v++) {
            $login = 'p71seller' . $v;
            $user = get_user_by('login', $login);
            $id = $user === false ? 0 : (int) $user->ID;
            if ($id === 0) {
                $id = (int) wp_insert_user([
                    'user_login' => $login,
                    'user_pass' => wp_generate_password(20),
                    'user_email' => $login . '@example.invalid',
                    'display_name' => 'فروشگاه آزمایشی ' . $v,
                    'role' => 'customer',
                ]);
                $f['vendors'][] = $id;
            }
            // The real reader identifies a Dokan seller by the `seller` ROLE
            // inside the serialized capabilities meta — the same LIKE core
            // uses for a role filter — not by `dokan_enable_selling`. Dokan
            // registers that role on activation and cannot run here, so the
            // meta is written directly; nothing else about the user changes.
            update_user_meta($id, $wpdb->prefix . 'capabilities', ['customer' => true, 'seller' => true]);
            update_user_meta($id, 'dokan_enable_selling', 'yes');
            update_user_meta($id, 'dokan_profile_settings', ['store_name' => 'فروشگاه آزمایشی ' . $v, 'enable_selling' => 'yes']);
        }
        $sellers = array_values(array_map(
            static fn (string $l): int => (int) get_user_by('login', $l)->ID,
            ['p71seller1', 'p71seller2']
        ));

        $have = count($f['products']);
        for ($i = $have; $i < $wanted; $i++) {
            $author = $sellers[$i % count($sellers)];
            $post = (int) wp_insert_post([
                'post_type' => 'product',
                'post_status' => 'publish',
                'post_title' => 'کالای دکان ' . ($i + 1),
                'post_author' => $author,
            ], true);
            if ($post <= 0) {
                continue;
            }
            wp_set_object_terms($post, 'simple', 'product_type');
            update_post_meta($post, '_regular_price', (string) (100000 + $i * 1000));
            update_post_meta($post, '_price', (string) (100000 + $i * 1000));
            update_post_meta($post, '_sku', 'P71-' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT));
            update_post_meta($post, '_stock', (string) (3 + ($i % 7)));
            update_post_meta($post, '_manage_stock', 'yes');
            update_post_meta($post, '_thumbnail_id', (string) $sourceAttachment);
            $f['products'][] = $post;
        }
        $remember($f);

        $reader = $c->get(DokanReaderInterface::class);
        $say('reader_available', $reader->isAvailable());
        $say('reader_vendors', count($reader->vendors()));
        $say('reader_products', count($reader->products()));
        $say('source_image_px', $px);
        $say('source_attachment', $sourceAttachment);
        $say('observed_rows', $observed());
        break;
    }

    case 'plan': {
        $plan = $service->plan();
        $summary = $plan->summary();
        $say('plan_run_id', $plan->runId);
        $say('plan_vendors', (int) $summary['vendors']);
        $say('plan_products', (int) $summary['products']);
        $say('plan_conflicts', (int) ($summary['conflicts'] ?? 0));
        $say('plan_is_clean', $plan->isClean());
        $say('plan_total', (int) $summary['vendors'] + (int) $summary['products']);
        $f = $fixture();
        $f['run'] = $plan->runId;
        $f['total'] = (int) $summary['vendors'] + (int) $summary['products'];
        $remember($f);
        break;
    }

    // The same enqueue `MigrationPage` does, with the same live key.
    case 'queue': {
        $f = $fixture();
        if (($f['run'] ?? '') === '') {
            $say('queued', '__no_plan__');
            break;
        }
        $queued = $jobs->enqueue(
            DokanImportJob::TYPE,
            ['run_id' => $f['run'], 'total' => (int) ($f['total'] ?? 0)],
            DokanImportJob::TYPE,
            $manager
        );
        $say('queued_id', $queued['id']);
        $say('queued_created', $queued['created']);
        $sayJob($jobs->find((int) $queued['id']));
        break;
    }

    // What the page prints, and what «اجرای دستی یک دسته» answers — for
    // whatever state the job is in right now.
    case 'state': {
        $census = $jobs->census();
        foreach (['pending', 'running', 'stalled', 'done', 'failed', 'cancelled'] as $k) {
            $say('census_' . $k, (int) ($census[$k] ?? 0));
        }
        $say('census_stuck', array_key_exists('stuck', $census) ? (int) $census['stuck'] : '__absent__');
        $sayJob($liveJob());
        $say('observed_rows', $observed());
        $say('vendor_profiles', $profiles());
        // Could the manager start the import over? The live key says no.
        $again = $jobs->enqueue(DokanImportJob::TYPE, ['run_id' => 'probe-second', 'total' => 1], DokanImportJob::TYPE, $manager);
        $say('second_import_created', $again['created']);
        $say('second_import_points_at', $again['id']);
        break;
    }

    // Put the row into exactly the state staging reports, without running
    // anything: a dead worker's token and a lease that has not run out.
    case 'dead-worker': {
        $job = $liveJob();
        if ($job === null) {
            $say('dead_worker', '__no_job__');
            break;
        }
        $attempts = max(1, (int) ($args[1] ?? $job->attempts));
        $lease = (int) ($args[2] ?? 240);
        // How many of those attempts stopped at THIS position. Written here
        // rather than by running a batch, because a batch that runs resets it
        // — which is the whole point of the counter and cost one browser run
        // to find out.
        $tries = (int) ($args[3] ?? 0);
        $cursor = $job->cursor;
        if ($tries > 0) {
            $cursor['tries'] = $tries;
        }
        $now = current_time('mysql');
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}tmc_jobs
             SET status = 'running', attempts = %d, lock_token = 'tok-of-the-dead-worker',
                 locked_until = %s, updated_at = %s, `cursor` = %s
             WHERE id = %d",
            $attempts,
            gmdate('Y-m-d H:i:s', strtotime($now) + $lease),
            $now,
            (string) json_encode($cursor, JSON_UNESCAPED_UNICODE),
            $job->id
        ));
        $say('dead_worker_lease_seconds', $lease);
        $say('dead_worker_tries', $tries);
        $sayJob($jobs->find($job->id));
        break;
    }

    // Expire the lease without touching anything else — what five minutes of
    // wall clock does, measured in one step.
    case 'expire-lease': {
        $job = $liveJob();
        if ($job === null) {
            $say('expire_lease', '__no_job__');
            break;
        }
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}tmc_jobs SET locked_until = %s WHERE id = %d",
            gmdate('Y-m-d H:i:s', strtotime(current_time('mysql')) - 10),
            $job->id
        ));
        $say('lease_expired', true);
        $sayJob($jobs->find($job->id));
        break;
    }

    // One batch, in this process. What a cron tick or «اجرای دستی» does.
    case 'run': {
        $before = $liveJob();
        $beforeCursor = $before === null ? [] : $before->cursor;
        $t = microtime(true);
        $results = $c->get(JobRunner::class)->run(max(1, (int) ($args[1] ?? 1)));
        $say('run_elapsed_ms', round((microtime(true) - $t) * 1000, 2));
        $say('run_batches', count($results));
        $say('run_says', $results === [] ? 'nothing_was_in_the_queue' : $results[0]['outcome']);
        foreach ($results as $i => $r) {
            $say('run_outcome_' . $i, $r['outcome'] . ' done:' . $r['done'] . ' failed:' . $r['failed'] . ' error:' . ($r['error'] === '' ? '__none__' : $r['error']));
        }
        // How many times the handler recorded its position INSIDE the batch.
        // `__absent__` on the base tree, where a batch was the only unit of
        // record there was.
        $say('run_checkpoints', $results === [] ? '__none__' : ($results[0]['checkpoints'] ?? '__absent__'));
        $after = $liveJob();
        $say('cursor_moved', $after !== null && $after->cursor !== $beforeCursor);
        $sayJob($after);
        $say('observed_rows', $observed());
        break;
    }

    // One batch in another process, SIGKILLed after `seconds`.
    case 'kill': {
        $seconds = max(1, (int) ($args[1] ?? 4));
        $repo = dirname(__DIR__, 2);
        $wpPath = (string) (getenv('TMC_WP_PATH') ?: ABSPATH);
        $cli = (string) (getenv('TMC_WPCLI') ?: '/usr/local/bin/wp');
        $before = $liveJob();
        $say('before_cursor', $before === null ? '__none__' : $before->cursor);
        $say('before_done', $before === null ? '__none__' : $before->done);
        $say('before_attempts', $before === null ? '__none__' : $before->attempts);
        $beforeObserved = $observed();
        $say('before_observed', $beforeObserved);

        // `exec` so the shell REPLACES itself with php and the pid proc_open
        // hands back is the pid that does the work. Without it the kill lands
        // on `/bin/sh` and the worker carries on orphaned in the background —
        // measured: the row said «killed» while ten more products arrived
        // afterwards, so the probe was reporting a state that no longer
        // existed by the time it printed.
        $cmd = sprintf(
            'exec php %s --allow-root --path=%s eval-file %s 1 2>&1',
            escapeshellarg($cli),
            escapeshellarg($wpPath),
            escapeshellarg($repo . '/tests/Support/alpha71-worker.php')
        );
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) {
            $say('kill', '__cannot_start_worker__');
            break;
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $deadline = microtime(true) + $seconds;
        $out = '';
        $finished = false;
        while (microtime(true) < $deadline) {
            $out .= (string) stream_get_contents($pipes[1]);
            $status = proc_get_status($p);
            if (!$status['running']) {
                $finished = true;
                break;
            }
            usleep(50000);
        }
        if (!$finished) {
            // SIGKILL, so no shutdown function and no fatal in any log —
            // the thing the host does, not an approximation of it.
            $status = proc_get_status($p);
            if ($status['running'] && function_exists('posix_kill')) {
                @posix_kill((int) $status['pid'], 9);
            }
            proc_terminate($p, 9);
            // Wait for the process table to actually lose it, so nothing this
            // probe prints afterwards is about a worker still writing.
            for ($i = 0; $i < 100; $i++) {
                if (!proc_get_status($p)['running']) {
                    break;
                }
                usleep(20000);
            }
        }
        $out .= (string) stream_get_contents($pipes[1]);
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        proc_close($p);

        $say('worker_was_killed', !$finished);
        $say('worker_said', trim(preg_replace('~\s+~', ' ', $out)) === '' ? '__nothing__' : trim(preg_replace('~\s+~', ' ', $out)));
        $after = $liveJob();
        $say('after_cursor', $after === null ? '__none__' : $after->cursor);
        $say('after_done', $after === null ? '__none__' : $after->done);
        $say('after_attempts', $after === null ? '__none__' : $after->attempts);
        $say('after_status', $after === null ? '__none__' : $after->status->value);
        $say('after_observed', $observed());
        $say('cursor_moved', $after !== null && $before !== null && $after->cursor !== $before->cursor);
        // Work that reached the SITE and not the row: products created minus
        // units counted. Zero is the only honest answer for a queue that
        // reports progress, and on the base tree it is the size of the lie.
        if ($after !== null && $before !== null) {
            $createdHere = $observed() - $beforeObserved;
            $countedHere = ($after->done + $after->failed) - ($before->done + $before->failed);
            $say('created_this_batch', $createdHere);
            $say('counted_this_batch', $countedHere);
            $say('unrecorded_units', $createdHere - $countedHere);
        }
        break;
    }

    // Batches until the import is done, or `max` batches — so «it resumes» is
    // a number.
    case 'complete': {
        $max = max(1, (int) ($args[1] ?? 20));
        $runner = $c->get(JobRunner::class);
        $batches = 0;
        $outcomes = [];
        for ($i = 0; $i < $max; $i++) {
            $job = $liveJob();
            if ($job !== null && $job->status->isTerminal()) {
                break;
            }
            // A live lease is the one thing a fresh worker respects, and the
            // point here is to count batches rather than wait out the clock.
            if ($job !== null && $job->lockedUntil !== '' && strtotime($job->lockedUntil) > strtotime(current_time('mysql'))) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->prefix}tmc_jobs SET locked_until = %s WHERE id = %d",
                    gmdate('Y-m-d H:i:s', strtotime(current_time('mysql')) - 10),
                    $job->id
                ));
            }
            $results = $runner->run(1);
            if ($results === []) {
                break;
            }
            $batches++;
            $outcomes[] = $results[0]['outcome'];
        }
        $say('complete_batches', $batches);
        $say('complete_outcomes', implode('|', $outcomes));
        $sayJob($liveJob());
        $say('observed_rows', $observed());
        $say('vendor_profiles', $profiles());
        break;
    }

    // How long one page of products really takes, on this machine, with the
    // images the owner's products have.
    case 'cost': {
        $f = $fixture();
        $runId = (string) ($f['run'] ?? '');
        if ($runId === '') {
            $say('cost', '__no_plan__');
            break;
        }
        $limit = max(1, (int) ($args[1] ?? 50));
        $before = $observed();
        $t = microtime(true);
        $page = $service->importProductPage($runId, 0, $limit);
        $say('cost_limit', $limit);
        $say('cost_elapsed_ms', round((microtime(true) - $t) * 1000, 2));
        $say('cost_created', $observed() - $before);
        $say('cost_done', (int) $page['done']);
        $say('cost_peak_memory_mb', round(memory_get_peak_usage(true) / 1048576, 1));
        break;
    }

    // A position that has already killed workers: `tries` is what a dying
    // process leaves behind, so setting it is how a test says «three workers
    // died here» without killing three.
    case 'poison': {
        $job = $liveJob();
        if ($job === null) {
            $say('poison', '__no_job__');
            break;
        }
        $tries = max(0, (int) ($args[1] ?? 3));
        $cursor = $job->cursor;
        $cursor['tries'] = $tries;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}tmc_jobs SET `cursor` = %s, locked_until = %s WHERE id = %d",
            (string) json_encode($cursor, JSON_UNESCAPED_UNICODE),
            gmdate('Y-m-d H:i:s', strtotime(current_time('mysql')) - 10),
            $job->id
        ));
        $say('poison_tries', $tries);
        $say('poison_cursor', $cursor);
        $before = $observed();
        $results = $c->get(JobRunner::class)->run(1);
        $say('poison_says', $results === [] ? 'nothing_was_in_the_queue' : $results[0]['outcome']);
        $say('poison_failed_delta', $results === [] ? '__none__' : $results[0]['failed']);
        $after = $liveJob();
        $say('poison_cursor_after', $after === null ? '__none__' : $after->cursor);
        $say('poison_cursor_moved', $after !== null && $after->cursor !== $cursor);
        $say('poison_job_failed', $after === null ? '__none__' : $after->failed);
        $say('poison_created', $observed() - $before);
        break;
    }

    // The recovery the page offers for a worker that no longer exists: end the
    // lease, then run one batch from the recorded position.
    case 'hand-over': {
        $job = $liveJob();
        if ($job === null) {
            $say('hand_over', '__no_job__');
            break;
        }
        $say('before_locked_until', $job->lockedUntil === '' ? '__none__' : $job->lockedUntil);
        $say('before_done', $job->done);
        $say('before_cursor', $job->cursor);
        $say('claim_finds_anything', $c->get(JobRunner::class)->run(1) !== []);
        $say('handed_over', method_exists($jobs, 'handOver') ? $jobs->handOver($job->id) : '__absent__');
        $results = $c->get(JobRunner::class)->run(1);
        $say('after_hand_over_batches', count($results));
        $say('after_hand_over_says', $results === [] ? 'nothing_was_in_the_queue' : $results[0]['outcome']);
        $say('after_hand_over_checkpoints', $results === [] ? '__none__' : ($results[0]['checkpoints'] ?? '__absent__'));
        $sayJob($liveJob());
        $say('observed_rows', $observed());
        break;
    }

    // What the page needs in order to say WHY a run found nothing.
    case 'why': {
        $live = method_exists($jobs, 'live') ? $jobs->live(50) : [];
        $say('live_jobs', count($live));
        $held = 0;
        foreach ($live as $job) {
            if ($job->status === JobStatus::Running && $job->lockedUntil !== '' && strtotime($job->lockedUntil) > strtotime(current_time('mysql'))) {
                $held++;
                $say('held_job_' . $job->id, 'until:' . $job->lockedUntil . ' tries:' . (int) ($job->cursor['tries'] ?? 0));
            }
        }
        $say('jobs_holding_a_live_lease', $held);
        $say('a_run_would_find_anything', $c->get(JobRunner::class)->run(0) !== []);
        break;
    }

    // Everything of the shop's and of Dokan's that this import must not have
    // touched, hashed — so «محصولات ووکامرس تغییر نکردند» is a number.
    case 'untouched': {
        $rows = $wpdb->get_results(
            "SELECT p.ID, p.post_author, p.post_status, p.post_title, p.post_date, p.post_modified
             FROM {$wpdb->posts} p
             WHERE p.post_type = 'product' AND p.post_title LIKE 'کالای دکان %'
             ORDER BY p.ID ASC",
            ARRAY_A
        );
        $material = [];
        foreach ($rows as $row) {
            $id = (int) $row['ID'];
            $material[] = $row + [
                'regular_price' => get_post_meta($id, '_regular_price', true),
                'price' => get_post_meta($id, '_price', true),
                'sku' => get_post_meta($id, '_sku', true),
                'stock' => get_post_meta($id, '_stock', true),
                'thumbnail' => get_post_meta($id, '_thumbnail_id', true),
            ];
        }
        $say('wc_products', count($rows));
        $say('wc_digest', hash('sha256', (string) json_encode($material, JSON_UNESCAPED_UNICODE)));
        $say('dokan_sellers', (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'dokan_enable_selling'"
        ));
        $say('dokan_orders_rows', (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}dokan_orders"
        ));
        // One marketplace row per WooCommerce product, or the name of the one
        // that got two.
        $dup = $wpdb->get_results(
            "SELECT wc_product_id, COUNT(*) AS n FROM {$wpdb->prefix}tmc_products
             WHERE wc_product_id > 0 GROUP BY wc_product_id HAVING n > 1",
            ARRAY_A
        );
        $say('duplicate_mappings', count($dup));
        $say('duplicate_ids', $dup === [] ? '__none__' : implode(',', array_map(static fn (array $r): string => (string) $r['wc_product_id'], $dup)));
        $say('duplicate_vendor_profiles', (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM (SELECT user_id FROM {$wpdb->prefix}tmc_vendor_profiles GROUP BY user_id HAVING COUNT(*) > 1) d"
        ));
        break;
    }

    case 'report': {
        $reader = $c->get(DokanReaderInterface::class);
        $say('reader_available', $reader->isAvailable());
        $say('reader_vendors', count($reader->vendors()));
        $say('reader_products', count($reader->products()));
        $say('observed_rows', $observed());
        $say('vendor_profiles', $profiles());
        $sayJob($liveJob());
        $census = $jobs->census();
        foreach (['pending', 'running', 'stalled'] as $k) {
            $say('census_' . $k, (int) ($census[$k] ?? 0));
        }
        $say('census_stuck', array_key_exists('stuck', $census) ? (int) $census['stuck'] : '__absent__');
        break;
    }

    case 'reset': {
        $f = $fixture();
        // The documented give-back first, so the marketplace rows go the way a
        // manager would undo them rather than by SQL.
        $rolled = '__none__';
        foreach (array_keys($service->runs()) as $runId) {
            $result = $service->rollback((string) $runId);
            $rolled = $runId . ':' . ($result->ok ? 'ok' : $result->code);
        }
        $say('rolled_back', $rolled);
        $wpdb->query("DELETE FROM {$wpdb->prefix}tmc_jobs WHERE job_type = '" . DokanImportJob::TYPE . "'");
        // Whatever rollback declined to take, so a later run does not inherit
        // a half-undone fixture.
        foreach ($f['products'] as $post) {
            $row = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}tmc_products WHERE wc_product_id = %d",
                (int) $post
            ));
            if ($row !== null) {
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}tmc_product_images WHERE product_id = %d", (int) $row));
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}tmc_products WHERE id = %d", (int) $row));
            }
            wp_delete_post((int) $post, true);
        }
        foreach ($f['vendors'] as $user) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}tmc_vendor_profiles WHERE user_id = %d", (int) $user));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}tmc_vendor_stores WHERE user_id = %d", (int) $user));
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user((int) $user);
        }
        // Every attachment the import copied for a vendor, plus our source.
        $copies = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_title = 'tmc-p71-source'"
        );
        foreach ($copies as $cid) {
            wp_delete_attachment((int) $cid, true);
        }
        if (($f['table'] ?? false) === true) {
            $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}dokan_orders`");
        }
        delete_option($FIXTURE);
        $say('reset_products_left', (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_title LIKE 'کالای دکان %'"
        ));
        $say('reset_observed_left', $observed());
        $say('reset_sellers_left', (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'dokan_enable_selling'"
        ));
        $say('reset_images_left', count($copies) === 0 ? 0 : (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_title = 'tmc-p71-source'"
        ));
        $say('reset_jobs_left', (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tmc_jobs WHERE job_type = '" . DokanImportJob::TYPE . "'"
        ));
        break;
    }

    default:
        $say('unknown_verb', $command);
}
