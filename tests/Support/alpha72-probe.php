<?php
/**
 * The three gaps in `alpha.72`'s own continuation path, measured.
 *
 * All three are about the same seam: **the product and its media are written
 * BEFORE anything token-guarded.** `alpha.72` moved the checkpoint to one per
 * unit, which fixed the figure on the page — and left the write itself
 * unguarded, so:
 *
 *   §1  `handOver()` expires a lease and asks nothing about the worker that
 *       held it. Two LIVE workers can then be inside the same page: the
 *       resumed one is refused at its next checkpoint, which is far too late,
 *       because by then it has already created a product row, copied a file
 *       and written an attachment. A refused checkpoint is not the proof.
 *   §2  the row and its mapping are written before `duplicateForVendor()` and
 *       `saveImages()`, and the next pass finds the mapping and counts that
 *       product `done` through the skip path. A mapping is not a finished
 *       product, and a product with no picture is exactly what `alpha.71` may
 *       have left behind on staging.
 *   §3  `tries` is raised once at the top of the batch and `recordOne` zeroes
 *       it after every unit — so from the SECOND product on there is no fresh
 *       increment. Product one succeeds, product two is interrupted for ever,
 *       the counter never reaches the limit and the step-over never fires.
 *       And a refusal recorded mid-batch is written into a cursor built
 *       before it happened, so an interrupted batch loses it.
 *
 * Every verb prints ONE key per line (`alpha.41`) and loads on BOTH trees
 * (`alpha.32`): anything this round adds is asked for with
 * `method_exists`/`array_key_exists` and reported `__absent__` on the base.
 *
 * ⛔ Dokan is NOT running and cannot (`abzarwp/` is SourceGuardian-encrypted,
 * `alpha.53`). The fixture is Dokan-SHAPED data read by the REAL
 * `WpDokanReader`; only the data is ours. `reset` undoes the import through
 * the documented `rollback()` and then deletes exactly what it created.
 *
 *   wp eval-file tests/Support/alpha72-probe.php <verb> [args]
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\JobRepositoryInterface;
use Tecteb\Marketplace\Core\Jobs\JobRunner;
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

/** Marketplace rows this import made. */
$mapped = static function () use ($wpdb, $P): int {
    return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $P('tmc_products') . " WHERE link_ownership = 'observed'");
};
/** Rows pointing at ONE WooCommerce product — the duplicate that must not exist. */
$duplicateMappings = static function () use ($wpdb, $P): int {
    return (int) $wpdb->get_var(
        'SELECT COUNT(*) FROM (SELECT wc_product_id FROM ' . $P('tmc_products')
        . ' WHERE wc_product_id > 0 GROUP BY wc_product_id HAVING COUNT(*) > 1) d'
    );
};
/** Mapped rows that have NO image of their own. «Mapped» is not «finished». */
$withoutImage = static function () use ($wpdb, $P): int {
    return (int) $wpdb->get_var(
        'SELECT COUNT(*) FROM ' . $P('tmc_products') . ' p
         WHERE p.link_ownership = \'observed\'
           AND NOT EXISTS (SELECT 1 FROM ' . $P('tmc_product_images') . ' i WHERE i.product_id = p.id)'
    );
};
/** Vendor copies of the fixture's one source picture — one per product, never two. */
$vendorCopies = static function () use ($wpdb): int {
    return (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_title = 'tmc-p72-source'"
    ) - 1;                                  // minus the source itself
};
/** The run manifest — the only place a VENDOR's id is written down. */
$manifest = static function () use ($service): array {
    $runs = $service->runs();
    $out = ['runs' => count($runs), 'vendors' => 0, 'products' => 0];
    foreach ($runs as $row) {
        $out['vendors'] += count((array) ($row['vendors'] ?? []));
        $out['products'] += count((array) ($row['products'] ?? []));
    }
    return $out;
};
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
    $say('job_cursor_at', array_key_exists('at', $job->cursor) ? $job->cursor['at'] : '__absent__');
    $say('job_cursor_tries', (int) ($job->cursor['tries'] ?? 0));
    $say('job_refused', $job->cursor['refused'] ?? '__none__');
    $say('job_locked_until', $job->lockedUntil === '' ? '__none__' : $job->lockedUntil);
};
/**
 * Everything the IMPORT owns, hashed: the rows, their mappings and their
 * images. The question «did a worker that no longer owns the job write
 * anything?» is this digest before and after it was released.
 */
$importDigest = static function () use ($wpdb, $P): string {
    $rows = (array) $wpdb->get_results(
        'SELECT p.id, p.wc_product_id, p.vendor_user_id, p.import_run_id,
                (SELECT GROUP_CONCAT(i.media_id ORDER BY i.media_id) FROM ' . $P('tmc_product_images') . ' i
                  WHERE i.product_id = p.id) AS media
         FROM ' . $P('tmc_products') . " p
         WHERE p.link_ownership = 'observed' ORDER BY p.id ASC",
        ARRAY_A
    );
    return hash('sha256', (string) json_encode($rows, JSON_UNESCAPED_UNICODE));
};

/** Everything the shop owns that this import must not touch. */
$shopDigest = static function () use ($wpdb): string {
    $rows = $wpdb->get_results(
        "SELECT p.ID, p.post_author, p.post_status, p.post_title, p.post_modified
         FROM {$wpdb->posts} p
         WHERE p.post_type = 'product' AND p.post_title LIKE 'کالای ۷۲ %'
         ORDER BY p.ID ASC",
        ARRAY_A
    );
    $material = [];
    foreach ($rows as $row) {
        $id = (int) $row['ID'];
        $material[] = $row + [
            'thumb' => get_post_meta($id, '_thumbnail_id', true),
            'sku' => get_post_meta($id, '_sku', true),
        ];
    }
    return hash('sha256', (string) json_encode($material, JSON_UNESCAPED_UNICODE));
};

$repo = dirname(__DIR__, 2);
$wpPath = (string) (getenv('TMC_WP_PATH') ?: ABSPATH);
$cli = (string) (getenv('TMC_WPCLI') ?: '/usr/local/bin/wp');
/**
 * Start a worker in its own process. Returns the handle so the caller decides
 * when, and whether, it is allowed to finish.
 *
 * `exec` so the pid proc_open hands back is the pid that does the work — the
 * `alpha.72` lesson, where the kill landed on `/bin/sh` and the worker carried
 * on orphaned.
 *
 * @return array{proc:resource, out:resource}|null
 */
$startWorker = static function (array $env) use ($repo, $wpPath, $cli): ?array {
    $prefix = '';
    foreach ($env as $k => $v) {
        $prefix .= $k . '=' . escapeshellarg((string) $v) . ' ';
    }
    $cmd = $prefix . sprintf(
        'exec php %s --allow-root --path=%s eval-file %s 1 2>&1',
        escapeshellarg($cli),
        escapeshellarg($wpPath),
        escapeshellarg($repo . '/tests/Support/alpha72-worker.php')
    );
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) {
        return null;
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    return ['proc' => $p, 'out' => $pipes[1], 'err' => $pipes[2]];
};
/** Read a worker's output until `$needle` appears or `$seconds` pass. */
$awaitLine = static function (array $w, string $needle, int $seconds): array {
    $text = '';
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        $text .= (string) stream_get_contents($w['out']);
        if (str_contains($text, $needle)) {
            return ['found' => true, 'text' => $text];
        }
        if (!proc_get_status($w['proc'])['running']) {
            $text .= (string) stream_get_contents($w['out']);
            return ['found' => str_contains($text, $needle), 'text' => $text];
        }
        usleep(40000);
    }
    return ['found' => false, 'text' => $text];
};
$reap = static function (array $w, int $seconds = 120): string {
    $text = '';
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        $text .= (string) stream_get_contents($w['out']);
        if (!proc_get_status($w['proc'])['running']) {
            break;
        }
        usleep(40000);
    }
    $text .= (string) stream_get_contents($w['out']);
    @fclose($w['out']);
    @fclose($w['err']);
    proc_close($w['proc']);
    return trim(preg_replace('~\s+~', ' ', $text));
};

switch ($command) {
    // ----------------------------------------------------------- the fixture
    case 'arrange': {
        $wanted = max(2, (int) ($args[1] ?? 6));
        $px = max(16, (int) ($args[2] ?? 900));
        $f = $fixture();

        $table = $P('dokan_orders');
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

        $uploads = wp_upload_dir();
        $path = trailingslashit($uploads['path']) . 'tmc-p72-source.png';
        if (!file_exists($path)) {
            $im = imagecreatetruecolor($px, $px);
            imagefill($im, 0, 0, imagecolorallocate($im, 236, 241, 247));
            for ($i = 0; $i < 40; $i++) {
                imagefilledellipse(
                    $im,
                    (int) ($px * (0.1 + 0.8 * ($i % 8) / 8)),
                    (int) ($px * (0.1 + 0.8 * intdiv($i, 8) / 5)),
                    (int) ($px / 7),
                    (int) ($px / 7),
                    imagecolorallocate($im, 30 + $i * 4, 110, 170)
                );
            }
            imagepng($im, $path);
            imagedestroy($im);
        }
        $source = (int) ($f['source'] ?? 0);
        if ($source === 0 || get_post($source) === null) {
            $source = (int) wp_insert_attachment([
                'post_mime_type' => 'image/png',
                'post_title' => 'tmc-p72-source',
                'post_status' => 'inherit',
            ], $path);
            $f['source'] = $source;
        }

        for ($v = 1; $v <= 2; $v++) {
            $login = 'p72seller' . $v;
            $user = get_user_by('login', $login);
            $id = $user === false ? 0 : (int) $user->ID;
            if ($id === 0) {
                $id = (int) wp_insert_user([
                    'user_login' => $login,
                    'user_pass' => wp_generate_password(20),
                    'user_email' => $login . '@example.invalid',
                    'display_name' => 'فروشگاه ۷۲ شمارهٔ ' . $v,
                    'role' => 'customer',
                ]);
                $f['vendors'][] = $id;
            }
            // The real reader finds a Dokan seller by the `seller` ROLE inside
            // the serialized capabilities meta. Dokan registers that role on
            // activation and cannot run here, so the meta is written directly.
            update_user_meta($id, $wpdb->prefix . 'capabilities', ['customer' => true, 'seller' => true]);
            update_user_meta($id, 'dokan_enable_selling', 'yes');
            update_user_meta($id, 'dokan_profile_settings', [
                'store_name' => 'فروشگاه ۷۲ شمارهٔ ' . $v,
                'enable_selling' => 'yes',
            ]);
        }
        $sellers = array_values(array_map(
            static fn (string $l): int => (int) get_user_by('login', $l)->ID,
            ['p72seller1', 'p72seller2']
        ));

        $have = count($f['products']);
        for ($i = $have; $i < $wanted; $i++) {
            $post = (int) wp_insert_post([
                'post_type' => 'product',
                'post_status' => 'publish',
                'post_title' => 'کالای ۷۲ ' . ($i + 1),
                'post_author' => $sellers[$i % count($sellers)],
            ], true);
            if ($post <= 0) {
                continue;
            }
            wp_set_object_terms($post, 'simple', 'product_type');
            update_post_meta($post, '_regular_price', (string) (200000 + $i * 1000));
            update_post_meta($post, '_price', (string) (200000 + $i * 1000));
            update_post_meta($post, '_sku', 'P72-' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT));
            update_post_meta($post, '_stock', (string) (4 + ($i % 5)));
            update_post_meta($post, '_manage_stock', 'yes');
            update_post_meta($post, '_thumbnail_id', (string) $source);
            $f['products'][] = $post;
        }
        $remember($f);

        $reader = $c->get(DokanReaderInterface::class);
        $say('reader_available', $reader->isAvailable());
        $say('reader_vendors', count($reader->vendors()));
        $say('reader_products', count($reader->products()));
        $say('source_attachment', $source);
        // How many of the fixture's WooCommerce products really carry a
        // featured image — the thing a half-made import is missing, so a
        // fixture without it cannot show the defect at all.
        // ⛔ SCOPED TO THE FIXTURE'S OWN POSTS. The first version counted
        // every product on the site with a thumbnail and answered 16 for a
        // fixture of 6 — a true number about the wrong set, which is the
        // oldest way an evidence script lies.
        $withPicture = 0;
        foreach ($f['products'] as $post) {
            if ((int) get_post_thumbnail_id((int) $post) > 0) {
                $withPicture++;
            }
        }
        $say('products_with_a_picture', $withPicture);
        $say('shop_digest', $shopDigest());
        break;
    }

    case 'plan': {
        $plan = $service->plan();
        $summary = $plan->summary();
        $say('plan_run_id', $plan->runId);
        $say('plan_vendors', (int) $summary['vendors']);
        $say('plan_products', (int) $summary['products']);
        $say('plan_conflicts', (int) ($summary['conflicts'] ?? 0));
        $f = $fixture();
        $f['run'] = $plan->runId;
        $f['total'] = (int) $summary['vendors'] + (int) $summary['products'];
        $remember($f);
        $say('plan_total', $f['total']);
        break;
    }

    case 'queue': {
        $f = $fixture();
        if (($f['run'] ?? '') === '') {
            $say('queued', '__no_plan__');
            break;
        }
        $queued = $jobs->enqueue(
            DokanImportJob::TYPE,
            ['run_id' => $f['run'], 'total' => (int) $f['total']],
            DokanImportJob::TYPE,
            $manager
        );
        $say('queued_id', $queued['id']);
        $say('queued_created', $queued['created']);
        break;
    }

    /** One batch in this process — the vendors phase, or a plain run. */
    case 'run': {
        $results = $c->get(JobRunner::class)->run(max(1, (int) ($args[1] ?? 1)));
        $say('run_batches', count($results));
        $say('run_says', $results === [] ? 'nothing_was_in_the_queue' : $results[0]['outcome']);
        $sayJob($liveJob());
        $say('mapped', $mapped());
        break;
    }

    // ========================================================= §1 two workers
    //
    // A is stopped INSIDE the first product's image work. The manager then
    // presses «ادامه از همین نقطه», B runs a whole batch, and only then is A
    // released. Everything A does after that moment is a second live worker
    // writing into a page somebody else owns.
    case 'race': {
        $fifo = sys_get_temp_dir() . '/tmc-p72-' . bin2hex(random_bytes(4)) . '.fifo';
        @posix_mkfifo($fifo, 0600);
        if (!file_exists($fifo)) {
            $say('race', '__no_fifo__');
            break;
        }
        $before = ['mapped' => $mapped(), 'copies' => $vendorCopies()];
        $a = $startWorker([
            'TMC_P72_MODE' => 'hold-at',
            'TMC_P72_AT' => 1,
            'TMC_P72_FIFO' => $fifo,
        ]);
        if ($a === null) {
            $say('race', '__cannot_start_a__');
            @unlink($fifo);
            break;
        }
        $held = $awaitLine($a, 'worker_holding_at=', 90);
        $say('a_reached_the_image_work', $held['found']);
        if (!$held['found']) {
            $say('a_said', trim(preg_replace('~\s+~', ' ', $held['text'])));
            $handle = @fopen($fifo, 'w');
            if ($handle !== false) {
                @fwrite($handle, "go\n");
                @fclose($handle);
            }
            $say('a_output', $reap($a));
            @unlink($fifo);
            break;
        }
        $heldJob = $liveJob();
        $say('a_holds_the_job', $heldJob?->id ?? 0);
        $say('mapped_while_a_is_held', $mapped());

        // The manager's button, exactly as the page calls it: carrying the
        // fingerprint of the lock the page was drawn from, and overriding the
        // «it wrote a moment ago» refusal the way a person who knows the
        // process is gone would.
        if (!method_exists($jobs, 'handOver')) {
            $say('handed_over', '__absent__');
        } else {
            $fingerprint = method_exists($jobs, 'lockFingerprint')
                ? $jobs->lockFingerprint((string) ($heldJob?->lockToken ?? ''), (string) ($heldJob?->lockedUntil ?? ''))
                : '';
            $say('seconds_since_write', method_exists($jobs, 'secondsSinceWrite')
                ? (string) $jobs->secondsSinceWrite((int) ($heldJob?->id ?? 0))
                : '__absent__');
            // Read on BOTH trees, so the answer has to be normalised:
            // `alpha.72` returned a bool and extra arguments to a userland
            // method are silently ignored, so these same three calls reach
            // the one-argument version and answer `true`/`false`.
            $answerOf = static fn (mixed $a): string => is_bool($a)
                ? ($a ? 'handed_over' : 'refused')
                : ($a === '' ? 'handed_over' : (string) $a);
            // Without the override first, so the refusal is on the record.
            $say(
                'hand_over_without_override',
                $answerOf($jobs->handOver((int) ($heldJob?->id ?? 0), $fingerprint))
            );
            $say(
                'hand_over_with_a_stale_fingerprint',
                $answerOf($jobs->handOver((int) ($heldJob?->id ?? 0), str_repeat('0', 64), true))
            );
            $say('handed_over', $answerOf($jobs->handOver((int) ($heldJob?->id ?? 0), $fingerprint, true)));
        }

        // B runs the import OUT, so A comes back into one that is already
        // reported finished — the worst version of the same window, and the
        // one a manager would actually produce by pressing the button and
        // then letting the queue tick.
        $b = $startWorker([]);
        $bText = $b === null ? '__cannot_start_b__' : $reap($b, 300);
        $say('b_output', $bText);
        $say('mapped_after_b', $mapped());
        $say('duplicate_mappings_after_b', $duplicateMappings());
        $say('missing_image_after_b', $withoutImage());
        $afterB = $liveJob();
        $say('job_status_after_b', $afterB?->status->value ?? '__none__');
        $say('job_done_after_b', (int) ($afterB?->done ?? 0));
        $digestAfterB = $importDigest();
        $copiesAfterB = $vendorCopies();
        $say('import_digest_after_b', $digestAfterB);
        $say('vendor_copies_after_b', $copiesAfterB);

        // …and only now does A carry on.
        $handle = @fopen($fifo, 'w');
        if ($handle !== false) {
            @fwrite($handle, "go\n");
            @fclose($handle);
        }
        $say('a_output', $reap($a, 300));
        @unlink($fifo);

        // THE MEASUREMENT. A's own checkpoint is refused — that is not the
        // question. The question is whether anything it did before reaching
        // that checkpoint landed in the import it no longer owns.
        $say('import_digest_after_a_resumed', $importDigest());
        $say('a_wrote_after_losing_the_job', $importDigest() !== $digestAfterB);
        $say('vendor_copies_after_a_resumed', $vendorCopies());
        // ADDED and HANDED BACK are different directions and only one of them
        // is a defect. A that comes back, finds the job gone and deletes the
        // copy it had made leaves FEWER attachments than B did — which is the
        // orphan from `alpha.42` not happening.
        $say('a_added_media_after_losing_the_job', $vendorCopies() > $copiesAfterB);
        $say('a_handed_its_own_copy_back', $vendorCopies() < $copiesAfterB);
        $say('mapped_after_a_resumed', $mapped());
        $say('duplicate_mappings', $duplicateMappings());
        $say('products_without_image', $withoutImage());
        $say('vendor_copies', $vendorCopies());
        $say('vendor_copies_before', $before['copies']);
        $say('manifest', $manifest());
        $say('shop_digest', $shopDigest());
        $sayJob($liveJob());
        break;
    }

    // THE CONTROL: the same two processes, the same pause — and NO hand-over.
    // B must find nothing and A must finish its own page normally. A
    // measurement that only ever looks at the broken case cannot tell a fix
    // from a feature that stopped working (`alpha.46`).
    case 'race-control': {
        $fifo = sys_get_temp_dir() . '/tmc-p72-' . bin2hex(random_bytes(4)) . '.fifo';
        @posix_mkfifo($fifo, 0600);
        $a = $startWorker([
            'TMC_P72_MODE' => 'hold-at',
            'TMC_P72_AT' => 1,
            'TMC_P72_FIFO' => $fifo,
        ]);
        if ($a === null) {
            $say('race_control', '__cannot_start_a__');
            @unlink($fifo);
            break;
        }
        $held = $awaitLine($a, 'worker_holding_at=', 90);
        $say('a_reached_the_image_work', $held['found']);
        $b = $startWorker([]);
        $bText = $b === null ? '__cannot_start_b__' : $reap($b, 120);
        $say('b_output', $bText);
        $say('b_found_nothing', str_contains($bText, 'worker_batches=0'));
        $handle = @fopen($fifo, 'w');
        if ($handle !== false) {
            @fwrite($handle, "go\n");
            @fclose($handle);
        }
        $say('a_output', $reap($a, 180));
        $say('duplicate_mappings', $duplicateMappings());
        $say('products_without_image', $withoutImage());
        $say('mapped', $mapped());
        @unlink($fifo);
        $sayJob($liveJob());
        break;
    }

    // ================================================ §3 the attempt counter
    //
    // THE OWNER'S SCENARIO: the first product succeeds and the second is
    // interrupted every single time. Each pass kills the worker at the first
    // image operation it reaches, which from the second pass on IS the second
    // product — so the same unit is attempted over and over, and the count
    // either follows it or does not exist.
    //
    // On `alpha.72` it did not exist: the first product's landing reset the
    // one counter there was, so `tries` read 0 after every pass and the
    // give-up could never fire.
    case 'stuck-unit': {
        $rounds = max(1, (int) ($args[1] ?? 4));
        for ($i = 1; $i <= $rounds; $i++) {
            $w = $startWorker(['TMC_P72_MODE' => 'kill-at', 'TMC_P72_AT' => $i === 1 ? 2 : 1]);
            if ($w === null) {
                $say('stuck_unit', '__cannot_start__');
                break 2;
            }
            $out = $reap($w, 180);
            $job = $liveJob();
            $cursor = $job?->cursor ?? [];
            $say('pass_' . $i . '_killed', str_contains($out, 'worker_killing_itself_at=') ? 'yes' : 'no');
            $say('pass_' . $i . '_at', (string) ($cursor['at'] ?? '__none__'));
            $say('pass_' . $i . '_tries', (string) ($cursor['tries'] ?? '__none__'));
            $say('pass_' . $i . '_after', (string) ($cursor['after'] ?? '__none__'));
            $say('pass_' . $i . '_done', (string) ($job?->done ?? '__none__'));
            $say('pass_' . $i . '_mapped', $mapped());
            if ($job !== null) {
                $jobs->handOver($job->id, '', true);
            }
        }
        // And now a pass nothing interrupts: the unit that kept killing
        // workers has to be named and stepped over, by its own id.
        $say('final_run', $c->get(JobRunner::class)->run(1) === [] ? '__nothing_ran__' : 'ran');
        $job = $liveJob();
        $say('final_at', (string) ($job?->cursor['at'] ?? '__none__'));
        $say('final_after', (string) ($job?->cursor['after'] ?? '__none__'));
        $say('final_tries', (string) ($job?->cursor['tries'] ?? '__none__'));
        $say('final_refused', implode(',', array_map('strval', (array) ($job?->cursor['refused'] ?? []))));
        $say('final_failed', (string) ($job?->failed ?? '__none__'));
        $say('mapped', $mapped());
        $say('products_without_image', $withoutImage());
        $say('duplicate_mappings', $duplicateMappings());
        break;
    }

    // =========================================== §4 the completion report
    //
    // Four numbers, read from the SERVICE — so the page and this measurement
    // cannot disagree about what «finished» means — next to the same counts
    // taken straight from the rows.
    case 'completion': {
        if (!method_exists($service, 'importCompletion')) {
            $say('import_completion', '__absent__');
            break;
        }
        $report = $service->importCompletion();
        foreach (['mapped', 'complete', 'without_image', 'source_unreadable', 'duplicate_mappings', 'scanned'] as $key) {
            $say('service_' . $key, (string) ($report[$key] ?? ''));
        }
        $say('service_finished', $report['finished'] ?? false);
        $say('service_incomplete_ids', implode(',', array_map('strval', $report['incomplete'] ?? [])));
        $say('rows_mapped', $mapped());
        $say('rows_without_image', $withoutImage());
        $say('rows_duplicate_mappings', $duplicateMappings());
        $say('vendor_copies', $vendorCopies());
        $say('manifest', $manifest());
        $say('shop_digest', $shopDigest());
        break;
    }

    // And finishing them, which is a path of its own because the job that
    // left them is long over and no batch will look at them again.
    case 'repair': {
        if (!method_exists($service, 'repairImports')) {
            $say('repair', '__absent__');
            break;
        }
        $before = $withoutImage();
        $result = $service->repairImports();
        $say('repair_ok', $result->ok);
        $say('repair_code', $result->code);
        $say('repaired', (string) ($result->context['repaired'] ?? '__absent__'));
        $say('still_incomplete', (string) ($result->context['still_incomplete'] ?? '__absent__'));
        $say('refused', (string) ($result->context['refused'] ?? '__absent__'));
        $say('rows_without_image_before', $before);
        $say('rows_without_image_after', $withoutImage());
        $say('mapped', $mapped());
        $say('duplicate_mappings', $duplicateMappings());
        $say('shop_digest', $shopDigest());
        break;
    }

    // ================================================= §2 a half-made product
    //
    // SIGKILL during the n-th image operation: the row and the mapping exist,
    // the attachment exists, and `saveImages()` was never reached.
    case 'half-made': {
        $at = max(1, (int) ($args[1] ?? 1));
        $w = $startWorker(['TMC_P72_MODE' => 'kill-at', 'TMC_P72_AT' => $at]);
        if ($w === null) {
            $say('half_made', '__cannot_start__');
            break;
        }
        $say('worker_output', $reap($w, 180));
        $say('mapped', $mapped());
        $say('products_without_image', $withoutImage());
        $say('duplicate_mappings', $duplicateMappings());
        $sayJob($liveJob());
        break;
    }

    /** What the next pass makes of that half-made product. */
    case 'resume': {
        $job = $liveJob();
        if ($job !== null && $job->lockedUntil !== '' && strtotime($job->lockedUntil) > strtotime(current_time('mysql'))) {
            $wpdb->query($wpdb->prepare(
                'UPDATE ' . $P('tmc_jobs') . ' SET locked_until = %s WHERE id = %d',
                gmdate('Y-m-d H:i:s', strtotime(current_time('mysql')) - 10),
                $job->id
            ));
        }
        $beforeDone = (int) ($job?->done ?? 0);
        $beforeMissing = $withoutImage();
        $results = $c->get(JobRunner::class)->run(max(1, (int) ($args[1] ?? 1)));
        $say('resume_batches', count($results));
        $say('resume_says', $results === [] ? 'nothing_was_in_the_queue' : $results[0]['outcome']);
        $after = $liveJob();
        $say('done_before', $beforeDone);
        $say('done_after', (int) ($after?->done ?? 0));
        $say('missing_image_before', $beforeMissing);
        $say('missing_image_after', $withoutImage());
        $say('duplicate_mappings', $duplicateMappings());
        $say('the_half_made_one_was_counted_done', ($after?->done ?? 0) > $beforeDone && $withoutImage() >= $beforeMissing);
        $say('mapped', $mapped());
        $sayJob($after);
        break;
    }

    /** Batches until the import is done, or `max` — «it resumes» as a number. */
    case 'complete': {
        $max = max(1, (int) ($args[1] ?? 20));
        $runner = $c->get(JobRunner::class);
        $outcomes = [];
        for ($i = 0; $i < $max; $i++) {
            $job = $liveJob();
            if ($job !== null && $job->status->isTerminal()) {
                break;
            }
            if ($job !== null && $job->lockedUntil !== '' && strtotime($job->lockedUntil) > strtotime(current_time('mysql'))) {
                $wpdb->query($wpdb->prepare(
                    'UPDATE ' . $P('tmc_jobs') . ' SET locked_until = %s WHERE id = %d',
                    gmdate('Y-m-d H:i:s', strtotime(current_time('mysql')) - 10),
                    $job->id
                ));
            }
            $results = $runner->run(1);
            if ($results === []) {
                break;
            }
            $outcomes[] = $results[0]['outcome'];
        }
        $say('complete_batches', count($outcomes));
        $say('complete_outcomes', implode('|', $outcomes));
        $sayJob($liveJob());
        $say('mapped', $mapped());
        $say('products_without_image', $withoutImage());
        $say('duplicate_mappings', $duplicateMappings());
        break;
    }

    // The WooCommerce products themselves, as a digest — asked before and
    // after, because «ما به محصولات ووکامرس دست نزدیم» is a measurement.
    case 'untouched': {
        $say('wc_digest', $shopDigest());
        break;
    }

    case 'report': {
        $reader = $c->get(DokanReaderInterface::class);
        $say('reader_available', $reader->isAvailable());
        $say('reader_products', count($reader->products()));
        $say('mapped', $mapped());
        $say('products_without_image', $withoutImage());
        $say('duplicate_mappings', $duplicateMappings());
        $say('vendor_copies', $vendorCopies());
        $sayJob($liveJob());
        break;
    }

    case 'reset': {
        $f = $fixture();
        $rolled = '__none__';
        foreach (array_keys($service->runs()) as $runId) {
            $result = $service->rollback((string) $runId);
            $rolled = $runId . ':' . ($result->ok ? 'ok' : $result->code);
        }
        $say('rolled_back', $rolled);
        $wpdb->query('DELETE FROM ' . $P('tmc_jobs') . " WHERE job_type = '" . DokanImportJob::TYPE . "'");
        foreach ($f['products'] as $post) {
            $row = $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM ' . $P('tmc_products') . ' WHERE wc_product_id = %d',
                (int) $post
            ));
            if ($row !== null) {
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $P('tmc_product_images') . ' WHERE product_id = %d', (int) $row));
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $P('tmc_products') . ' WHERE id = %d', (int) $row));
            }
            wp_delete_post((int) $post, true);
        }
        foreach ($f['vendors'] as $user) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $P('tmc_vendor_profiles') . ' WHERE user_id = %d', (int) $user));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $P('tmc_vendor_stores') . ' WHERE user_id = %d', (int) $user));
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user((int) $user);
        }
        // ⛔ Only the copies THIS fixture made, found by the title it gave
        // them. No sweep, no age cut, and nothing of the shop's own media.
        foreach ((array) $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_title = 'tmc-p72-source'"
        ) as $cid) {
            wp_delete_attachment((int) $cid, true);
        }
        if (($f['table'] ?? false) === true) {
            $wpdb->query('DROP TABLE IF EXISTS `' . $P('dokan_orders') . '`');
        }
        delete_option($FIXTURE);
        $say('reset_products_left', (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_title LIKE 'کالای ۷۲ %'"
        ));
        $say('reset_mapped_left', $mapped());
        $say('reset_sellers_left', (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'dokan_enable_selling'"
        ));
        $say('reset_attachments_left', (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_title = 'tmc-p72-source'"
        ));
        $say('reset_jobs_left', (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . $P('tmc_jobs') . " WHERE job_type = '" . DokanImportJob::TYPE . "'"
        ));
        break;
    }

    default:
        $say('unknown_verb', $command);
}
