<?php
/**
 * ONE batch of the queue, in its own process, so it can be killed.
 *
 * The stall this round is about is a process that stops existing in the middle
 * of a batch — a php-fpm `request_terminate_timeout`, an OOM killer, a web
 * server that gives up on a slow request. None of those leave a PHP fatal in
 * `debug.log`, and none of them can be modelled from inside the test process:
 * an exception is caught by `JobRunner` and recorded, an `exit` still runs
 * shutdown functions. Only another process that is SIGKILLed is the thing
 * itself (`alpha.40`'s rule about what a second actor is).
 *
 * So this file does exactly what one cron tick or one «اجرای دستی» does —
 * `JobRunner::run(1)` through the container the plugin itself built — and
 * nothing else. The caller decides when, and whether, it is allowed to finish.
 *
 * It prints its progress one key per line and flushes after each, so a kill
 * leaves a readable record of how far it got.
 *
 *   wp eval-file tests/Support/alpha71-worker.php [batches]
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
        'worker_batch_%d=job:%d outcome:%s done:%d failed:%d error:%s',
        $i,
        (int) $r['job'],
        (string) $r['outcome'],
        (int) $r['done'],
        (int) $r['failed'],
        $r['error'] === '' ? '__none__' : (string) $r['error']
    ));
}
$say('worker_finished=1');
