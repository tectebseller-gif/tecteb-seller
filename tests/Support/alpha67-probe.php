<?php

/**
 * MEASUREMENTS FOR THE `alpha.68` ROUND — loadable on BOTH trees.
 *
 * Run with `wp eval-file` against the DISPOSABLE install, on `alpha.67`'s
 * bytes first and on this checkout's second, so every sentence below is a
 * before/after pair rather than a reading of the new code.
 *
 * Everything this round adds is asked for with `method_exists()` /
 * `class_exists()` / `defined()` before it is used, because a probe that
 * cannot LOAD on the baseline reports nothing about it (`alpha.32`'s trap).
 *
 * Verbs:
 *   §1  unit-arrange · unit-healthy · unit-read-failure · unit-state
 *       unit-absent · unit-write-refusal
 *   §2  books-sum · books-two-currencies
 *   §3  split-arrange · split-partial · split-full · split-unit-price
 *       trial-on · trial-off
 *   §4  rollback-hazard
 *   report · reset
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Finance\Application\VendorBalance;
use Tecteb\Marketplace\Modules\Order\Domain\ReturnStatus;
use Tecteb\Marketplace\Modules\Order\Infrastructure\DbOrderItemRepository;

// --- refuses to run anywhere but a disposable install ---------------------
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

/** This round's own namespace, so nothing collides with `alpha66-probe.php`. */
const TMC_P67 = 'tmc-p67';
const TMC_P67_ORDER = 990067;           // the §1/§2 fixture's order id
const TMC_P67_SPLIT_ORDER = 990167;     // the §3 fixture's order id

$args = $args ?? [];
$verb = (string) ($args[0] ?? 'report');
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

global $wpdb;
$db = new WpDatabase($wpdb);
$say = static function (string $key, mixed $value): void {
    if (is_bool($value)) {
        $value = $value ? 'yes' : 'no';
    }
    echo $key . '=' . ($value === '' || $value === null ? '__none__' : (string) $value) . "\n";
};

$items = $db->prefix() . 'tmc_order_items';
$ledger = $db->prefix() . 'tmc_ledger_entries';
$units = $db->prefix() . 'tmc_vendor_money_unit';
$returnsTable = $db->prefix() . 'tmc_returns';

/** An EXISTING approved, sellable shop — borrowed, so `reset` owes nothing. */
$vendorId = static function () use ($db, $say): int {
    $id = (int) ($db->getVar(
        'SELECT v.user_id FROM `' . $db->prefix() . 'tmc_vendor_profiles` v
          INNER JOIN `' . $db->prefix() . 'users` u ON u.ID = v.user_id
          INNER JOIN `' . $db->prefix() . 'tmc_vendor_applications` a
                  ON a.user_id = v.user_id AND a.status = %s
          WHERE v.can_sell = 1 ORDER BY v.id DESC LIMIT 1',
        ['approved']
    ) ?? 0);
    if ($id === 0) {
        $say('fixture', '__no_sellable_shop__');
    }
    return $id;
};

/**
 * A REAL DATABASE WITH ONE READ MADE TO FAIL, by name.
 *
 * `information_schema.COLUMNS` is the read `M0033::carriedBy()` asks, and
 * the owner's §1 is about what happens when it does not answer. Every other
 * statement goes to the real MariaDB on the same connection, so the rows
 * this measures are the rows that are actually there.
 */
final class Alpha67BlindedDatabase implements DatabaseInterface
{
    public int $blinded = 0;

    /** @param list<string> $needles */
    public function __construct(
        private readonly DatabaseInterface $inner,
        private readonly array $needles = ['information_schema.COLUMNS']
    ) {
    }

    private function matches(string $sql): bool
    {
        foreach ($this->needles as $needle) {
            if (!str_contains($sql, $needle)) {
                return false;
            }
        }
        return true;
    }

    public function prefix(): string
    {
        return $this->inner->prefix();
    }

    public function charsetCollate(): string
    {
        return $this->inner->charsetCollate();
    }

    public function execute(string $sql, array $params = []): ?int
    {
        return $this->inner->execute($sql, $params);
    }

    public function getVar(string $sql, array $params = []): mixed
    {
        if ($this->matches($sql)) {
            $this->blinded++;
            return null;        // what a failed read answers
        }
        return $this->inner->getVar($sql, $params);
    }

    public function getRow(string $sql, array $params = []): ?array
    {
        if ($this->matches($sql)) {
            $this->blinded++;
            return null;
        }
        return $this->inner->getRow($sql, $params);
    }

    public function getResults(string $sql, array $params = []): array
    {
        if ($this->matches($sql)) {
            $this->blinded++;
            return [];
        }
        return $this->inner->getResults($sql, $params);
    }

    public function lastError(): string
    {
        return $this->inner->lastError();
    }

    // The transaction half is passed straight through: this decorator blinds
    // ONE read and must not be a second gateway on the same connection, or
    // the nested-transaction contract would count in the wrong object
    // (`alpha.56`).
    public function begin(): bool
    {
        return $this->inner->begin();
    }

    public function commit(): bool
    {
        return $this->inner->commit();
    }

    public function rollback(): bool
    {
        return $this->inner->rollback();
    }

    public function inTransaction(): bool
    {
        return $this->inner->inTransaction();
    }
}

/**
 * WHAT ONE NAMED ROW LOOKS LIKE THROUGH `settlementView()`, which is the
 * read `VendorBalance` takes every figure from. Reported per row because the
 * borrowed shop carries other lines and a claim about the total would be a
 * claim about somebody else's data (`alpha.66`).
 */
$viewRow = static function (DatabaseInterface $gateway, int $vendor, int $orderItemId) use ($db, $items): array {
    $id = (int) ($db->getVar('SELECT id FROM `' . $items . '` WHERE wc_order_item_id = %d', [$orderItemId]) ?? 0);
    $repo = new DbOrderItemRepository($gateway, new SystemClock());
    foreach ($repo->settlementView($vendor) as $line) {
        if ((int) $line['id'] === $id) {
            return $line;
        }
    }
    return [];
};

/** A `VendorBalance` over any gateway, with this build's own dependencies. */
$balanceOver = static function (DatabaseInterface $gateway) use ($c): VendorBalance {
    $repo = new DbOrderItemRepository($gateway, new SystemClock());
    $settings = $c->get(\Tecteb\Marketplace\Core\Config\SettingsService::class);
    $returns = $c->has(\Tecteb\Marketplace\Modules\Order\Application\ShipmentRepositoryInterface::class)
        ? $c->get(\Tecteb\Marketplace\Modules\Order\Application\ShipmentRepositoryInterface::class)
        : null;
    $paymentsClass = \Tecteb\Marketplace\Modules\Finance\Application\SupplierPaymentRepositoryInterface::class;
    $payments = $c->has($paymentsClass) ? $c->get($paymentsClass) : null;
    $registryClass = \Tecteb\Marketplace\Modules\Finance\Application\VendorMoneyUnitRegistryInterface::class;
    $registry = $c->has($registryClass) ? $c->get($registryClass) : null;
    return new VendorBalance($repo, $settings, new SystemClock(), $returns, $payments, $registry);
};

// =========================================================================
// §1 — ABSENT, PRESENT AND READ-FAILED ARE THREE ANSWERS
// =========================================================================

/**
 * ONE SHOP WITH TWO EXPONENTS IN IT, which is the state migration 33 exists
 * for and the state the owner's §1 is about: a vendor whose registry row and
 * whose historical lines are at exponent 0, and one line written by this
 * release at exponent 4.
 *
 * Both rows are this fixture's own — `reset` takes them back — and the
 * borrowed shop's own rows are left alone, so every claim below is about a
 * DELTA (`alpha.66`'s rule, paid for once already).
 */
if ($verb === 'unit-arrange') {
    $vendor = $vendorId();
    if ($vendor === 0) {
        return;
    }
    $now = gmdate('Y-m-d H:i:s');
    // THE REGISTRY: the vendor's FIRST recorded unit, which is what a row
    // that does not carry its own is read at. Seeded only when absent —
    // moving an existing one would reinterpret somebody else's rows.
    $stored = $db->getRow('SELECT currency, exponent FROM `' . $units . '` WHERE vendor_user_id = %d', [$vendor]);
    if ($stored === null) {
        $db->execute(
            'INSERT INTO `' . $units . '` (vendor_user_id, currency, exponent, source_event, created_at)
             VALUES (%d, %s, %d, %s, %s)',
            [$vendor, 'IRT', 0, 'tmc-p67-arrange', $now]
        );
        $stored = ['currency' => 'IRT', 'exponent' => 0];
    }
    $say('registry_currency', (string) $stored['currency']);
    $say('registry_exponent', (int) $stored['exponent']);

    $insert = static function (
        int $orderItemId,
        string $sku,
        int $base,
        ?int $commission,
        ?int $share,
        ?string $currency,
        ?int $exponent
    ) use ($db, $items, $vendor, $now, $say): void {
        $db->execute('DELETE FROM `' . $items . '` WHERE wc_order_item_id = %d', [$orderItemId]);
        $carried = $db->getVar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            [$items, 'tmc_exponent']
        );
        $hasColumns = $carried !== null && (int) $carried === 1;
        $cols = $hasColumns ? ', `tmc_currency`, `tmc_exponent`' : '';
        $vals = $hasColumns
            ? ', ' . ($currency === null ? 'NULL' : "'" . $currency . "'")
              . ', ' . ($exponent === null ? 'NULL' : (string) $exponent)
            : '';
        $db->execute(
            'INSERT INTO `' . $items . '`
             (wc_order_id, wc_order_item_id, wc_product_id, product_id, variation_id, vendor_user_id,
              title, sku, quantity, unit_price_minor, base_minor, tax_minor,
              commission_minor, vendor_share_minor, rate_bp, rate_source, ledger_event, status'
             . $cols . ', settlement_completed_at, created_at, updated_at)
             VALUES (%d, %d, 0, 0, NULL, %d, %s, %s, 1, %d, %d, 0, '
             . ($commission === null ? 'NULL' : '%d') . ', '
             . ($share === null ? 'NULL' : '%d') . ', 1250, %s, %s, %s'
             . $vals . ', NULL, %s, %s)',
            array_values(array_filter([
                TMC_P67_ORDER,
                $orderItemId,
                $vendor,
                'قلم آزمون واحد ' . $sku,
                $sku,
                $base,
                $base,
                $commission,
                $share,
                'product',
                'tmc-p67-' . $sku,
                'placed',
                $now,
                $now,
            ], static fn ($v): bool => $v !== null))
        );
        $say('row_' . $sku, (int) ($db->getVar(
            'SELECT id FROM `' . $items . '` WHERE wc_order_item_id = %d',
            [$orderItemId]
        ) ?? 0));
        $say('row_' . $sku . '_exponent', $hasColumns ? (string) ($db->getVar(
            'SELECT `tmc_exponent` FROM `' . $items . '` WHERE wc_order_item_id = %d',
            [$orderItemId]
        ) ?? '__null__') : '__column_absent__');
    };

    // ۱۰۰ تومان with NO unit — exactly what every release up to `alpha.66`
    // wrote, and the truth about every row that exists today.
    $insert(TMC_P67_ORDER + 1, 'legacy', 100, 0, 100, null, null);
    // ۸۸٫۳۷۵ تومان at exponent 4 — ۱۰۱ at ۱۲٫۵٪, the owner's own example.
    $insert(TMC_P67_ORDER + 2, 'exact', 1010000, 126250, 883750, 'IRT', 4);

    // THE LEDGER, in the same two units, so §2's control has its rows.
    foreach ([
        ['tmc-p67-books-e0', 100, 0],
        ['tmc-p67-books-e4', 883750, 4],
    ] as [$event, $amount, $exponent]) {
        $db->execute('DELETE FROM `' . $ledger . '` WHERE event_key = %s', [$event]);
        foreach ([['vendor_earning', $amount], ['central_payment', -$amount]] as [$account, $signed]) {
            $db->execute(
                'INSERT INTO `' . $ledger . '`
                 (event_key, vendor_user_id, account, amount_minor, currency, exponent,
                  order_ref, item_ref, reason, reverses_entry_id, snapshot, created_at)
                 VALUES (%s, %d, %s, %d, %s, %d, %s, %s, %s, NULL, %s, %s)',
                [$event, $vendor, $account, $signed, 'IRT', $exponent, 'tmc-p67', '', 'probe', '{}', $now]
            );
        }
    }
    $say('ledger_exponents', implode(',', array_map(
        static fn (array $r): string => (string) $r['exponent'],
        $db->getResults(
            'SELECT DISTINCT exponent FROM `' . $ledger . '`
              WHERE vendor_user_id = %d ORDER BY exponent',
            [$vendor]
        )
    )));
    $say('arranged', 'yes');
    return;
}

/** A reading of the two rows with a WORKING `information_schema`. */
if ($verb === 'unit-healthy') {
    $vendor = $vendorId();
    $repo = new DbOrderItemRepository($db, new SystemClock());
    $totals = $repo->totalsForVendor($vendor);
    $say('totals_exponent', array_key_exists('exponent', $totals)
        ? ($totals['exponent'] === null ? '__null__' : (string) $totals['exponent'])
        : '__no_key__');
    $say('totals_share', (string) ($totals['share'] ?? '__no_key__'));
    $say('totals_unreadable', array_key_exists('unit_unreadable', $totals)
        ? ($totals['unit_unreadable'] ? 'yes' : 'no')
        : '__no_key__');
    $balance = $balanceOver($db)->of($vendor);
    $say('balance_earned', (string) ($balance['earned'] ?? '__no_key__'));
    $say('balance_exponent', array_key_exists('exponent', $balance)
        ? ($balance['exponent'] === null ? '__null__' : (string) $balance['exponent'])
        : '__no_key__');
    $say('balance_unreadable', array_key_exists('unit_unreadable', $balance)
        ? ($balance['unit_unreadable'] ? 'yes' : 'no')
        : '__no_key__');
    // The two fixture rows' own contribution, so the borrowed shop's other
    // lines cannot make this claim about somebody else's data.
    $say('fixture_share_at_e4', (string) (100 * 10000 + 883750));
    foreach (['legacy' => TMC_P67_ORDER + 1, 'exact' => TMC_P67_ORDER + 2] as $name => $orderItemId) {
        $line = $viewRow($db, $vendor, $orderItemId);
        $say('view_' . $name . '_exponent', $line === []
            ? '__not_in_view__'
            : (($line['exponent'] ?? null) === null ? '__null__' : (string) $line['exponent']));
        $say('view_' . $name . '_share', $line === [] ? '__not_in_view__' : (string) ($line['vendor_share_minor'] ?? '__null__'));
        $say('view_' . $name . '_unreadable', $line === []
            ? '__not_in_view__'
            : (array_key_exists('unit_unreadable', $line)
                ? ($line['unit_unreadable'] ? 'yes' : 'no')
                : '__no_key__'));
    }
    return;
}

/**
 * THE SAME READING WITH THAT ONE READ BLINDED.
 *
 * On `alpha.67` `carriedBy()` answers `false`, the reader substitutes
 * `NULL AS tmc_exponent` for columns that ARE there and DO carry exponent 4,
 * and the exponent-4 row is then read at the registry's exponent 0 — ten
 * thousand times too much. The ratio is printed rather than described.
 */
if ($verb === 'unit-read-failure') {
    $vendor = $vendorId();
    $healthy = $balanceOver($db)->of($vendor);
    $blind = new Alpha67BlindedDatabase($db);
    $repo = new DbOrderItemRepository($blind, new SystemClock());
    $totals = $repo->totalsForVendor($vendor);
    $say('blinded_reads', $blind->blinded);
    $say('totals_exponent', array_key_exists('exponent', $totals)
        ? ($totals['exponent'] === null ? '__null__' : (string) $totals['exponent'])
        : '__no_key__');
    $say('totals_share', (string) ($totals['share'] ?? '__no_key__'));
    $say('totals_unreadable', array_key_exists('unit_unreadable', $totals)
        ? ($totals['unit_unreadable'] ? 'yes' : 'no')
        : '__no_key__');
    $blind2 = new Alpha67BlindedDatabase($db);
    $balance = $balanceOver($blind2)->of($vendor);
    $say('balance_earned', (string) ($balance['earned'] ?? '__no_key__'));
    $say('balance_exponent', array_key_exists('exponent', $balance)
        ? ($balance['exponent'] === null ? '__null__' : (string) $balance['exponent'])
        : '__no_key__');
    $say('balance_unreadable', array_key_exists('unit_unreadable', $balance)
        ? ($balance['unit_unreadable'] ? 'yes' : 'no')
        : '__no_key__');
    $healthyEarned = (int) ($healthy['earned'] ?? 0);
    $blindEarned = (int) ($balance['earned'] ?? 0);
    $say('healthy_earned', (string) $healthyEarned);
    $say('earned_inflated_by', $healthyEarned === 0
        ? '__cannot_divide__'
        : (string) intdiv($blindEarned - $healthyEarned, max(1, $healthyEarned)) . 'x+');
    $say('earned_differs', (bool) ($blindEarned !== $healthyEarned));
    $say('exact_share_lost', (string) ($blindEarned - $healthyEarned));
    // THE ONE ROW THIS FIXTURE OWNS, under each reading. ۸۸٫۳۷۵ تومان is
    // `883750` at exponent 4; read at the registry's exponent 0 it becomes
    // `8837500000` at exponent 4 — ۸۸۳٬۷۵۰ تومان, ten thousand times too
    // much, which is the inflation the owner's §1 names.
    foreach (['legacy' => TMC_P67_ORDER + 1, 'exact' => TMC_P67_ORDER + 2] as $name => $orderItemId) {
        $line = $viewRow(new Alpha67BlindedDatabase($db), $vendor, $orderItemId);
        $say('view_' . $name . '_exponent', $line === []
            ? '__not_in_view__'
            : (($line['exponent'] ?? null) === null ? '__null__' : (string) $line['exponent']));
        $say('view_' . $name . '_unreadable', $line === []
            ? '__not_in_view__'
            : (array_key_exists('unit_unreadable', $line)
                ? ($line['unit_unreadable'] ? 'yes' : 'no')
                : '__no_key__'));
    }
    $registry = $db->getRow('SELECT exponent FROM `' . $units . '` WHERE vendor_user_id = %d', [$vendor]);
    $registryExponent = $registry === null ? 0 : (int) $registry['exponent'];
    $balanceExponent = array_key_exists('exponent', $balance) && $balance['exponent'] !== null
        ? (int) $balance['exponent']
        : 0;
    $say('exact_row_read_at', (string) $registryExponent);
    $say('exact_row_stated_at', '4');
    $say('exact_row_factor_applied', (string) (10 ** max(0, $balanceExponent - $registryExponent)));
    return;
}

/** What the guard itself answers in each of the three situations. */
if ($verb === 'unit-state') {
    $m = \Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0033MoneyUnitOnRows::class;
    $say('has_carried_by', (bool) method_exists($m, 'carriedBy'));
    $say('has_three_states', (bool) method_exists($m, 'unitColumnState'));
    if (method_exists($m, 'carriedBy')) {
        $say('carried_present', $m::carriedBy($db, $items) ? 'yes' : 'no');
        $say('carried_blinded', $m::carriedBy(new Alpha67BlindedDatabase($db), $items) ? 'yes' : 'no');
        $say('carried_absent_table', $m::carriedBy($db, $items . '_no_such_table') ? 'yes' : 'no');
    }
    if (method_exists($m, 'unitColumnState')) {
        $say('state_present', (string) $m::unitColumnState($db, $items));
        $say('state_blinded', (string) $m::unitColumnState(new Alpha67BlindedDatabase($db), $items));
        $say('state_absent_table', (string) $m::unitColumnState($db, $items . '_no_such_table'));
    }
    return;
}

/** A WRITE while the read cannot answer — refused on both trees. */
if ($verb === 'unit-write-refusal') {
    $vendor = $vendorId();
    $blind = new Alpha67BlindedDatabase($db);
    $repo = new DbOrderItemRepository($blind, new SystemClock());
    $row = new \Tecteb\Marketplace\Modules\Order\Domain\VendorOrderItem(
        id: 0,
        orderId: TMC_P67_ORDER,
        orderItemId: TMC_P67_ORDER + 9,
        productId: 0,
        vendorUserId: $vendor,
        title: 'قلم آزمون نوشتن',
        sku: 'TMCP67-WRITE',
        quantity: 1,
        unitPriceMinor: 1010000,
        baseMinor: 1010000,
        taxMinor: 0,
        commissionMinor: 126250,
        vendorShareMinor: 883750,
        rateBasisPoints: 1250,
        rateSource: 'product',
        ledgerEvent: 'tmc-p67-write',
        status: \Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus::Placed,
        currency: 'IRT',
        exponent: 4
    );
    $say('write_result', (string) $repo->record($row));
    $say('row_landed', (string) ($db->getVar(
        'SELECT COUNT(*) FROM `' . $items . '` WHERE wc_order_item_id = %d',
        [TMC_P67_ORDER + 9]
    ) ?? '__unreadable__'));
    $db->execute('DELETE FROM `' . $items . '` WHERE wc_order_item_id = %d', [TMC_P67_ORDER + 9]);
    return;
}

// =========================================================================
// §2 — THE LEDGER SUM
// =========================================================================

if ($verb === 'books-sum') {
    $vendor = $vendorId();
    $repo = $c->get(\Tecteb\Marketplace\Modules\Finance\Application\LedgerRepositoryInterface::class);
    $balances = $repo->balances($vendor);
    // SHAPE FIRST: a flat `account => int` map cannot carry a unit, which is
    // the whole of the owner's §2.
    $earning = $balances['vendor_earning'] ?? null;
    $say('balances_shape', is_array($earning) ? 'array' : (is_int($earning) ? 'int' : gettype($earning)));
    if (is_int($earning)) {
        $say('vendor_earning_raw', (string) $earning);
        $say('vendor_earning_unit', '__not_reported__');
    } elseif (is_array($earning)) {
        $say('vendor_earning_raw', (string) ($earning['minor'] ?? '__no_key__'));
        $say('vendor_earning_unit', (string) ($earning['currency'] ?? '?')
            . '/1e' . (string) ($earning['exponent'] ?? '?'));
        $say('vendor_earning_text', (string) ($earning['text'] ?? '__no_key__'));
        $say('vendor_earning_exact', array_key_exists('exact', $earning)
            ? ($earning['exact'] ? 'yes' : 'no')
            : '__no_key__');
    }
    // What the rows actually hold, read straight from the table.
    foreach ($db->getResults(
        'SELECT exponent, SUM(amount_minor) AS total FROM `' . $ledger . '`
          WHERE vendor_user_id = %d AND account = %s GROUP BY exponent ORDER BY exponent',
        [$vendor, 'vendor_earning']
    ) as $r) {
        $say('rows_at_e' . (string) $r['exponent'], (string) $r['total']);
    }
    // THE OWNER'S CONTROL, computed here from the two fixture rows alone:
    // ۱۰۰ at e0 plus ۸۸٫۳۷۵ at e4 is exactly ۱۸۸٫۳۷۵.
    $say('control_exact_minor_at_e4', (string) (100 * 10000 + 883750));
    $say('control_exact_text', '۱۸۸٫۳۷۵');
    $say('naive_sum_of_the_two', (string) (100 + 883750));
    return;
}

/** TWO CURRENCIES must not be summed at all, in either direction. */
if ($verb === 'books-two-currencies') {
    $vendor = $vendorId();
    $now = gmdate('Y-m-d H:i:s');
    $event = 'tmc-p67-books-usd';
    $db->execute('DELETE FROM `' . $ledger . '` WHERE event_key = %s', [$event]);
    foreach ([['vendor_earning', 500], ['central_payment', -500]] as [$account, $signed]) {
        $db->execute(
            'INSERT INTO `' . $ledger . '`
             (event_key, vendor_user_id, account, amount_minor, currency, exponent,
              order_ref, item_ref, reason, reverses_entry_id, snapshot, created_at)
             VALUES (%s, %d, %s, %d, %s, %d, %s, %s, %s, NULL, %s, %s)',
            [$event, $vendor, $account, $signed, 'USD', 2, 'tmc-p67', '', 'probe', '{}', $now]
        );
    }
    $repo = $c->get(\Tecteb\Marketplace\Modules\Finance\Application\LedgerRepositoryInterface::class);
    $balances = $repo->balances($vendor);
    $earning = $balances['vendor_earning'] ?? null;
    if (is_int($earning)) {
        $say('two_currencies_answer', 'summed:' . (string) $earning);
    } elseif (is_array($earning)) {
        $say('two_currencies_answer', (string) ($earning['refused'] ?? '__no_key__'));
        $say('two_currencies_minor', (string) ($earning['minor'] ?? '__none__'));
        $say('two_currencies_currencies', (string) ($earning['currencies'] ?? '__no_key__'));
    } else {
        $say('two_currencies_answer', '__no_row__');
    }
    $db->execute('DELETE FROM `' . $ledger . '` WHERE event_key = %s', [$event]);
    $say('usd_rows_left', (string) ($db->getVar(
        'SELECT COUNT(*) FROM `' . $ledger . '` WHERE event_key = %s',
        [$event]
    ) ?? '__unreadable__'));
    return;
}

// =========================================================================
// §3 — TRUNCATION IS ROUNDING
// =========================================================================

/** The §3 fixture's own product, so §1's rows are not disturbed. */
const TMC_P67_SPLIT = 'tmc-p67-split';

$splitProduct = static function (int $author) use ($say): int {
    $existing = get_page_by_path(TMC_P67_SPLIT, OBJECT, 'product');
    if ($existing instanceof \WP_Post) {
        return (int) $existing->ID;
    }
    $id = wp_insert_post([
        'post_type' => 'product',
        'post_title' => 'محصول آزمون تقسیم مرجوعی',
        'post_name' => TMC_P67_SPLIT,
        'post_status' => 'publish',
        'post_author' => $author,
    ], true);
    if (is_wp_error($id)) {
        $say('split_product_not_created', $id->get_error_code());
        return 0;
    }
    $id = (int) $id;
    wp_set_object_terms($id, 'simple', 'product_type');
    update_post_meta($id, '_sku', 'TMCP67-SPLIT');
    update_post_meta($id, '_manage_stock', 'yes');
    update_post_meta($id, '_stock', '50');
    update_post_meta($id, '_stock_status', 'instock');
    update_post_meta($id, '_regular_price', '101');
    update_post_meta($id, '_price', '101');
    update_post_meta($id, '_tmc_commission_type', 'percent');
    update_post_meta($id, '_tmc_commission_value', '12.50');
    return $id;
};

if ($verb === 'trial-on' || $verb === 'trial-off') {
    // THE OPERATIONAL KEY, PUT IN A KNOWN STATE (`alpha.66`'s rule, which
    // this round's own script broke once). The caller restores it in a trap.
    update_option('tmc_order_trial_mode', $verb === 'trial-on' ? '1' : '', false);
    $say('trial_mode', (string) get_option('tmc_order_trial_mode', ''));
    $gate = $c->get(\Tecteb\Marketplace\Modules\Order\Application\OrderOperationsGate::class);
    $answer = $gate->check();
    $say('gate_ready', (bool) ($answer['ready'] ?? false));
    $say('gate_reason', (string) ($answer['reason'] ?? ''));
    return;
}

/**
 * ONE REAL WOOCOMMERCE LINE WHOSE TOTAL IS ۱۰۱ FOR THREE UNITS — the
 * owner's own scenario, and the shape a coupon makes: `set_total()` is what
 * WooCommerce itself writes on a discounted line (`alpha.64`).
 */
if ($verb === 'split-arrange') {
    $gate = $c->get(\Tecteb\Marketplace\Modules\Order\Application\OrderOperationsGate::class);
    $answer = $gate->check();
    $say('gate_ready', (bool) ($answer['ready'] ?? false));
    $say('gate_reason', (string) ($answer['reason'] ?? ''));
    if (($answer['ready'] ?? false) !== true) {
        $say('order_created', 'no');
        return;
    }
    $vendor = $vendorId();
    $wcId = $splitProduct($vendor);
    if ($wcId === 0 || !function_exists('wc_create_order')) {
        $say('fixture', '__absent__');
        return;
    }
    // The marketplace row, so the capture recognises the line as ours.
    $intake = $c->get(\Tecteb\Marketplace\Modules\Migration\Application\ImportExistingProducts::class);
    $runId = $intake->newRunId();
    $intake->importBatch($runId, [$wcId], 0, 5);
    $rowId = (int) ($db->getVar(
        'SELECT id FROM `' . $db->prefix() . 'tmc_products` WHERE wc_product_id = %d',
        [$wcId]
    ) ?? 0);
    if ($rowId > 0) {
        $transfer = $c->get(\Tecteb\Marketplace\Modules\Migration\Application\TransferOwnership::class);
        $seen = [];
        foreach ($transfer->storefrontDrift($rowId) as $field => $pair) {
            $seen[$field] = (string) $pair['fingerprint'];
        }
        $say('take_code', $transfer->take($rowId, array_keys($transfer->risksFor($rowId)), $seen)->code);
        $c->get(\Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface::class)
            ->updateStatus($rowId, \Tecteb\Marketplace\Modules\Product\Domain\ProductStatus::Published);
    }
    $say('marketplace_row', $rowId === 0 ? '__none__' : $rowId);

    // THE STALE IDS GO FIRST. A run whose capture fails must not leave the
    // previous run's item id behind for the next verb to measure — that is
    // how a measurement ends up being about somebody else's row
    // (`alpha.33`).
    delete_option('tmc_p67_split_order');
    delete_option('tmc_p67_split_item');
    $order = wc_create_order(['customer_id' => $adminId]);
    if (is_wp_error($order)) {
        $say('order_not_created', $order->get_error_code());
        return;
    }
    $order->add_product(wc_get_product($wcId), 3);
    $order->calculate_totals();
    // THE DISCOUNTED LINE: three units, ۱۰۱ تومان in total. Written with the
    // same setter WooCommerce uses, and the order total recomputed from the
    // line rather than from the price.
    foreach ($order->get_items() as $lineItem) {
        $lineItem->set_total('101');
        $lineItem->set_subtotal('101');
        $lineItem->save();
    }
    $order->set_total('101');
    $order->save();
    $orderId = (int) $order->get_id();
    update_option('tmc_p67_split_order', (string) $orderId, false);
    $say('order_id', $orderId);
    $say('order_total', (string) $order->get_total());
    $say('order_currency', (string) $order->get_currency());
    foreach ($order->get_items() as $lineItem) {
        $say('line_total_on_order', (string) $lineItem->get_total());
        $say('line_quantity', (string) $lineItem->get_quantity());
    }
    $order->payment_complete();
    do_action('woocommerce_checkout_order_processed', $orderId, [], $order);

    $repo = $c->get(\Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface::class);
    $captured = $repo->forOrder($orderId);
    if ($captured === []) {
        // SAID, not left silent: the capture wrote nothing, so every verb
        // after this one would be measuring an older run.
        $say('item_id', '__none__');
        $say('capture', '__failed__');
        return;
    }
    foreach ($captured as $line) {
        update_option('tmc_p67_split_item', (string) $line->id, false);
        $say('item_id', $line->id);
        $say('item_quantity', $line->quantity);
        $say('item_base_minor', (string) $line->baseMinor);
        $say('item_commission_minor', (string) ($line->commissionMinor ?? '__none__'));
        $say('item_share_minor', (string) ($line->vendorShareMinor ?? '__none__'));
        $say('item_exponent', $line->exponent === null ? '__none__' : $line->exponent);
        // §3's second half: the per-unit column.
        $say('item_unit_price_minor', $line->unitPriceMinor === null
            ? '__null__'
            : (string) $line->unitPriceMinor);
        $say('unit_price_divides_exactly', (bool) ($line->baseMinor % max(1, $line->quantity) === 0));
        $say('unit_price_exact_would_be', (string) ($line->baseMinor / max(1, $line->quantity)));
    }
    return;
}

/** The per-unit column on its own, so it can be read after `split-arrange`. */
if ($verb === 'split-unit-price') {
    $itemId = (int) get_option('tmc_p67_split_item', '0');
    $row = $itemId === 0 ? null : $db->getRow(
        'SELECT quantity, unit_price_minor, base_minor FROM `' . $items . '` WHERE id = %d',
        [$itemId]
    );
    if ($row === null) {
        $say('split_item', '__absent__');
        return;
    }
    $quantity = max(1, (int) $row['quantity']);
    $base = (int) $row['base_minor'];
    $say('unit_price_minor', $row['unit_price_minor'] === null ? '__null__' : (string) $row['unit_price_minor']);
    $say('unit_price_times_quantity', $row['unit_price_minor'] === null
        ? '__null__'
        : (string) ((int) $row['unit_price_minor'] * $quantity));
    $say('base_minor', (string) $base);
    $say('unit_price_is_exact', $row['unit_price_minor'] === null
        ? '__null__'
        : (((int) $row['unit_price_minor'] * $quantity === $base) ? 'yes' : 'no'));
    $say('division_is_exact', (bool) ($base % $quantity === 0));
    return;
}

/** A PARTIAL return of one of three — the split the owner's §3 names. */
if ($verb === 'split-partial') {
    $itemId = (int) get_option('tmc_p67_split_item', '0');
    if ($itemId === 0) {
        $say('split_item', '__absent__');
        return;
    }
    $vendor = $vendorId();
    $returns = $c->get(\Tecteb\Marketplace\Modules\Order\Application\ManageReturns::class);
    $opened = $returns->open($vendor, $vendor, $itemId, 1, 'آزمون تقسیم', '');
    $say('open_code', (string) $opened->code);
    $returnId = (int) ($opened->context['return_id'] ?? 0);
    if ($returnId === 0) {
        $returnId = (int) ($db->getVar(
            'SELECT id FROM `' . $returnsTable . '` WHERE order_item_id = %d
              AND status NOT IN (%s, %s, %s) ORDER BY id DESC LIMIT 1',
            [$itemId, 'refunded', 'rejected', 'cancelled']
        ) ?? 0);
    }
    $say('return_id', $returnId === 0 ? '__none__' : $returnId);
    if ($returnId === 0) {
        return;
    }
    $say('approve_code', (string) $returns->decide($adminId, $returnId, ReturnStatus::Approved)->code);
    // ACCEPTING THE GOODS IS WHERE THIS CAN BE REFUSED from `alpha.68`, so
    // the two steps are reported separately and the money step is only
    // ATTEMPTED when the first one passed — «invalid_transition» afterwards
    // would be a true sentence about the wrong thing (`alpha.65`).
    $received = $returns->decide($adminId, $returnId, ReturnStatus::Received);
    $say('receive_code', (string) $received->code);
    foreach (['quantity', 'line_quantity', 'base_minor', 'commission_minor', 'parts'] as $key) {
        if (array_key_exists($key, $received->context)) {
            $say('refusal_' . $key, (string) $received->context[$key]);
        }
    }
    if (!$received->ok) {
        $say('refund_code', '__not_attempted__');
        $say('refusal_step', 'received');
        $row = $db->getRow('SELECT status FROM `' . $returnsTable . '` WHERE id = %d', [$returnId]);
        $say('return_status', $row === null ? '__absent__' : (string) $row['status']);
        $say('return_row', '__absent__');
        return;
    }
    $say('refusal_step', '__none__');
    $result = $returns->refund($adminId, $returnId, null, 'آزمون');
    $say('refund_code', (string) $result->code);
    foreach (['refund_minor', 'tax_minor', 'currency', 'exponent'] as $key) {
        $say('declared_' . $key, (string) ($result->context[$key] ?? '__no_key__'));
    }
    $row = $db->getRow(
        'SELECT refund_minor, tax_refund_minor, commission_reversed_minor,
                vendor_share_reversed_minor, status, `tmc_exponent` AS e
           FROM `' . $returnsTable . '` WHERE id = %d',
        [$returnId]
    );
    if ($row === null) {
        $say('return_row', '__absent__');
        return;
    }
    $say('return_status', (string) $row['status']);
    $say('stored_exponent', $row['e'] === null ? '__null__' : (string) $row['e']);
    foreach (['refund_minor', 'tax_refund_minor', 'commission_reversed_minor', 'vendor_share_reversed_minor'] as $key) {
        $say('stored_' . $key, $row[$key] === null ? '__null__' : (string) $row[$key]);
    }
    $line = $db->getRow(
        'SELECT quantity, base_minor, commission_minor FROM `' . $items . '` WHERE id = %d',
        [$itemId]
    );
    $base = (int) ($line['base_minor'] ?? 0);
    $commission = (int) ($line['commission_minor'] ?? 0);
    $quantity = max(1, (int) ($line['quantity'] ?? 1));
    $say('exact_third_of_base', (string) ($base / $quantity));
    $say('exact_third_of_commission', (string) ($commission / $quantity));
    $say('base_divides_exactly', (bool) ($base % $quantity === 0));
    $say('commission_divides_exactly', (bool) ($commission % $quantity === 0));
    $say('stored_refund_is_exact', $row['refund_minor'] === null
        ? '__null__'
        : ((((int) $row['refund_minor']) * $quantity === $base) ? 'yes' : 'no'));
    return;
}

/** THE FULL RETURN, which must keep working whatever the split rule is. */
if ($verb === 'split-full') {
    $itemId = (int) get_option('tmc_p67_split_item', '0');
    if ($itemId === 0) {
        $say('split_item', '__absent__');
        return;
    }
    $vendor = $vendorId();
    $returns = $c->get(\Tecteb\Marketplace\Modules\Order\Application\ManageReturns::class);
    $line = $db->getRow('SELECT quantity, base_minor FROM `' . $items . '` WHERE id = %d', [$itemId]);
    // ⛔ THE WAY OUT, TAKEN. A return refused at «received» stays APPROVED
    // and holds its quantity, so the whole-line return cannot be opened
    // while it is there. `approved → cancelled` exists precisely for this,
    // and taking it here is what shows the refusal is not a dead end.
    $open = $db->getResults(
        'SELECT id FROM `' . $returnsTable . '` WHERE order_item_id = %d
          AND status NOT IN (%s, %s, %s)',
        [$itemId, 'refunded', 'rejected', 'cancelled']
    );
    $cancelled = 0;
    foreach ($open as $row) {
        if ($returns->decide($adminId, (int) $row['id'], ReturnStatus::Cancelled)->ok) {
            $cancelled++;
        }
    }
    $say('cancelled_open_returns', (string) $cancelled);
    $already = (int) ($db->getVar(
        'SELECT COALESCE(SUM(quantity), 0) FROM `' . $returnsTable . '`
          WHERE order_item_id = %d AND status = %s',
        [$itemId, 'refunded']
    ) ?? 0);
    $remaining = max(0, (int) ($line['quantity'] ?? 0) - $already);
    $say('already_refunded_quantity', $already);
    $say('remaining_quantity', $remaining);
    if ($remaining === 0) {
        $say('full_return', '__nothing_left__');
        return;
    }
    $opened = $returns->open($vendor, $vendor, $itemId, $remaining, 'آزمون کامل', '');
    $say('open_code', (string) $opened->code);
    $returnId = (int) ($opened->context['return_id'] ?? 0);
    if ($returnId === 0) {
        $say('return_id', '__none__');
        return;
    }
    $returns->decide($adminId, $returnId, ReturnStatus::Approved);
    $returns->decide($adminId, $returnId, ReturnStatus::Received);
    $say('refund_code', (string) $returns->refund($adminId, $returnId, null, 'آزمون')->code);
    $totals = $db->getRow(
        'SELECT COALESCE(SUM(refund_minor), 0) AS refund,
                COALESCE(SUM(commission_reversed_minor), 0) AS commission,
                COALESCE(SUM(vendor_share_reversed_minor), 0) AS share,
                COALESCE(SUM(quantity), 0) AS quantity
           FROM `' . $returnsTable . '` WHERE order_item_id = %d AND status = %s',
        [$itemId, 'refunded']
    );
    $say('refunded_quantity_total', (string) ($totals['quantity'] ?? '__none__'));
    $say('refunded_base_total', (string) ($totals['refund'] ?? '__none__'));
    $say('line_base_minor', (string) ($line['base_minor'] ?? '__none__'));
    $say('whole_line_given_back', (bool) ((int) ($totals['refund'] ?? 0) === (int) ($line['base_minor'] ?? -1)));
    return;
}

// =========================================================================
// §4 — THE ROLLBACK HAZARD, AS A MEASUREMENT
// =========================================================================

if ($verb === 'rollback-hazard') {
    $gateClass = \Tecteb\Marketplace\Modules\Finance\Application\MoneyScaleRollbackGate::class;
    $say('has_rollback_gate', (bool) class_exists($gateClass));
    if (!class_exists($gateClass)) {
        // On `alpha.67` nothing answers this question, so the counts are
        // taken straight from the tables to show it is answerable.
        $finer = (int) ($db->getVar(
            'SELECT COUNT(*) FROM `' . $items . '` i
               LEFT JOIN `' . $units . '` u ON u.vendor_user_id = i.vendor_user_id
              WHERE i.`tmc_exponent` IS NOT NULL
                AND i.`tmc_exponent` > COALESCE(u.exponent, 0)'
        ) ?? 0);
        $say('rows_finer_than_the_registry', $finer);
        $ledgerFiner = (int) ($db->getVar(
            'SELECT COUNT(*) FROM `' . $ledger . '` l
               LEFT JOIN `' . $units . '` u ON u.vendor_user_id = l.vendor_user_id
              WHERE l.exponent > COALESCE(u.exponent, 0)'
        ) ?? 0);
        $say('ledger_entries_finer_than_the_registry', $ledgerFiner);
        $say('verdict', '__nothing_answers_this__');
        return;
    }
    $gate = new $gateClass($db);
    $answer = $gate->check();
    foreach (['safe', 'verdict', 'reason'] as $key) {
        if (array_key_exists($key, $answer)) {
            $say('gate_' . $key, $answer[$key]);
        }
    }
    foreach (($answer['counts'] ?? []) as $table => $count) {
        $say('finer_rows_' . (string) $table, (string) $count);
    }
    $say('gate_readable', array_key_exists('readable', $answer)
        ? ($answer['readable'] ? 'yes' : 'no')
        : '__no_key__');
    return;
}

// =========================================================================
if ($verb === 'report') {
    $vendor = $vendorId();
    $say('vendor_user_id', $vendor);
    $say('schema_version', (string) get_option('tmc_schema_version', '__none__'));
    $say('plugin_version', (string) (get_file_data(
        WP_PLUGIN_DIR . '/tecteb-marketplace-core/tecteb-marketplace-core.php',
        ['Version' => 'Version']
    )['Version'] ?? '__none__'));
    $say('fixture_item_rows', (string) ($db->getVar(
        'SELECT COUNT(*) FROM `' . $items . '` WHERE wc_order_id IN (%d, %d)',
        [TMC_P67_ORDER, (int) get_option('tmc_p67_split_order', '0')]
    ) ?? '__unreadable__'));
    $say('fixture_ledger_rows', (string) ($db->getVar(
        'SELECT COUNT(*) FROM `' . $ledger . '` WHERE event_key LIKE %s',
        ['tmc-p67-%']
    ) ?? '__unreadable__'));
    return;
}

// =========================================================================
if ($verb === 'reset') {
    // GIVES BACK EVERYTHING THIS FILE SPENT — rows first, then the ledger
    // lines that hang off them, then the posts. A fixture that spends a
    // guarantee has to give it back (`alpha.67`'s own lesson, where one
    // leftover exponent-4 row made every later capture `books_mixed`).
    $vendor = $vendorId();
    $splitOrder = (int) get_option('tmc_p67_split_order', '0');
    $events = array_map(
        static fn (array $r): string => (string) $r['ledger_event'],
        $db->getResults(
            'SELECT ledger_event FROM `' . $items . '` WHERE wc_order_id IN (%d, %d) AND ledger_event <> %s',
            [TMC_P67_ORDER, $splitOrder, '']
        )
    );
    $itemIds = array_map(
        static fn (array $r): int => (int) $r['id'],
        $db->getResults('SELECT id FROM `' . $items . '` WHERE wc_order_id IN (%d, %d)', [TMC_P67_ORDER, $splitOrder])
    );
    foreach ($itemIds as $id) {
        $db->execute('DELETE FROM `' . $db->prefix() . 'tmc_shipments` WHERE order_item_id = %d', [$id]);
        $db->execute('DELETE FROM `' . $returnsTable . '` WHERE order_item_id = %d', [$id]);
    }
    $say('item_rows_removed', (string) ($db->execute(
        'DELETE FROM `' . $items . '` WHERE wc_order_id IN (%d, %d)',
        [TMC_P67_ORDER, $splitOrder]
    ) ?? '__failed__'));
    $db->execute('DELETE FROM `' . $ledger . '` WHERE event_key LIKE %s', ['tmc-p67-%']);
    foreach ($events as $event) {
        $db->execute('DELETE FROM `' . $ledger . '` WHERE event_key = %s', [$event]);
        $db->execute('DELETE FROM `' . $ledger . '` WHERE event_key LIKE %s', [$event . ':%']);
    }
    $db->execute('DELETE FROM `' . $ledger . '` WHERE event_key LIKE %s', ['return:%']);
    // ⛔ AND A RETURN WHOSE ORDER ITEM IS ALREADY GONE.
    //
    // The loop above deletes returns BY `order_item_id`, from the ids it
    // read a moment earlier — so a return written in an earlier cycle,
    // whose item row a previous `reset` already removed, is not in that
    // list and survives. It then carries `tmc_exponent = 4` for a vendor
    // whose registry says 0, which is exactly what the rollback gate
    // counts: the drill that measures «going back is safe again» was
    // answered `blocked` by two orphans of this very file, and the answer
    // was CORRECT about the database. Same rule, one table further
    // (`alpha.67`), and scoped to this fixture's vendor so nothing of
    // anybody else's is touched.
    $orphans = $db->execute(
        'DELETE r FROM `' . $returnsTable . '` r
           LEFT JOIN `' . $items . '` i ON i.id = r.order_item_id
          WHERE r.vendor_user_id = %d AND i.id IS NULL',
        [$vendor]
    );
    $say('orphan_returns_removed', $orphans === null ? '__failed__' : (string) $orphans);
    // ⛔ AND THE LEDGER ROWS OF ORDERS THAT NO LONGER EXIST.
    //
    // `split-arrange` makes a REAL WooCommerce order, and its capture writes
    // `order:<id>:item:<n>` rows. Deleting the fixture's item rows and the
    // order post does not take those with them, and one leftover
    // exponent-4 row makes every later capture on this borrowed shop
    // `books_mixed` — which is `alpha.67`'s own lesson, paid for once
    // already: «فیکسچری که یک ضمانت را خرج می‌کند باید آن را پس بدهد».
    //
    // Only rows whose ORDER POST IS GONE are removed, so nothing belonging
    // to a live order of somebody else's is touched.
    foreach ($db->getResults(
        'SELECT DISTINCT order_ref FROM `' . $ledger . '`
          WHERE vendor_user_id = %d AND event_key LIKE %s',
        [$vendor, 'order:%']
    ) as $row) {
        $orderRef = (int) $row['order_ref'];
        if ($orderRef <= 0 || get_post_status($orderRef) !== false) {
            continue;
        }
        $db->execute(
            'DELETE FROM `' . $ledger . '` WHERE vendor_user_id = %d AND order_ref = %s',
            [$vendor, (string) $orderRef]
        );
        $say('swept_ledger_for_order', (string) $orderRef);
    }
    if ($splitOrder > 0 && function_exists('wp_delete_post')) {
        wp_delete_post($splitOrder, true);
    }
    $product = get_page_by_path(TMC_P67_SPLIT, OBJECT, 'product');
    if ($product instanceof \WP_Post) {
        $db->execute(
            'DELETE FROM `' . $db->prefix() . 'tmc_products` WHERE wc_product_id = %d',
            [(int) $product->ID]
        );
        wp_delete_post((int) $product->ID, true);
        $say('split_product_deleted', (int) $product->ID);
    }
    delete_option('tmc_p67_split_order');
    delete_option('tmc_p67_split_item');
    $say('exponents_left_for_vendor', implode(',', array_map(
        static fn (array $r): string => (string) $r['exponent'],
        $db->getResults(
            'SELECT DISTINCT exponent FROM `' . $ledger . '` WHERE vendor_user_id = %d ORDER BY exponent',
            [$vendor]
        )
    )));
    $say('reset', 'done');
    return;
}

$say('unknown_verb', $verb);
