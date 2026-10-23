<?php
/**
 * «ادامه از همین نقطه», in its own process and on its own connection.
 *
 * The hand-over has to be a SECOND process for this round, because the thing
 * being measured is whether it can interleave with a worker's write phase.
 * Run from the probe's process it would either share the connection with
 * nobody or block the probe itself, and in both cases the answer would be
 * about the test harness rather than about the lease (`alpha.40`).
 *
 * Prints one key per line, and prints `handover_started` BEFORE the call so
 * the reader can tell «still blocked» from «never got going».
 *
 *   wp eval-file tests/Support/concurrent-job-handover.php <job-id> [override]
 */

use Tecteb\Marketplace\Contracts\JobRepositoryInterface;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;

if (!defined('DB_NAME') || (DB_NAME !== 'tmc_wp_test' && DB_NAME !== 'tmc_wp_demo')) {
    fwrite(STDERR, "refused: DB_NAME is not one of the disposable databases.\n");
    echo "refused=1\n";
    return;
}

$jobId = (int) ($args[0] ?? 0);
$override = ((string) ($args[1] ?? '1')) === '1';
if ($jobId <= 0) {
    echo "handover_refused=no_job_id\n";
    return;
}

$say = static function (string $line): void {
    echo $line . "\n";
    if (ob_get_level() > 0) {
        @ob_flush();
    }
    @flush();
};

/** @var JobRepositoryInterface $jobs */
$jobs = Bootstrap::container()->get(JobRepositoryInterface::class);

$say('handover_started=' . getmypid());
$began = microtime(true);
// The manager's explicit override: this round is not about the fifteen-second
// silence window, and leaving that guard in the way would measure the guard
// instead of the lease.
$answer = $jobs->handOver($jobId, '', $override);
$say('handover_answer=' . ($answer === '' ? 'handed_over' : $answer));
$say('handover_waited_ms=' . round((microtime(true) - $began) * 1000, 1));
$say('handover_finished=1');
