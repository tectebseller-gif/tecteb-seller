<?php
declare(strict_types=1);

/*
 * A second withdrawal writer, in a process of its own.
 *
 * ### Why a process and not a fork
 *
 * The same reason `concurrent-shop-write.php` gives: a fork inherits the
 * parent's socket, and `disconnect()`-before-fork ends the parent's
 * transaction and its read view — so a test written to prove that a stale
 * view causes a bug proves nothing instead.
 *
 * ### Why not `InterferingDatabase` for these two
 *
 * `InterferingDatabase` schedules a third party's write between two of YOUR
 * statements, on YOUR connection. That is the right tool for «the row moved
 * between the SELECT and the UPDATE», and it is used for exactly that. It
 * cannot answer «do two independent connections both get past this guard»,
 * because there is only one connection and therefore no lock to contend for.
 * This script is the second connection, so the guard is measured where it
 * actually lives: the unique index and the `WHERE` clause, under InnoDB.
 *
 * It is scheduled, not raced for: the caller launches it at a named point and
 * waits for it to finish. Nothing here sleeps, and nothing here depends on
 * which process the kernel runs first — «یک اجرای زمان‌بندی‌شدهٔ تک‌پروسه را
 * رقابت واقعی معرفی نکن» cuts the other way too, so what this gives is a
 * second connection whose work is COMMITTED before the statement under test
 * runs.
 *
 * Usage (from a database test):
 *   php tests/Support/concurrent-withdrawal.php reserve <vendor-id> <amount-minor> <item-id,...>
 *   php tests/Support/concurrent-withdrawal.php move <withdrawal-id> <to-status> <expected-status>
 *   php tests/Support/concurrent-withdrawal.php pay <withdrawal-id> <reference>
 *
 * `reserve` prints the new withdrawal id, or `0` when it got nothing.
 * `move` prints `moved` or `refused`.
 * `pay` walks the request to Paid through the REAL service — review, approve,
 * start payment, record — and prints `paid` or the refusal code. It exists
 * because `move` writes a status and a payment is a status AND a ledger
 * document in one transaction; a test about a stale cancel meeting a PAID
 * request needs the real pair, on a connection of its own.
 */

require dirname(__DIR__) . '/bootstrap-contract.php';

use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpAuditRepository;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Finance\Application\ReviewWithdrawals;
use Tecteb\Marketplace\Modules\Finance\Domain\WithdrawalStateMachine;
use Tecteb\Marketplace\Modules\Finance\Domain\WithdrawalStatus;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbLedgerRepository;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbWithdrawalRepository;
use TmcWpStubs\State;

foreach (['TMC_TEST_DB_DSN', 'TMC_TEST_DB_USER', 'TMC_TEST_DB_PASS'] as $var) {
    if (getenv($var) === false) {
        fwrite(STDERR, "concurrent-withdrawal: {$var} is not set\n");
        exit(1);
    }
}
if (!str_contains((string) getenv('TMC_TEST_DB_DSN'), 'tmc_test')) {
    fwrite(STDERR, "concurrent-withdrawal: refuses any DSN that is not the disposable tmc_test\n");
    exit(1);
}

$verb = (string) ($argv[1] ?? '');

State::$optionsBackedByWpdb = true;
$GLOBALS['wpdb'] = new \wpdb();

try {
    $withdrawals = new DbWithdrawalRepository(new WpDatabase($GLOBALS['wpdb']), new SystemClock());

    if ($verb === 'reserve') {
        $vendorId = (int) ($argv[2] ?? 0);
        $amount = (int) ($argv[3] ?? 0);
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($argv[4] ?? '')))));
        if ($vendorId <= 0 || $amount <= 0 || $ids === []) {
            fwrite(STDERR, "usage: concurrent-withdrawal.php reserve <vendor-id> <amount-minor> <item-id,...>\n");
            exit(1);
        }
        echo $withdrawals->reserve($vendorId, $ids, $amount, 'IR000000000000000000000000', 'دارندهٔ حساب');
        exit(0);
    }

    if ($verb === 'pay') {
        $withdrawalId = (int) ($argv[2] ?? 0);
        $reference = (string) ($argv[3] ?? '');
        if ($withdrawalId <= 0 || $reference === '') {
            fwrite(STDERR, "usage: concurrent-withdrawal.php pay <withdrawal-id> <reference>\n");
            exit(1);
        }
        $db = new WpDatabase($GLOBALS['wpdb']);
        $clock = new SystemClock();
        $review = new ReviewWithdrawals(
            $withdrawals,
            new DbLedgerRepository($db, $clock),
            new WithdrawalStateMachine(),
            new AuditLogger(new WpAuditRepository($GLOBALS['wpdb']), new AuditEventSanitizer(), $clock),
            new FakeCapabilityChecker(9, [Capabilities::REVIEW_WITHDRAWALS]),
            $db
        );
        foreach (['startReview', 'approve', 'startPayment'] as $step) {
            $result = $review->{$step}($withdrawalId);
            if (!$result->ok && $result->code !== 'invalid_transition') {
                echo $result->code;
                exit(0);
            }
        }
        $paid = $review->recordPayment($withdrawalId, $reference);
        echo $paid->ok ? 'paid' : $paid->code;
        exit(0);
    }

    if ($verb === 'move') {
        $withdrawalId = (int) ($argv[2] ?? 0);
        $to = WithdrawalStatus::tryFrom((string) ($argv[3] ?? ''));
        $expected = WithdrawalStatus::tryFrom((string) ($argv[4] ?? ''));
        if ($withdrawalId <= 0 || $to === null || $expected === null) {
            fwrite(STDERR, "usage: concurrent-withdrawal.php move <withdrawal-id> <to-status> <expected-status>\n");
            exit(1);
        }
        echo $withdrawals->updateStatus($withdrawalId, $to, null, '', '', $expected) ? 'moved' : 'refused';
        exit(0);
    }

    fwrite(STDERR, "concurrent-withdrawal: unknown verb\n");
    exit(1);
} catch (\Throwable $e) {
    fwrite(STDERR, 'concurrent-withdrawal: ' . $e->getMessage() . "\n");
    exit(1);
}
