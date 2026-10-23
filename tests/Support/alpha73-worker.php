<?php
/**
 * ONE batch of the queue, in its own process, stoppable at a NAMED WRITE.
 *
 * `alpha72-worker.php` stops inside the image work — which is the right place
 * for «the copy is the slow half» and the wrong place for this round. The gap
 * the owner read out of the source is narrower than that: it is between the
 * moment this worker's ownership was last validated and the moment its
 * dependent write lands.
 *
 *   TMC_P73_STOP=before-attach
 *     Stop immediately before the FIRST statement of the image attach — the
 *     `DELETE FROM …tmc_product_images` that opens `saveImages()`. On
 *     `alpha.73` that is after `stillOurs()` has answered yes and before
 *     anything has been written: the gap itself.
 *
 *   TMC_P73_STOP=before-create
 *     Stop immediately before `INSERT INTO …tmc_products`, which is the other
 *     window named in the report: between `claim(unit)` and the row.
 *
 * ⛔ The point is placed on `$wpdb`'s own `query` filter rather than on this
 * plugin's code, for the same reason `alpha72-worker.php` used WordPress's
 * image filters: the measurement has to be possible on the BASELINE's bytes,
 * and the baseline is already installed. A park placed by editing `src/` would
 * measure a tree nobody delivered.
 *
 * `TMC_P73_FIFO` is a blocking read, so the other side decides when this one
 * carries on — no `sleep` anywhere (`alpha.54`).
 *
 * It also times the write phase: the wall clock between the lease's locking
 * read and the commit that follows it. That is the only number that says
 * whether the heavy image work stayed outside, so it is measured rather than
 * asserted — and on a tree with no such read it prints `__absent__` instead of
 * a zero that would read like a measurement.
 *
 *   wp eval-file tests/Support/alpha73-worker.php [batches]
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

$stopAt = (string) getenv('TMC_P73_STOP');
$fifo = (string) getenv('TMC_P73_FIFO');
$parked = false;

// The write phase, timed. `heldFrom` is set by the locking read and cleared by
// the COMMIT that ends the same unit of work.
$heldFrom = null;
$heldMs = [];

$needle = static function (string $stop): string {
    return match ($stop) {
        // `tmc_product_images` and nothing else: the table name is matched to
        // its closing backtick so `tmc_products` cannot answer for it.
        'before-attach' => '~DELETE\s+FROM\s+`[^`]*tmc_product_images`~i',
        'before-create' => '~INSERT\s+INTO\s+`[^`]*tmc_products`\s*\(~i',
        default => '',
    };
};

add_filter('query', static function ($sql) use (
    &$parked,
    &$heldFrom,
    &$heldMs,
    $stopAt,
    $fifo,
    $say,
    $needle
) {
    $text = (string) $sql;

    // The lease's locking read, and the commit that closes it.
    if (preg_match('~SELECT[^;]*`[^`]*tmc_jobs`[^;]*FOR\s+UPDATE~is', $text)) {
        $heldFrom = microtime(true);
    } elseif ($heldFrom !== null && preg_match('~^\s*(COMMIT|ROLLBACK)\s*$~i', $text)) {
        $heldMs[] = round((microtime(true) - $heldFrom) * 1000, 2);
        $heldFrom = null;
    }

    if ($parked || $stopAt === '' || $fifo === '') {
        return $sql;
    }
    $pattern = $needle($stopAt);
    if ($pattern === '' || !preg_match($pattern, $text)) {
        return $sql;
    }
    $parked = true;
    // Printed BEFORE the blocking read, because the other side waits for this
    // line to know the worker is in position.
    $say('worker_parked_before=' . $stopAt);
    $handle = @fopen($fifo, 'r');
    if ($handle === false) {
        $say('worker_fifo_unreadable=1');
        return $sql;
    }
    @fgets($handle);
    @fclose($handle);
    $say('worker_released=1');
    return $sql;
}, 1, 1);

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
$say('worker_parked=' . ($parked ? 'yes' : 'no'));
$say('worker_write_phase_ms=' . ($heldMs === [] ? '__absent__' : implode(',', $heldMs)));
$say('worker_finished=1');
