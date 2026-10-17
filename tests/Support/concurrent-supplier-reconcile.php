<?php
/**
 * A SECOND REQUEST that reconciles one supplier payment, in its own process
 * and on its own connection.
 *
 * `RecordSupplierPayment::void()` reads the payment BEFORE `begin()` and
 * decides from that snapshot whether a compensating entry is owed. The
 * question «what if the payment gained its money line in that gap» cannot be
 * asked from inside the voiding process: `InterferingDatabase` writes on the
 * connection under test, so once that code is in a transaction the write
 * joins it and a rollback takes it away — the `alpha.40` rule. This is the
 * other half, and it is the shape the two admin buttons really have:
 * `SupplierPaymentsPage` posts `void` and `reconcile` from the same screen,
 * so two requests are two requests.
 *
 * Two modes, because the owner asked for BOTH orderings and the second one
 * is the mirror: a void committing inside a `reconcile()`'s gap is the same
 * hazard pointing the other way. No `sleep` and no FIFO — the caller runs
 * this synchronously and waits for it to exit, so the interleaving is
 * scheduled rather than raced for.
 *
 * Prints ONE key per line (`alpha.41`).
 *
 *   wp eval-file tests/Support/concurrent-supplier-reconcile.php <payment_id> [reconcile|void]
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Finance\Application\RecordSupplierPayment;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbSupplierPaymentRepository;

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

$say = static function (string $key, mixed $value): void {
    echo $key . '=' . str_replace(["\n", ' '], ['', '_'], (string) ($value === '' ? '__none__' : $value)) . "\n";
};
$say('actor', 'ran');

$paymentId = (int) ($args[0] ?? 0);
if ($paymentId <= 0) {
    $say('refused', 'no_payment');
    return;
}

$c = Bootstrap::container();
$adminId = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
wp_set_current_user($adminId);
$c->bind(CapabilityCheckerInterface::class, static fn () => new class implements CapabilityCheckerInterface {
    public function can(string $capability): bool
    {
        return in_array($capability, Capabilities::all(), true);
    }

    public function currentUserId(): ?int
    {
        return (int) get_current_user_id();
    }
});

$mode = (string) ($args[1] ?? 'reconcile');
$service = $c->get(RecordSupplierPayment::class);
if ($mode === 'void') {
    $result = $service->void($paymentId, 'ابطال از درخواست دیگر');
    $say('void_code', (string) $result->code);
    $say('void_compensating', (string) ($result->context['compensating_event'] ?? ''));
} else {
    $result = $service->reconcile($paymentId);
    $say('reconcile_code', (string) $result->code);
    $say('reconcile_event', (string) ($result->context['ledger_event'] ?? ''));
    $say('reconcile_posting', (string) ($result->context['posting'] ?? ''));
}

global $wpdb;
$row = (new DbSupplierPaymentRepository(new WpDatabase($wpdb), new SystemClock()))->find($paymentId);
$say('row_ledger_event', (string) ($row?->ledgerEvent ?? ''));
$say('row_reconciled', $row !== null && $row->isReconciled() ? 'yes' : 'no');
$say('row_void', $row !== null && $row->isVoid() ? 'yes' : 'no');
