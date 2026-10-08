<?php
declare(strict_types=1);

/*
 * A second reviewer's write, in a process of its own.
 *
 * `markSeen()` has no transaction and does not need one: the guard is in the
 * same statement as the write (`INSERT … SELECT … WHERE <identity>` with
 * `ON DUPLICATE KEY UPDATE`), and one statement is atomic under InnoDB. That
 * is an ASSUMPTION ABOUT THE DATABASE, though, and an assumption about the
 * database cannot be checked on one connection — there is nothing to contend
 * for. So this script is the second connection, and `ReviewSeenConcurrencyTest`
 * schedules it.
 *
 * Nothing here sleeps. The caller launches it at a named point and waits, so
 * the statement under test always runs against work another connection has
 * already committed.
 *
 * Usage:
 *   php tests/Support/concurrent-review-seen.php mark <user-id> <product-id> <token> [when]
 *
 * Prints `wrote` or `declined`, and exits 0 either way: «declined» is a real
 * answer (the guard saw a different identity), not a failure.
 */

require dirname(__DIR__) . '/bootstrap-contract.php';

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbReviewSeenStore;
use TmcWpStubs\State;

foreach (['TMC_TEST_DB_DSN', 'TMC_TEST_DB_USER', 'TMC_TEST_DB_PASS'] as $var) {
    if (getenv($var) === false) {
        fwrite(STDERR, "concurrent-review-seen: {$var} is not set\n");
        exit(1);
    }
}
if (!str_contains((string) getenv('TMC_TEST_DB_DSN'), 'tmc_test')) {
    fwrite(STDERR, "concurrent-review-seen: refuses any DSN that is not the disposable tmc_test\n");
    exit(1);
}

$verb = (string) ($argv[1] ?? '');
$userId = (int) ($argv[2] ?? 0);
$productId = (int) ($argv[3] ?? 0);
$token = (string) ($argv[4] ?? '');
$when = (string) ($argv[5] ?? '');

if ($verb !== 'mark' || $userId <= 0 || $productId <= 0 || $token === '') {
    fwrite(STDERR, "usage: concurrent-review-seen.php mark <user-id> <product-id> <token> [when]\n");
    exit(1);
}

State::$optionsBackedByWpdb = true;
$GLOBALS['wpdb'] = new \wpdb();

try {
    $clock = $when === ''
        ? new \Tecteb\Marketplace\Core\Support\SystemClock()
        : new class ($when) implements ClockInterface {
            public function __construct(private readonly string $when)
            {
            }

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable($this->when);
            }
        };
    $store = new DbReviewSeenStore(new WpDatabase($GLOBALS['wpdb']), $clock);

    // The row BEFORE and AFTER, so «wrote» means a row really changed rather
    // than `markSeen()` having returned true for a statement the guard
    // declined — `markSeen()` answers «not a failure», which is not the same
    // question.
    $before = $store->marksFor($userId, [$productId])[$productId] ?? '';
    $store->markSeen($userId, [$productId => $token]);
    $after = $store->marksFor($userId, [$productId])[$productId] ?? '';
    echo $after === $token && $after !== $before ? 'wrote' : 'declined';
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'concurrent-review-seen: ' . $e->getMessage() . "\n");
    exit(1);
}
