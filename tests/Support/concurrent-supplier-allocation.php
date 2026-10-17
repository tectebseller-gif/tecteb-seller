<?php
/**
 * A SECOND, INDEPENDENT WRITER against one obligation's balance.
 *
 * Its own process, its own connection, its own transaction — which is the
 * whole point: `InterferingDatabase` writes inside the caller's transaction
 * and goes away with its rollback, so it can show that a statement SEES a
 * competing row but never which of two committed transactions wins
 * (`alpha.38`: «شخص سومی که روی اتصال خودتان باشد شخص سوم نیست»).
 *
 * It records a payment and allocates it against the obligation of one order
 * line, commits, and then touches `<fifo>.done` so the caller knows the order
 * of events rather than hoping for it. No `sleep` anywhere.
 *
 *   wp eval-file tests/Support/concurrent-supplier-allocation.php <line> <amount> <fifo>
 */

use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Finance\Application\SupplierObligationRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Application\SupplierPaymentRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Finance\Domain\SupplierPayment;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbSupplierObligationRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbSupplierPaymentRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0031SupplierObligations;

if (!defined('DB_NAME') || (DB_NAME !== 'tmc_wp_test' && DB_NAME !== 'tmc_wp_demo')) {
    fwrite(STDERR, "refused: DB_NAME is not one of the disposable databases.\n");
    return;
}

$args = $args ?? [];
$line = (int) ($args[0] ?? 0);
$amount = (int) ($args[1] ?? 0);
$fifo = (string) ($args[2] ?? '');
if ($line <= 0 || $amount <= 0 || $fifo === '') {
    echo "rival=bad_arguments\n";
    return;
}

global $wpdb;
$db = new WpDatabase($wpdb);
$clock = new SystemClock();
$payments = new DbSupplierPaymentRepository($db, $clock);
$obligations = new DbSupplierObligationRepository($db, $clock);

$obligation = $obligations->forOrderItem($line);
if ($obligation === null || !$obligation->isAgreed()) {
    echo "rival=no_obligation\n";
    return;
}
$supplier = $obligation->supplierUserId;

$written = $payments->record(
    new SupplierPayment(
        0,
        $supplier,
        Money::of($amount, $obligation->currency, $obligation->exponent),
        Money::of(0, $obligation->currency, $obligation->exponent),
        '2026-10-18',
        'TRK-RIVAL-' . bin2hex(random_bytes(3)),
        '',
        '',
        0,
        'rival:' . bin2hex(random_bytes(6)),
        '',
        M0031SupplierObligations::POSTING_PARTIAL,
        ''
    ),
    [$line => null],
    [$line]
);
echo 'rival_record=' . $written['outcome'] . "\n";
if ($written['outcome'] !== SupplierPaymentRepositoryInterface::RECORDED) {
    @touch($fifo . '.done');
    return;
}
$allocated = $obligations->allocate(
    (int) $written['payment_id'],
    $obligation->id,
    $line,
    $supplier,
    $amount,
    $obligation->currency,
    $obligation->exponent
);
echo 'rival_allocate=' . $allocated['outcome'] . "\n";
echo 'rival_balance=' . (string) ($allocated['balance_minor'] ?? '') . "\n";
echo 'rival_committed=' . (
    $allocated['outcome'] === SupplierObligationRepositoryInterface::ALLOCATED ? 'yes' : 'no'
) . "\n";
// The caller waits on this file rather than on a clock.
@touch($fifo . '.done');
