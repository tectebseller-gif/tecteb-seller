<?php
/**
 * ONE batch of the queue, in its own process, stoppable INSIDE a product.
 *
 * `alpha71-worker.php` could be killed, but only from outside and only at a
 * wall-clock moment — which measures «somewhere in the page» and not «between
 * the row and its image». The three findings this round is about all live in
 * that gap, so the stop has to be placed by the code, at a named point:
 *
 *   TMC_P72_MODE=kill-at  TMC_P72_AT=n
 *     SIGKILL this very process during the n-th image operation. A real
 *     signal to its own pid: no shutdown function, no fatal in any log, the
 *     product row already written and `saveImages()` not yet reached.
 *
 *   TMC_P72_MODE=hold-at  TMC_P72_AT=n  TMC_P72_FIFO=/path
 *     Stop in the same place and WAIT for a line on the FIFO. No `sleep`
 *     anywhere: the other side decides when this one carries on, which is
 *     what makes a two-worker measurement repeatable instead of a coin toss
 *     (`alpha.54`).
 *
 * The point is placed on WordPress's own filters, not on this plugin's code —
 * `intermediate_image_sizes_advanced` fires while the metadata is being built
 * and `wp_generate_attachment_metadata` just after, so whichever the host
 * reaches first is the same moment in the import: the attachment exists, the
 * marketplace row and its mapping exist, and nothing has been attached to the
 * product yet.
 *
 *   wp eval-file tests/Support/alpha72-worker.php [batches]
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Jobs\JobRunner;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;

if (!defined('DB_NAME') || (DB_NAME !== 'tmc_wp_test' && DB_NAME !== 'tmc_wp_demo')) {
    fwrite(STDERR, "refused: DB_NAME is not one of the disposable databases.\n");
    echo "refused=1\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local.\n");
    echo "refused=1\n";
    return;
}

$say = static function (string $line): void {
    echo $line . "\n";
    if (ob_get_level() > 0) {
        @ob_flush();
    }
    @flush();
};

$mode = (string) getenv('TMC_P72_MODE');
$at = max(1, (int) (getenv('TMC_P72_AT') ?: 1));
$fifo = (string) getenv('TMC_P72_FIFO');
$seen = 0;
$done = false;
/** @var array<int,true> $counted */
$counted = [];

// Counted PER PRODUCT, not per hook call.
//
// ⛔ The first version counted invocations, and one product's image work trips
// both filters — so `TMC_P72_AT=2` stopped inside product ONE and two
// measurements that were meant to differ printed the same numbers. Each
// imported product copies exactly one image, so the attachment id is the unit,
// and an id already counted never counts twice.
$stop = static function (int $attachment) use (&$seen, &$done, &$counted, $at, $mode, $fifo, $say): void {
    if ($done) {
        return;
    }
    if ($attachment > 0) {
        if (isset($counted[$attachment])) {
            return;
        }
        $counted[$attachment] = true;
    }
    $seen++;
    if ($seen !== $at) {
        return;
    }
    $done = true;
    if ($mode === 'kill-at') {
        $say('worker_killing_itself_at=' . $seen);
        // Its own pid, signal 9. The process stops existing here: this is the
        // thing a host does, and the reason nothing reaches `debug.log`.
        @posix_kill(getmypid(), 9);
        // Unreachable; only here so a build without pcntl says so instead of
        // quietly carrying on and reporting a pass.
        $say('worker_could_not_signal_itself=1');
        exit(1);
    }
    if ($mode === 'hold-at' && $fifo !== '') {
        $say('worker_holding_at=' . $seen);
        $handle = @fopen($fifo, 'r');
        if ($handle === false) {
            $say('worker_fifo_unreadable=1');
            return;
        }
        // A BLOCKING read, so the pause lasts exactly as long as the other
        // side wants it to.
        @fgets($handle);
        @fclose($handle);
        $say('worker_released_at=' . $seen);
    }
};

if ($mode !== '') {
    add_filter('intermediate_image_sizes_advanced', static function (array $sizes, $meta = [], $attachment = 0) use ($stop): array {
        $stop((int) $attachment);
        return $sizes;
    }, 1, 3);
    add_filter('wp_generate_attachment_metadata', static function (array $data, $attachment = 0) use ($stop): array {
        $stop((int) $attachment);
        return $data;
    }, 1, 2);
}

$batches = max(1, (int) ($args[0] ?? 1));
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

$say('worker_started=' . getmypid());
$results = $c->get(JobRunner::class)->run($batches);
$say('worker_batches=' . count($results));
foreach ($results as $i => $r) {
    $say(sprintf(
        'worker_batch_%d=job:%d outcome:%s done:%d failed:%d checkpoints:%s error:%s',
        $i,
        (int) $r['job'],
        (string) $r['outcome'],
        (int) $r['done'],
        (int) $r['failed'],
        (string) ($r['checkpoints'] ?? '__absent__'),
        $r['error'] === '' ? '__none__' : (string) $r['error']
    ));
}
$say('worker_image_points_reached=' . $seen);
$say('worker_finished=1');
