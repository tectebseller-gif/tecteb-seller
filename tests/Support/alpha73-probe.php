<?php
/**
 * The gap between a validated lease and the write that depends on it.
 *
 * `alpha.73` moved the claim in front of the unit's writes and added a second
 * question in the middle of the slow half — and the owner then read the order
 * back out of the source:
 *
 *     duplicateForVendor() → progress->stillOurs() → products->saveImages()
 *
 * That is still CHECK, then WRITE, with two round trips between them. A worker
 * parked in the gap has already been told it owns the import, and nothing it
 * is told afterwards can take back a write it has already made. So: park A
 * there, hand the job to B, let B attach ITS copy and record its progress,
 * then release A. If A's `saveImages()` runs, it replaces B's picture and
 * B's copy is left in the media library with nothing pointing at it — and A's
 * own checkpoint refusing a moment later undoes none of it.
 *
 * The second window named in the report is between `claim(unit)` and
 * `create()`/`rememberRun()`. It is measured here too, and the answer is NOT
 * the same: see `create-gap`.
 *
 * **Where the park is placed, and why on the SQL stream.** The measurement has
 * to run on the BASELINE's bytes, and the baseline is the installed package —
 * so the stop cannot be an edit to `src/`. `$wpdb` applies its own `query`
 * filter to every statement, so the gap can be named by the statement that
 * opens the dependent write: the `DELETE` that begins `saveImages()`, or the
 * `INSERT` that makes the row. See `alpha73-worker.php`.
 *
 * **The invariant, chosen so it does not depend on which tree answers.**
 * «Did A write after losing the job» is not usable on its own here: on a tree
 * where the write phase and the hand-over are mutually exclusive, A is
 * released while it still legitimately owns the lease, so of course it writes
 * — and that is correct. What cannot be true on any sound tree is a vendor
 * copy that nothing points at, a product carrying two copies, a duplicated
 * mapping, or a manifest and a row set that disagree. Those are the keys.
 *
 * The fixture is the one `alpha72-probe.php` arranges, and `alpha72-probe.php
 * reset` is what gives it back. ⛔ Dokan is NOT running and cannot be
 * (`abzarwp/` is SourceGuardian-encrypted — `alpha.53`); the data is
 * Dokan-shaped and the reader is the real `WpDokanReader`.
 *
 *   wp eval-file tests/Support/alpha73-probe.php <verb> [args]
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\JobRepositoryInterface;
use Tecteb\Marketplace\Core\Jobs\JobRunner;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Migration\Application\DokanImportJob;
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

$FIXTURE = 'tmc_p72_fixture';
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
    $base = ['vendors' => [], 'products' => [], 'source' => 0, 'table' => false, 'run' => '', 'total' => 0];
    return is_array($raw) ? $raw + $base : $base;
};
$remember = static function (array $f) use ($FIXTURE): void {
    update_option($FIXTURE, $f, false);
};
$P = static fn (string $t): string => $wpdb->prefix . $t;

$mapped = static function () use ($wpdb, $P): int {
    return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $P('tmc_products') . " WHERE link_ownership = 'observed'");
};
$duplicateMappings = static function () use ($wpdb, $P): int {
    return (int) $wpdb->get_var(
        'SELECT COUNT(*) FROM (SELECT wc_product_id FROM ' . $P('tmc_products')
        . ' WHERE wc_product_id > 0 GROUP BY wc_product_id HAVING COUNT(*) > 1) d'
    );
};
$withoutImage = static function () use ($wpdb, $P): int {
    return (int) $wpdb->get_var(
        'SELECT COUNT(*) FROM ' . $P('tmc_products') . ' p
         WHERE p.link_ownership = \'observed\'
           AND NOT EXISTS (SELECT 1 FROM ' . $P('tmc_product_images') . ' i WHERE i.product_id = p.id)'
    );
};
/** Every vendor copy of the fixture's one source picture. */
$copies = static function () use ($wpdb, $fixture): array {
    $f = $fixture();
    $ids = (array) $wpdb->get_col(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_title = 'tmc-p72-source' ORDER BY ID ASC"
    );
    return array_values(array_filter(
        array_map('intval', $ids),
        static fn (int $id): bool => $id !== (int) $f['source']
    ));
};
/**
 * A copy nothing points at — the litter this plugin must never leave in
 * somebody's media library. Both references are asked, because a product's
 * featured picture is a COLUMN and not a gallery row (`alpha.43`).
 */
$orphanCopies = static function () use ($wpdb, $P, $copies): array {
    $out = [];
    foreach ($copies() as $id) {
        $used = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $P('tmc_product_images') . ' WHERE media_id = %d',
            $id
        )) + (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $P('tmc_products') . ' WHERE main_image_id = %d',
            $id
        ));
        if ($used === 0) {
            $out[] = $id;
        }
    }
    return $out;
};
$manifestProducts = static function () use ($service): int {
    $n = 0;
    foreach ($service->runs() as $row) {
        $n += count((array) ($row['products'] ?? []));
    }
    return $n;
};
/** Marketplace rows the manifest names that do not exist. */
$manifestGhosts = static function () use ($service, $wpdb, $P): int {
    $n = 0;
    foreach ($service->runs() as $row) {
        foreach ((array) ($row['products'] ?? []) as $id) {
            $found = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $P('tmc_products') . ' WHERE id = %d',
                (int) $id
            ));
            if ($found === 0) {
                $n++;
            }
        }
    }
    return $n;
};
$importDigest = static function () use ($wpdb, $P): string {
    $rows = (array) $wpdb->get_results(
        'SELECT p.id, p.wc_product_id, p.main_image_id,
                (SELECT GROUP_CONCAT(i.media_id ORDER BY i.media_id) FROM ' . $P('tmc_product_images') . ' i
                  WHERE i.product_id = p.id) AS media
         FROM ' . $P('tmc_products') . " p
         WHERE p.link_ownership = 'observed' ORDER BY p.id ASC",
        ARRAY_A
    );
    return hash('sha256', (string) json_encode($rows, JSON_UNESCAPED_UNICODE));
};
$liveJob = static function () use ($jobs) {
    foreach ($jobs->recent([], 50) as $job) {
        if ($job->type === DokanImportJob::TYPE) {
            return $job;
        }
    }
    return null;
};

$repo = dirname(__DIR__, 2);
$wpPath = (string) (getenv('TMC_WP_PATH') ?: ABSPATH);
$cli = (string) (getenv('TMC_WPCLI') ?: '/usr/local/bin/wp');

/** @return array{proc:resource, out:resource, err:resource}|null */
$spawn = static function (string $script, array $env, array $argv) use ($repo, $wpPath, $cli): ?array {
    $prefix = '';
    foreach ($env as $k => $v) {
        $prefix .= $k . '=' . escapeshellarg((string) $v) . ' ';
    }
    // `exec`, so the pid belongs to the work and not to a shell (`alpha.72`).
    $cmd = $prefix . sprintf(
        'exec php %s --allow-root --path=%s eval-file %s %s 2>&1',
        escapeshellarg($cli),
        escapeshellarg($wpPath),
        escapeshellarg($repo . '/tests/Support/' . $script),
        implode(' ', array_map('escapeshellarg', array_map('strval', $argv)))
    );
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) {
        return null;
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    return ['proc' => $p, 'out' => $pipes[1], 'err' => $pipes[2], 'text' => ''];
};
$await = static function (array &$w, string $needle, float $seconds): bool {
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        $w['text'] .= (string) stream_get_contents($w['out']);
        if (str_contains($w['text'], $needle)) {
            return true;
        }
        if (!proc_get_status($w['proc'])['running']) {
            $w['text'] .= (string) stream_get_contents($w['out']);
            return str_contains($w['text'], $needle);
        }
        usleep(30000);
    }
    return false;
};
$reap = static function (array &$w, int $seconds = 180): string {
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        $w['text'] .= (string) stream_get_contents($w['out']);
        if (!proc_get_status($w['proc'])['running']) {
            break;
        }
        usleep(30000);
    }
    $w['text'] .= (string) stream_get_contents($w['out']);
    @fclose($w['out']);
    @fclose($w['err']);
    proc_close($w['proc']);
    return trim(preg_replace('~\s+~', ' ', $w['text']));
};
$release = static function (string $fifo): void {
    $h = @fopen($fifo, 'w');
    if ($h !== false) {
        @fwrite($h, "go\n");
        @fclose($h);
    }
};
$pick = static function (string $text, string $key): string {
    return preg_match('~\b' . preg_quote($key, '~') . '=(\S+)~', $text, $m) === 1 ? $m[1] : '__absent__';
};
/** The whole value, for keys whose value carries spaces of its own. */
$pickAll = static function (string $text, string $key): string {
    return preg_match('~\b' . preg_quote($key, '~') . '=(.*?)(?= worker_|$)~', $text, $m) === 1
        ? trim($m[1])
        : '__absent__';
};

/**
 * One scenario: park A at a named write, hand the job over, let B finish,
 * release A — and report what the media library looks like afterwards.
 */
$gap = static function (string $stop) use (
    $say,
    $spawn,
    $await,
    $reap,
    $release,
    $pick,
    $pickAll,
    $liveJob,
    $mapped,
    $withoutImage,
    $duplicateMappings,
    $copies,
    $orphanCopies,
    $manifestProducts,
    $manifestGhosts,
    $importDigest,
    $c
): void {
    $fifo = sys_get_temp_dir() . '/tmc-p73-' . bin2hex(random_bytes(4)) . '.fifo';
    @posix_mkfifo($fifo, 0600);
    if (!file_exists($fifo)) {
        $say('gap', '__no_fifo__');
        return;
    }
    $say('stop_point', $stop);
    $say('copies_before', count($copies()));
    $say('mapped_before', $mapped());
    $say('digest_before', $importDigest());

    $a = $spawn('alpha73-worker.php', [
        'TMC_P73_STOP' => $stop,
        'TMC_P73_FIFO' => $fifo,
    ], [1]);
    if ($a === null) {
        $say('gap', '__cannot_start_a__');
        @unlink($fifo);
        return;
    }
    if (!$await($a, 'worker_parked_before=' . $stop, 150.0)) {
        $say('a_parked', 'no');
        $say('a_said', $reap($a, 30));
        @unlink($fifo);
        return;
    }
    $say('a_parked', 'yes');
    $job = $liveJob();
    $jobId = $job === null ? 0 : $job->id;
    $say('job_id', $jobId);
    $say('a_is_on_unit', $job === null ? '__none__' : ($job->cursor['at'] ?? '__absent__'));

    // The hand-over, in its own process, while A sits in the gap. Whether it
    // can get through is the whole question: a tree that couples the check to
    // the write cannot let it in, and a tree that only checks has nothing to
    // stop it.
    $h = $spawn('concurrent-job-handover.php', [], [$jobId, 1]);
    if ($h === null) {
        $say('gap', '__cannot_start_handover__');
        $release($fifo);
        $reap($a);
        @unlink($fifo);
        return;
    }
    $await($h, 'handover_started', 20.0);
    $got = $await($h, 'handover_finished=1', 8.0);
    $say('hand_over_got_through_while_a_was_parked', $got);

    if ($got) {
        // Nothing stood in its way, so the scenario the report describes is
        // reachable: B takes the job and finishes the unit BEFORE A moves.
        $handOverText = $reap($h, 20);
        $say('hand_over_answer', $pick($handOverText, 'handover_answer'));
        $b = $c->get(JobRunner::class)->run(3);
        $say('b_batches', count($b));
        $say('b_outcome', $b === [] ? 'nothing_was_in_the_queue' : (string) $b[0]['outcome']);
        $say('copies_after_b', count($copies()));
        $digestAfterB = $importDigest();
        $copiesAfterB = $copies();
        $say('digest_after_b', $digestAfterB);
        $release($fifo);
        $aText = $reap($a);
        // The whole question, in one key: did anything the import owns move
        // AFTER the worker that owns the import had finished with it?
        $say('a_wrote_after_losing_the_job', $digestAfterB !== $importDigest());
        $say('a_added_a_copy_after_losing_the_job', count($copies()) > count($copiesAfterB));
    } else {
        // It could not. A is released so its unit of work can close, and only
        // then does the lease move — which is the ordering the fix promises.
        $release($fifo);
        $aText = $reap($a);
        $handOverText = $reap($h, 30);
        $say('hand_over_answer', $pick($handOverText, 'handover_answer'));
        $say('hand_over_waited_ms', $pick($handOverText, 'handover_waited_ms'));
        $say('digest_after_a_closed', $importDigest());
        // ⛔ Said in both branches, so one key can be read on both trees. Here
        // it is false for a reason worth naming: A never lost the job WHILE it
        // was writing, because the hand-over could not land until A's unit of
        // work had closed. A refusal that arrives after the write is a report;
        // this one arrives before it.
        $say('a_wrote_after_losing_the_job', false);
        $say('a_added_a_copy_after_losing_the_job', false);
        $b = $c->get(JobRunner::class)->run(3);
        $say('b_batches', count($b));
        $say('b_outcome', $b === [] ? 'nothing_was_in_the_queue' : (string) $b[0]['outcome']);
    }

    $say('a_outcome', $pickAll($aText, 'worker_batch_0'));
    $say('a_write_phase_ms', $pick($aText, 'worker_write_phase_ms'));
    $say('a_released', str_contains($aText, 'worker_released=1'));

    // ------------------------------------------------- what the shop is left with
    $orphans = $orphanCopies();
    $say('copies_at_the_end', count($copies()));
    $say('orphan_copies', count($orphans));
    $say('orphan_copy_ids', $orphans === [] ? '__none__' : implode(',', $orphans));
    $say('mapped_at_the_end', $mapped());
    $say('duplicate_mappings', $duplicateMappings());
    $say('products_without_image', $withoutImage());
    $say('manifest_products', $manifestProducts());
    $say('manifest_names_a_row_that_is_gone', $manifestGhosts());
    $say('digest_at_the_end', $importDigest());
    @unlink($fifo);
};

switch ($command) {
    /**
     * Put the import back to «planned and queued, nothing imported», without
     * touching the Dokan-shaped source data.
     *
     * The rollback goes through the DOCUMENTED path; only the vendor copies it
     * does not own are then taken back, found by the title this fixture gave
     * them — no sweep, no age cut (`alpha.42`).
     */
    case 'rearm': {
        $f = $fixture();
        $rolled = [];
        foreach (array_keys($service->runs()) as $runId) {
            $r = $service->rollback((string) $runId);
            $rolled[] = $runId . ':' . ($r->ok ? 'ok' : $r->code);
        }
        $say('rolled_back', $rolled === [] ? '__none__' : implode(' ', $rolled));
        foreach ($copies() as $cid) {
            wp_delete_attachment($cid, true);
        }
        $wpdb->query('DELETE FROM ' . $P('tmc_jobs') . " WHERE job_type = '" . DokanImportJob::TYPE . "'");
        $plan = $service->plan();
        $summary = $plan->summary();
        $f['run'] = $plan->runId;
        $f['total'] = (int) $summary['vendors'] + (int) $summary['products'];
        $remember($f);
        // ⛔ The dry run's own numbers are reported HERE rather than by a
        // separate `plan` call, for two reasons measured the hard way: a plan
        // asked BEFORE the rollback answers about a site that still holds the
        // previous scenario's import («0 shops left»), and `alpha72-probe.php
        // plan` REWRITES the fixture's run id — so asking it afterwards left
        // the completion report looking at a run nothing had imported, and
        // six controls failed about a build that was working.
        $say('rearm_plan_vendors', (int) $summary['vendors']);
        $say('rearm_plan_products', (int) $summary['products']);
        $say('rearm_plan_conflicts', (int) ($summary['conflicts'] ?? 0));
        $queued = $jobs->enqueue(
            DokanImportJob::TYPE,
            ['run_id' => $f['run'], 'total' => (int) $f['total']],
            DokanImportJob::TYPE,
            $manager
        );
        $say('rearm_run', $f['run']);
        $say('rearm_queued', $queued['id']);
        $say('rearm_mapped', $mapped());
        $say('rearm_copies', count($copies()));
        break;
    }

    /** Walk the vendors phase so the next batch is a PRODUCT batch. */
    case 'to-products': {
        for ($i = 0; $i < 6; $i++) {
            $c->get(JobRunner::class)->run(1);
            $job = $liveJob();
            if ($job !== null && (string) ($job->cursor['phase'] ?? '') === 'products') {
                break;
            }
        }
        $job = $liveJob();
        $say('phase', $job === null ? '__none__' : ($job->cursor['phase'] ?? '__absent__'));
        $say('after', $job === null ? '__none__' : ($job->cursor['after'] ?? '__absent__'));
        $say('mapped', $mapped());
        break;
    }

    // ============================================ §1 the gap the owner named
    case 'attach-gap': {
        $gap('before-attach');
        break;
    }

    // ===================== §2 the other window: claim(unit) → create()
    case 'create-gap': {
        $gap('before-create');
        break;
    }

    /**
     * WHY the create window is already shut, named rather than inferred.
     *
     * `create-gap` answers `duplicate_mappings=0` on both trees, and a number
     * that is right for an unknown reason is not evidence. The reason is the
     * UNIQUE key `tmc_product_wc` on `wc_product_id`, added by migration 6:
     * the stale worker's insert loses at the index, `create()` returns `0`
     * (there is no create token on the import path, so there is nothing to
     * resolve to) and the caller names it `create_failed`.
     *
     * ⛔ So that window is held by the SCHEMA, not by the lease — which is
     * worth writing down, because the day somebody drops that key the window
     * reopens and nothing else is watching it.
     */
    case 'create-gap-mechanism': {
        $f = $fixture();
        $unique = (array) $wpdb->get_results(
            'SHOW INDEX FROM ' . $P('tmc_products') . " WHERE Column_name = 'wc_product_id'",
            ARRAY_A
        );
        $isUnique = false;
        foreach ($unique as $row) {
            if ((int) ($row['Non_unique'] ?? 1) === 0) {
                $isUnique = true;
                $say('wc_product_id_unique_key', (string) $row['Key_name']);
            }
        }
        $say('wc_product_id_has_a_unique_key', $isUnique);
        $wc = (int) ($f['products'][0] ?? 0);
        $mine = $service->runProgress((string) $f['run']);
        $say('wc_product_under_test', $wc);
        $say('rows_for_it_before', (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $P('tmc_products') . ' WHERE wc_product_id = %d',
            $wc
        )));
        // A SECOND row for the same WooCommerce product, attempted straight at
        // the repository — the write a stale worker would make.
        $second = $c->get(\Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface::class)->create(
            (int) ($f['vendors'][0] ?? $manager),
            new \Tecteb\Marketplace\Modules\Product\Domain\ProductDetails(
                title: 'دوباره‌سازی مکانیزم',
                categoryKey: '',
                priceMinor: 1000,
                sku: '',
                stock: 1
            ),
            \Tecteb\Marketplace\Modules\Product\Domain\ProductStatus::Draft,
            \Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership::Observed,
            $wc,
            (string) $f['run']
        );
        $say('second_row_for_the_same_wc_product', $second === 0 ? 'refused' : ('made:' . $second));
        if ($second > 0) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $P('tmc_products') . ' WHERE id = %d', $second));
            $say('second_row_cleaned_up', 'yes');
        }
        $say('rows_for_it_after', (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $P('tmc_products') . ' WHERE wc_product_id = %d',
            $wc
        )));
        $say('run_progress_products', (int) ($mine['products'] ?? 0));
        break;
    }

    // ===== control: both trees refuse a resume whose lease has already gone
    //
    // The park is INSIDE the image copy, so A has validated nothing yet when
    // the job moves. `alpha.73` already refuses this one, and it must keep
    // refusing it — a fix that only works because the earlier one stopped
    // working is not a fix.
    case 'stale-resume': {
        $fifo = sys_get_temp_dir() . '/tmc-p73-' . bin2hex(random_bytes(4)) . '.fifo';
        @posix_mkfifo($fifo, 0600);
        $say('copies_before', count($copies()));
        $a = $spawn('alpha72-worker.php', [
            'TMC_P72_MODE' => 'hold-at',
            'TMC_P72_AT' => 1,
            'TMC_P72_FIFO' => $fifo,
        ], [1]);
        if ($a === null || !$await($a, 'worker_holding_at=1', 150.0)) {
            $say('stale_resume', '__cannot_park_a__');
            if ($a !== null) {
                $release($fifo);
                $reap($a);
            }
            @unlink($fifo);
            break;
        }
        $job = $liveJob();
        $h = $spawn('concurrent-job-handover.php', [], [$job === null ? 0 : $job->id, 1]);
        $hText = $h === null ? '' : $reap($h, 30);
        $say('hand_over_answer', $pick($hText, 'handover_answer'));
        $b = $c->get(JobRunner::class)->run(3);
        $say('b_batches', count($b));
        $digestAfterB = $importDigest();
        $release($fifo);
        $aText = $reap($a);
        $say('a_outcome', $pickAll($aText, 'worker_batch_0'));
        $say('a_wrote_after_losing_the_job', $digestAfterB !== $importDigest());
        $say('orphan_copies', count($orphanCopies()));
        $say('duplicate_mappings', $duplicateMappings());
        $say('products_without_image', $withoutImage());
        @unlink($fifo);
        break;
    }

    /** Control: nothing interrupted. Answers the same on both trees. */
    case 'clean': {
        $a = $spawn('alpha73-worker.php', [], [8]);
        $text = $a === null ? '' : $reap($a, 300);
        $say('clean_parked', $pick($text, 'worker_parked'));
        $say('clean_write_phase_ms', $pick($text, 'worker_write_phase_ms'));
        $say('clean_mapped', $mapped());
        $say('clean_products_without_image', $withoutImage());
        $say('clean_duplicate_mappings', $duplicateMappings());
        $say('clean_orphan_copies', count($orphanCopies()));
        $say('clean_copies', count($copies()));
        $say('clean_manifest_products', $manifestProducts());
        break;
    }

    /** The five figures, read from the service rather than from the tables. */
    case 'completion': {
        $f = $fixture();
        $report = $service->importCompletion((string) $f['run']);
        foreach (['mapped', 'complete', 'without_image', 'source_unreadable', 'duplicate_mappings', 'finished'] as $k) {
            $say('completion_' . $k, array_key_exists($k, $report) ? $report[$k] : '__absent__');
        }
        $say('completion_incomplete_ids', $report['incomplete'] ?? '__absent__');
        break;
    }

    case 'report': {
        $say('mapped', $mapped());
        $say('products_without_image', $withoutImage());
        $say('duplicate_mappings', $duplicateMappings());
        $say('copies', count($copies()));
        $say('orphan_copies', count($orphanCopies()));
        $say('manifest_products', $manifestProducts());
        $say('manifest_names_a_row_that_is_gone', $manifestGhosts());
        $say('import_digest', $importDigest());
        $job = $liveJob();
        $say('job_status', $job === null ? '__none__' : $job->status->value);
        $say('job_cursor', $job === null ? '__none__' : $job->cursor);
        break;
    }

    default:
        $say('unknown_verb', $command);
}
