<?php
/**
 * The four `alpha.54` findings on `alpha.53`'s own bytes, and the same verbs
 * after the fix.
 *
 * **Its own file, and that is a rule this round paid for.** The first version
 * of this probe was written INTO `alpha53-probe.php`, the previous round's
 * evidence tool. It parsed, the suites were green, and
 * `tools/alpha53-minmax-check.sh` then reported «1 passed, 45 failed» about a
 * build that was working — because its verbs had been replaced by a different
 * round's. One probe per round, named for its round: a round's evidence tool
 * is part of that round's delivery and outlives it.
 *
 * This probe loads on BOTH trees (`alpha.32`'s rule): every service it builds
 * is constructed through reflection arity, every new result key is read with
 * `??`, and nothing it names exists only after the fix. So the same verb can be
 * run on the delivered `alpha.53` and on this checkout, and the two answers are
 * the measurement.
 *
 * **Failure is injected into a COLLABORATOR, never simulated.** The transaction,
 * the ledger and the destination port are each wrapped in a decorator that
 * refuses one named operation and passes everything else through to the real
 * thing — which is the only way «شکست begin» is a fact about this code rather
 * than about a mock. The meta-write failure uses WordPress's own
 * `update_post_metadata` short-circuit (the `alpha.47` method), so the write
 * fails the way a storage failure really fails.
 *
 * Prints ONE key per line (the `alpha.41` rule).
 *
 *   wp eval-file tests/Support/alpha54-probe.php <verb>
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\TransactionInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Migration\Application\MigrateMinMax;
use Tecteb\Marketplace\Modules\Migration\Application\MinMaxHandoverRepositoryInterface;
use Tecteb\Marketplace\Modules\Migration\Application\MinMaxReaderInterface;
use Tecteb\Marketplace\Modules\Migration\Application\ShopQuantityPortInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\ProjectedQuantity;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;

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

const TMC_P54_VENDOR = 9140;
const TMC_P54_CATEGORY = 'tmc-p54-atomicity';
/** Three products, so a batch can fail in the MIDDLE of one. */
const TMC_P54_SKUS = ['SKU-A54-ONE', 'SKU-A54-TWO', 'SKU-A54-THREE'];

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

$db = new WpDatabase($GLOBALS['wpdb']);
$clock = new SystemClock();
$products = new DbProductRepository($db, $clock);
$catalog = $c->get(\Tecteb\Marketplace\Modules\Product\Application\SyncCatalog::class);

$say = static function (string $key, mixed $value): void {
    if (is_bool($value)) {
        $value = $value ? 'yes' : 'no';
    }
    if (is_array($value)) {
        $value = implode('|', array_map('strval', $value));
    }
    echo $key . '=' . str_replace(["\n", ' '], ['', '_'], (string) ($value ?? '__null__')) . "\n";
};
$say('probe', 'ran');

(new DbVendorRepository($db, $clock))->upsertProfile(TMC_P54_VENDOR, 'تجهیزات آزمون ۵۴', true, false);

// ---------------------------------------------------------------- decorators

/** A transaction that refuses ONE named operation and passes the rest through. */
final class TmcP54Transaction implements TransactionInterface
{
    public int $begins = 0;
    public int $commits = 0;
    public int $rollbacks = 0;

    public function __construct(
        private readonly TransactionInterface $inner,
        /** `begin` | `commit` | `none` */
        private readonly string $refuse = 'none'
    ) {
    }

    public function begin(): bool
    {
        $this->begins++;
        if ($this->refuse === 'begin') {
            return false;               // the storage layer said no
        }
        return $this->inner->begin();
    }

    public function commit(): bool
    {
        $this->commits++;
        if ($this->refuse === 'commit') {
            // Refused, and the real transaction is ROLLED BACK so the database
            // is left in the state a failed commit leaves it: nothing applied.
            $this->inner->rollback();
            return false;
        }
        return $this->inner->commit();
    }

    public function rollback(): bool
    {
        $this->rollbacks++;
        return $this->inner->rollback();
    }

    public function inTransaction(): bool
    {
        return $this->inner->inTransaction();
    }
}

/** A ledger that refuses to record, or to mark rolled back. */
/**
 * The ledger, with one named operation refused — and a SUPERSET of both
 * trees' interfaces.
 *
 * `alpha.53` declares `record()`; this round declares `claim()`/`finish()`/
 * `history()`/`release()`. A class may carry extra public methods, so one
 * class satisfies both interfaces and the same probe loads on both trees
 * (`alpha.32`). Every forward goes through `method_exists()`, so nothing here
 * names a method only one of the two has.
 */
final class TmcP54Ledger implements MinMaxHandoverRepositoryInterface
{
    public int $records = 0;
    public int $claims = 0;
    public int $finishes = 0;

    public function __construct(
        private readonly MinMaxHandoverRepositoryInterface $inner,
        /** `record` | `mark` | `none` — `record` refuses whichever write that tree uses */
        private readonly string $refuse = 'none'
    ) {
    }

    /** `alpha.53` only. */
    public function record(array $row): string
    {
        $this->records++;
        if ($this->refuse === 'record') {
            return '';                  // «no decision stored», the real answer
        }
        return method_exists($this->inner, 'record') ? $this->inner->record($row) : '';
    }

    /** This round only: the claim, taken before the destination is written. */
    public function claim(array $row): array
    {
        $this->claims++;
        if (!method_exists($this->inner, 'claim')) {
            return ['claimed' => false, 'id' => 0, 'taken_by' => ''];
        }
        return $this->inner->claim($row);
    }

    /** This round only: the half `record` refuses on the old tree. */
    public function finish(int $claimId, string $status, string $problems, array $written): bool
    {
        $this->finishes++;
        $this->records++;
        if ($this->refuse === 'record') {
            return false;
        }
        return method_exists($this->inner, 'finish')
            ? $this->inner->finish($claimId, $status, $problems, $written)
            : false;
    }

    public function find(int $postId): ?array
    {
        return $this->inner->find($postId);
    }

    public function history(int $postId, int $limit = 20): array
    {
        return method_exists($this->inner, 'history') ? $this->inner->history($postId, $limit) : [];
    }

    public function tally(): array
    {
        return $this->inner->tally();
    }

    public function withStatus(array $statuses, int $limit = 200, int $afterId = 0): array
    {
        return $this->inner->withStatus($statuses, $limit, $afterId);
    }

    public function markRolledBack(int $postId): bool
    {
        if ($this->refuse === 'mark') {
            return false;
        }
        return $this->inner->markRolledBack($postId);
    }

    public function release(int $postId): bool
    {
        return method_exists($this->inner, 'release') ? $this->inner->release($postId) : false;
    }

    /**
     * ADDED IN `alpha.55`, and the reason is a rule this repository already
     * owns: «رابطی که پارامتر می‌گیرد، اسکریپت بازتولید را بی‌صدا از کار
     * می‌اندازد» (`alpha.32`). `alpha.55` gave
     * `MinMaxHandoverRepositoryInterface` two more methods, and PHP requires
     * an implementing class to declare every one — so without these two this
     * probe stopped LOADING on the newer tree, and
     * `tools/alpha54-reproduction.sh` reported sixty-one failures about a
     * build that was working.
     *
     * Forwarded through `method_exists()` like the two above, so the file
     * still loads on `alpha.53`'s bytes, where neither method exists. The
     * probe's own answers are unchanged: no verb of its calls these.
     */
    public function markEnforced(int $postId): bool
    {
        return method_exists($this->inner, 'markEnforced') ? $this->inner->markEnforced($postId) : false;
    }

    public function markUnenforced(int $postId): bool
    {
        return method_exists($this->inner, 'markUnenforced') ? $this->inner->markUnenforced($postId) : false;
    }
}

/**
 * The one answer the batch produced for this product, by name.
 *
 * `refused_for_atomicity` counts one specific outcome; this says WHICH, so a
 * refusal with a different name is not read as «nothing was refused». Reads
 * `failed` with `??` because `alpha.53` has no such key.
 */
$outcomeOf = static function (array $batch): string {
    foreach (($batch['counts'] ?? []) as $name => $count) {
        if ((int) $count > 0) {
            return (string) $name;
        }
    }
    return (string) ($batch['failed'][0]['outcome'] ?? '__none__');
};

/** Build the service with whatever collaborators this verb needs. */
$build = static function (
    ?TransactionInterface $tx,
    ?MinMaxHandoverRepositoryInterface $ledger = null
) use ($c): MigrateMinMax {
    return new MigrateMinMax(
        $c->get(MinMaxReaderInterface::class),
        $ledger ?? $c->get(MinMaxHandoverRepositoryInterface::class),
        $c->get(ShopQuantityPortInterface::class),
        $tx
    );
};

/**
 * Refuse the meta write for ONE post id, WordPress's own way.
 *
 * `update_post_metadata` short-circuits when a filter returns non-null, which
 * is how WordPress itself reports a storage refusal — so the write fails the
 * way a failed write fails, not the way a mock does.
 */
$refuseMetaFor = static function (int $postId): callable {
    $filter = static function (mixed $check, mixed $objectId, mixed $metaKey) use ($postId): mixed {
        if ((int) $objectId === $postId && str_starts_with((string) $metaKey, '_tmc_')) {
            return false;
        }
        return $check;
    };
    add_filter('update_post_metadata', $filter, 10, 3);
    return static function () use ($filter): void {
        remove_filter('update_post_metadata', $filter, 10);
    };
};

// ------------------------------------------------------------------ fixtures

$findBySku = static function (string $sku) use ($products): int {
    foreach ($products->forVendor(TMC_P54_VENDOR) as $product) {
        if ($product->details->sku === $sku) {
            return $product->id;
        }
    }
    return 0;
};

$detailsOf = static function (string $sku): ProductDetails {
    $named = [
        'title' => 'کالای ۵۴ ' . $sku,
        'type' => 'simple',
        'categoryKey' => TMC_P54_CATEGORY,
        'brand' => '',
        'shortDescription' => 'یک جمله',
        'priceMinor' => 400000,
        'sku' => $sku,
        'stock' => 90,
        'minPurchase' => 1,
        'maxPurchase' => null,
        'description' => null,
    ];
    if (property_exists(ProductDetails::class, 'purchaseStep')) {
        $named['purchaseStep'] = 1;
    }
    return new ProductDetails(...$named);
};

$prefix = static function (): string {
    return defined('WC_MMQ_PREFIX') ? WC_MMQ_PREFIX : '';
};

/** The three meta rows, with ABSENT told apart from empty (`alpha.28`). */
$rows = static function (int $wcId): string {
    $out = [];
    foreach (ProjectedQuantity::keys() as $key) {
        if (!metadata_exists('post', $wcId, $key)) {
            $out[] = '__absent__';
            continue;
        }
        $value = (string) get_post_meta($wcId, $key, true);
        $out[] = $value === '' ? '__empty__' : $value;
    }
    return implode('/', $out);
};

/** The same three rows read STRAIGHT FROM THE DATABASE, past every cache. */
$dbRows = static function (int $wcId) use ($db): string {
    $out = [];
    foreach (ProjectedQuantity::keys() as $key) {
        $value = $db->getVar(
            'SELECT meta_value FROM `' . $db->prefix() . 'postmeta`
              WHERE post_id = %d AND meta_key = %s LIMIT 1',
            [$wcId, $key]
        );
        if ($value === null) {
            $out[] = '__absent__';
            continue;
        }
        $out[] = (string) $value === '' ? '__empty__' : (string) $value;
    }
    return implode('/', $out);
};

$ownershipRecord = static function (int $wcId): string {
    $raw = get_post_meta($wcId, ProjectedQuantity::OWNED_META, true);
    $normalised = ProjectedQuantity::normalise($raw);
    if ($normalised === []) {
        return $raw === '' || $raw === false ? '__absent__' : '__unusable__';
    }
    $keys = array_keys($normalised);
    sort($keys);
    return count($normalised) . ':' . implode(',', array_map(
        static fn (string $k): string => str_replace('_tmc_', '', $k),
        $keys
    ));
};

$ledgerRow = static function (int $wcId) use ($c): string {
    $row = $c->get(MinMaxHandoverRepositoryInterface::class)->find($wcId);
    if ($row === null) {
        return '__none__';
    }
    return (string) ($row['status'] ?? '?')
        . ' before=' . ($row['dest_min_before'] === null ? '__absent__' : (string) $row['dest_min_before'])
        . ' wrote=' . ($row['written_min'] === null ? '__null__' : (string) $row['written_min']);
};

$handoverTable = static fn (): string => $db->prefix() . 'tmc_minmax_handover';

/**
 * Three published, projected products with a convertible Min Max rule and a
 * clean destination — and every ledger row for them removed.
 *
 * A fixture gives back what it spends (`alpha.45`): a ledger row or a meta row
 * left by an earlier verb would make the next verb's answer a true sentence
 * about the previous measurement.
 */
$arrange = static function (string $rule = '4/10/2')
use ($products, $findBySku, $detailsOf, $catalog, $db, $prefix, $handoverTable): array {
    [$min, $max, $step] = explode('/', $rule);
    $ids = [];
    foreach (TMC_P54_SKUS as $sku) {
        $id = $findBySku($sku);
        if ($id === 0) {
            $id = $products->create(TMC_P54_VENDOR, $detailsOf($sku), ProductStatus::Draft);
        } else {
            $products->updateDetails($id, $detailsOf($sku), $products->find($id)?->rowVersion ?? 0);
        }
        $row = $products->find($id);
        if ($row !== null && $row->status !== ProductStatus::Published) {
            $products->updateStatus($id, ProductStatus::Published, $row->rowVersion);
        }
        $catalog->publish($id);
        $wcId = (int) ($products->find($id)?->wcProductId ?? 0);
        foreach (ProjectedQuantity::keys() as $key) {
            delete_post_meta($wcId, $key);
        }
        delete_post_meta($wcId, ProjectedQuantity::OWNED_META);
        foreach (['min_quantity', 'max_quantity', 'product_step', 'default_quantity'] as $field) {
            foreach (['', '_wcmmq_', '_wcmmq_s_'] as $p) {
                delete_post_meta($wcId, $p . $field);
            }
        }
        update_post_meta($wcId, $prefix() . 'min_quantity', $min);
        update_post_meta($wcId, $prefix() . 'max_quantity', $max);
        update_post_meta($wcId, $prefix() . 'product_step', $step);
        $db->execute('DELETE FROM `' . $handoverTable() . '` WHERE post_id = %d', [$wcId]);
        wc_delete_product_transients($wcId);
        $ids[$sku] = $wcId;
    }
    return $ids;
};

/** Migrate ONE product through the real service, and say what happened. */
$migrateOne = static function (MigrateMinMax $service, int $wcId, bool $allowOverwrite = false): array {
    return $service->migrateBatch(1, $wcId - 1, $allowOverwrite, 1);
};

$countOf = static function (array $batch, string $key): int {
    return (int) (($batch['counts'] ?? [])[$key] ?? 0);
};

/** @var MinMaxReaderInterface $reader */
$reader = $c->get(MinMaxReaderInterface::class);
/** @var ShopQuantityPortInterface $port */
$port = $c->get(ShopQuantityPortInterface::class);
$realTx = $c->has(TransactionInterface::class) ? $c->get(TransactionInterface::class) : null;

switch ($verb) {
    // ================================================== §1 atomicity
    case 'begin-failure': {
        $ids = $arrange();
        $wcId = $ids[TMC_P54_SKUS[0]];
        $tx = new TmcP54Transaction($realTx ?? new WpDatabase($GLOBALS['wpdb']), 'begin');
        $batch = $migrateOne($build($tx), $wcId);
        $say('begin_called', $tx->begins);
        // THE QUESTION: did a refused `begin()` stop the write?
        $say('rows_after', $rows($wcId));
        $say('db_rows_after', $dbRows($wcId));
        $say('ledger_after', $ledgerRow($wcId));
        $say('migrated_count', $countOf($batch, 'migrated'));
        $say('refused_for_atomicity', $countOf($batch, 'not_atomic'));
        $say('outcome', $outcomeOf($batch));
        break;
    }

    case 'no-transaction': {
        $ids = $arrange();
        $wcId = $ids[TMC_P54_SKUS[0]];
        $batch = $migrateOne($build(null), $wcId);
        $say('rows_after', $rows($wcId));
        $say('ledger_after', $ledgerRow($wcId));
        $say('migrated_count', $countOf($batch, 'migrated'));
        $say('refused_for_atomicity', $countOf($batch, 'not_atomic'));
        $say('outcome', $outcomeOf($batch));
        break;
    }

    case 'caller-transaction': {
        $ids = $arrange();
        $wcId = $ids[TMC_P54_SKUS[0]];
        $tx = $realTx;
        if ($tx === null) {
            $say('no_transaction_service', 'yes');
            break;
        }
        // The CALLER opens a unit of work and writes something of its own.
        $tx->begin();
        $db->execute(
            'INSERT INTO `' . $handoverTable() . '`
                (post_id, post_type, level, status, problems, run_id, decided_at)
             VALUES (%d, %s, %s, %s, %s, %d, UTC_TIMESTAMP())',
            [999001, 'product', 'product', 'caller_marker', '', 0]
        );
        $batch = $migrateOne($build($tx), $wcId);
        $say('still_in_transaction_after', $tx->inTransaction());
        // Another CONNECTION decides whether the caller's work was committed
        // behind its back. The caller never asked for that.
        $other = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $stmt = $other->prepare('SELECT COUNT(*) FROM `' . $handoverTable() . '` WHERE post_id = 999001');
        $stmt->execute();
        $say('caller_row_visible_elsewhere', ((int) $stmt->fetchColumn()) > 0);
        $say('migrated_count', $countOf($batch, 'migrated'));
        // Leave nothing behind, whatever happened.
        if ($tx->inTransaction()) {
            $tx->rollback();
        }
        $db->execute('DELETE FROM `' . $handoverTable() . '` WHERE post_id = 999001');
        break;
    }

    case 'record-failure': {
        $ids = $arrange();
        $wcId = $ids[TMC_P54_SKUS[0]];
        $ledger = new TmcP54Ledger($c->get(MinMaxHandoverRepositoryInterface::class), 'record');
        $tx = new TmcP54Transaction($realTx ?? new WpDatabase($GLOBALS['wpdb']));
        $batch = $migrateOne($build($tx, $ledger), $wcId);
        $say('record_called', $ledger->records);
        $say('committed', $tx->commits);
        $say('rolled_back', $tx->rollbacks);
        // THE QUESTION: a destination written with NO rollback target, reported
        // as a success.
        $say('rows_after', $rows($wcId));
        $say('db_rows_after', $dbRows($wcId));
        $say('ledger_after', $ledgerRow($wcId));
        $say('migrated_count', $countOf($batch, 'migrated'));
        $say('outcome', $outcomeOf($batch));
        break;
    }

    case 'commit-failure': {
        $ids = $arrange();
        $wcId = $ids[TMC_P54_SKUS[0]];
        $tx = new TmcP54Transaction($realTx ?? new WpDatabase($GLOBALS['wpdb']), 'commit');
        $batch = $migrateOne($build($tx), $wcId);
        $say('commit_called', $tx->commits);
        $say('migrated_count', $countOf($batch, 'migrated'));
        $say('reported_not_committed', $countOf($batch, 'not_committed'));
        // Read past every cache: the transaction was rolled back, so the
        // database holds nothing — and a «migrated» answer would be a lie
        // about rows that are not there.
        $say('db_rows_after', $dbRows($wcId));
        $say('ledger_after', $ledgerRow($wcId));
        $say('outcome', $outcomeOf($batch));
        break;
    }

    // ================================================== §2 batch continuation
    case 'batch-continues': {
        $ids = $arrange();
        $sorted = array_values($ids);
        sort($sorted);
        [$first, $middle, $last] = $sorted;
        $undo = $refuseMetaFor($middle);
        $tx = new TmcP54Transaction($realTx ?? new WpDatabase($GLOBALS['wpdb']));
        $batch = $build($tx)->migrateBatch(1, $first - 1, false, 10);
        $undo();
        $say('first_id', $first);
        $say('middle_id', $middle);
        $say('last_id', $last);
        $say('cursor_after', (int) ($batch['cursor'] ?? -1));
        // THE QUESTION: a cursor at the END of the page means the failed
        // product is never looked at again.
        $say('cursor_past_failure', ((int) ($batch['cursor'] ?? 0)) > $middle);
        $say('migrated_count', $countOf($batch, 'migrated'));
        $say('not_written_count', $countOf($batch, 'not_written'));
        $say('stopped_at', (int) ($batch['stopped_at'] ?? 0));
        // `failed` is a list of `{post_id, outcome}` on this tree and absent on
        // `alpha.53`, so it is flattened to «id:reason» rather than printed —
        // the `alpha.41` rule is one key per line, and an array cast to string
        // is the word «Array».
        $say('failed_ids', implode(
            '|',
            array_map(
                static fn (array $f): string => ((string) ($f['post_id'] ?? '?')) . ':' . ((string) ($f['outcome'] ?? '?')),
                $batch['failed'] ?? []
            )
        ) ?: '__none__');
        $say('ledger_first', $ledgerRow($first));
        $say('ledger_middle', $ledgerRow($middle));
        $say('ledger_last', $ledgerRow($last));
        break;
    }

    // ================================================== §3 rollback
    case 'rollback-steps': {
        $ids = $arrange();
        $wcId = $ids[TMC_P54_SKUS[0]];
        // A destination that HAD a rule before the migration, so a rollback
        // has something to put back and «absent» is not the only answer.
        update_post_meta($wcId, ProjectedQuantity::META_MIN, '2');
        update_post_meta($wcId, ProjectedQuantity::META_STEP, '2');
        $before = $rows($wcId);
        $tx = new TmcP54Transaction($realTx ?? new WpDatabase($GLOBALS['wpdb']));
        $batch = $migrateOne($build($tx), $wcId, true);   // overwrite allowed
        $say('before_migration', $before);
        $say('migrated', $countOf($batch, 'migrated') + $countOf($batch, 'written_unenforced'));
        $say('rows_after_migration', $rows($wcId));
        // Now REFUSE the restore write and roll back.
        $undo = $refuseMetaFor($wcId);
        $result = $build($tx)->rollback($wcId);
        $undo();
        $say('rollback_code', $result->code);
        $say('rollback_ok', $result->ok);
        // THE QUESTION: a refused restore that still reports success, and a
        // destination left half-way between the two states.
        $say('rows_after_failed_rollback', $rows($wcId));
        $say('db_rows_after_failed_rollback', $dbRows($wcId));
        $say('ledger_after_failed_rollback', $ledgerRow($wcId));
        break;
    }

    case 'rollback-mark-failure': {
        $ids = $arrange();
        $wcId = $ids[TMC_P54_SKUS[0]];
        $tx = new TmcP54Transaction($realTx ?? new WpDatabase($GLOBALS['wpdb']));
        $migrateOne($build($tx), $wcId);
        $ledger = new TmcP54Ledger($c->get(MinMaxHandoverRepositoryInterface::class), 'mark');
        $result = $build($tx, $ledger)->rollback($wcId);
        $say('rollback_code', $result->code);
        // The rows went back and the ledger did not move: offering the same
        // rollback again would restore over an already-restored destination.
        $say('rows_after', $rows($wcId));
        $say('db_rows_after', $dbRows($wcId));
        $say('ledger_after', $ledgerRow($wcId));
        break;
    }

    case 'rollback-cache': {
        $ids = $arrange();
        $wcId = $ids[TMC_P54_SKUS[0]];
        $tx = new TmcP54Transaction($realTx ?? new WpDatabase($GLOBALS['wpdb']));
        $migrateOne($build($tx), $wcId);
        $result = $build($tx)->rollback($wcId);
        $say('rollback_code', $result->code);
        // SAME REQUEST: what the application reads, and what the database
        // holds. A rolled-back write leaves the meta cache warm.
        $say('app_rows_same_request', $rows($wcId));
        $say('db_rows_same_request', $dbRows($wcId));
        $say('record_same_request', $ownershipRecord($wcId));
        $say('agree_same_request', $rows($wcId) === $dbRows($wcId));
        break;
    }

    case 'fresh-read': {
        // Run AFTER `rollback-cache`, in its own process — a fresh request
        // with a cold cache. The `alpha.47` rule: measure the window in a
        // separate call, not from either side of it.
        $wcId = (int) ($products->find($findBySku(TMC_P54_SKUS[0]))?->wcProductId ?? 0);
        $say('app_rows_fresh', $rows($wcId));
        $say('db_rows_fresh', $dbRows($wcId));
        $say('agree_fresh', $rows($wcId) === $dbRows($wcId));
        $say('record_fresh', $ownershipRecord($wcId));
        $say('ledger_fresh', $ledgerRow($wcId));
        break;
    }

    case 'record-not-the-count': {
        $ids = $arrange();
        $wcId = $ids[TMC_P54_SKUS[0]];
        // WooCommerce (or anything on `update_post_metadata`) alters ONE value
        // on the way in. `count($record) === 3` is still three; the record no
        // longer describes what the shop holds.
        $filter = static function (mixed $check, mixed $objectId, mixed $metaKey, mixed $value) use ($wcId): mixed {
            if ((int) $objectId === $wcId && $metaKey === ProjectedQuantity::META_MAX) {
                update_post_meta($wcId, ProjectedQuantity::META_MAX . '_shadow', $value);
                // Store something ELSE, the way a filter that rewrites a value
                // would. Returning null lets the real write proceed, so this
                // is done by writing the altered value first and refusing.
                $GLOBALS['tmc_p54_altered'] = true;
                return false;
            }
            return $check;
        };
        add_filter('update_post_metadata', $filter, 10, 4);
        $written = $port->write($wcId, '4', '10', '2');
        remove_filter('update_post_metadata', $filter, 10);
        $say('port_says_stored', (bool) ($written['stored'] ?? false));
        $say('max_in_shop', metadata_exists('post', $wcId, ProjectedQuantity::META_MAX)
            ? (string) get_post_meta($wcId, ProjectedQuantity::META_MAX, true)
            : '__absent__');
        $say('record_after', $ownershipRecord($wcId));
        delete_post_meta($wcId, ProjectedQuantity::META_MAX . '_shadow');
        break;
    }

    case 'record-stale': {
        // `count($record) === 3` is not «the record describes what the shop
        // holds». A product projected EARLIER already carries a three-key
        // record; refuse only the record write and the old one survives, three
        // keys and all, pointing at values that are no longer there.
        $ids = $arrange();
        $wcId = $ids[TMC_P54_SKUS[0]];
        $port->write($wcId, '2', '6', '2');
        $say('record_before', $ownershipRecord($wcId));
        $filter = static function (mixed $check, mixed $objectId, mixed $metaKey) use ($wcId): mixed {
            if ((int) $objectId === $wcId && $metaKey === ProjectedQuantity::OWNED_META) {
                return false;
            }
            return $check;
        };
        add_filter('update_post_metadata', $filter, 10, 3);
        $written = $port->write($wcId, '4', '10', '2');
        remove_filter('update_post_metadata', $filter, 10);
        $say('port_says_stored', (bool) ($written['stored'] ?? false));
        $say('values_after', $rows($wcId));
        $say('record_after', $ownershipRecord($wcId));
        // Does the surviving record still describe the shop?
        $stored = ProjectedQuantity::normalise(get_post_meta($wcId, ProjectedQuantity::OWNED_META, true));
        $matches = 0;
        foreach (ProjectedQuantity::keys() as $key) {
            $now = (string) get_post_meta($wcId, $key, true);
            if (isset($stored[$key]) && hash_equals($stored[$key], ProjectedQuantity::fingerprint($now))) {
                $matches++;
            }
        }
        $say('record_entries', count($stored));
        $say('record_entries_matching_shop', $matches);
        $say('held_elsewhere_now', (bool) ($port->read($wcId)['held'] ?? false));
        break;
    }

    case 'manager-edit-locked': {
        // THE RACE, with a REAL second process holding the rows.
        //
        // The first version of this verb injected the other writer through
        // WordPress's own `update_post_metadata` filter, which runs on THIS
        // connection and inside THIS transaction — the `alpha.40` trap. It
        // measured nothing: both trees answered identically
        // (`injected_inside_window=yes rollback_code=minmax_rolled_back`)
        // about a guard that does work, because a lock cannot block its own
        // holder. That verb was DELETED rather than kept, because a verb that
        // cannot fail looks exactly like one that passed.
        //
        // So the other writer here is a separate `wp` process on a separate
        // connection, and it HOLDS its transaction open on a FIFO until this
        // side has finished asking. No `sleep` anywhere: the wait is a
        // blocking read on one side and a read of the holder's own output on
        // the other, so a holder that never starts is reported as
        // `measurement=not_run` instead of as a pass.
        $ids = $arrange();
        $wcId = $ids[TMC_P54_SKUS[0]];
        $tx = new TmcP54Transaction($realTx ?? new WpDatabase($GLOBALS['wpdb']));
        $migrateOne($build($tx), $wcId);
        $say('rows_after_migration', $rows($wcId));

        $repo = getenv('TMC_REPO') ?: '/home/user/tecteb-seller';
        $wpPath = getenv('TMC_WP_PATH') ?: '/home/user/wp-demo';
        $wpcli = getenv('TMC_WPCLI') ?: '/usr/local/bin/wp';
        $fifo = sys_get_temp_dir() . '/tmc-p54-' . getmypid() . '.fifo';
        @unlink($fifo);
        if (!function_exists('posix_mkfifo') && !@shell_exec('command -v mkfifo')) {
            $say('holder_started', 'no_mkfifo');
            break;
        }
        @shell_exec('mkfifo ' . escapeshellarg($fifo));
        $holderOut = sys_get_temp_dir() . '/tmc-p54-' . getmypid() . '.out';
        $command = escapeshellarg($wpcli) . ' --allow-root --path=' . escapeshellarg($wpPath)
            . ' eval-file ' . escapeshellarg($repo . '/tests/Support/concurrent-quantity-edit.php')
            . ' hold ' . (int) $wcId . ' 8 ' . escapeshellarg($fifo)
            . ' >' . escapeshellarg($holderOut) . ' 2>&1 &';
        @shell_exec($command);
        // Wait for the holder to SAY it is holding, by reading its output —
        // not by sleeping for a guessed interval. If it never says so, the
        // measurement is reported as not run rather than as a pass.
        $holding = false;
        for ($i = 0; $i < 600; $i++) {
            if (is_file($holderOut) && str_contains((string) file_get_contents($holderOut), 'holder_holding=yes')) {
                $holding = true;
                break;
            }
            usleep(50000);
        }
        $say('holder_holding', $holding);
        if (!$holding) {
            @shell_exec('printf "go\n" > ' . escapeshellarg($fifo) . ' &');
            $say('measurement', 'not_run');
            break;
        }
        // This side's lock attempt must not wait for ever, so the session says
        // how long it is prepared to wait. Two seconds is the probe's own
        // choice and nothing in the plugin depends on it.
        $db->execute('SET SESSION innodb_lock_wait_timeout = 2');
        $result = $build($tx)->rollback($wcId);
        $say('rollback_code', $result->code);
        $say('rollback_ok', $result->ok);
        // Let the holder commit, then ask what is actually there.
        @shell_exec('printf "go\n" > ' . escapeshellarg($fifo));
        for ($i = 0; $i < 600; $i++) {
            if (is_file($holderOut) && str_contains((string) file_get_contents($holderOut), 'holder_committed=yes')) {
                break;
            }
            usleep(50000);
        }
        $say('max_now', $dbRows($wcId));
        $other = (string) ($db->getVar(
            'SELECT meta_value FROM `' . $GLOBALS['wpdb']->postmeta . '`
              WHERE post_id = %d AND meta_key = %s',
            [$wcId, ProjectedQuantity::META_MAX]
        ) ?? '__absent__');
        $say('manager_value_kept', $other === '8');
        $say('ledger_after', $ledgerRow($wcId));
        @unlink($fifo);
        break;
    }

    case 'manager-edit-race': {
        $ids = $arrange();
        $wcId = $ids[TMC_P54_SKUS[0]];
        $tx = new TmcP54Transaction($realTx ?? new WpDatabase($GLOBALS['wpdb']));
        $migrateOne($build($tx), $wcId);
        $say('rows_after_migration', $rows($wcId));
        // The manager changes the rule AFTER the migration. The rollback must
        // refuse and leave their value alone.
        update_post_meta($wcId, ProjectedQuantity::META_MAX, '8');
        $result = $build($tx)->rollback($wcId);
        $say('rollback_code', $result->code);
        $say('manager_value_kept', (string) get_post_meta($wcId, ProjectedQuantity::META_MAX, true));
        $say('rows_after', $rows($wcId));
        break;
    }

    // ================================================== §4 re-migration
    case 'remigrate': {
        $ids = $arrange();
        $wcId = $ids[TMC_P54_SKUS[0]];
        $tx = new TmcP54Transaction($realTx ?? new WpDatabase($GLOBALS['wpdb']));
        $service = $build($tx);
        $first = $migrateOne($service, $wcId);
        $say('first_migrated', $countOf($first, 'migrated') + $countOf($first, 'written_unenforced'));
        $say('rows_after_first', $rows($wcId));
        $back = $service->rollback($wcId);
        $say('rollback_code', $back->code);
        $say('rows_after_rollback', $rows($wcId));
        $second = $migrateOne($service, $wcId);
        // THE QUESTION: a rolled-back product that can never be migrated again.
        $say('second_migrated', $countOf($second, 'migrated') + $countOf($second, 'written_unenforced'));
        $say('second_already_decided', $countOf($second, 'already_decided'));
        $say('rows_after_second', $rows($wcId));
        $say('ledger_after_second', $ledgerRow($wcId));
        $say('history_rows', (int) ($db->getVar(
            'SELECT COUNT(*) FROM `' . $handoverTable() . '` WHERE post_id = %d',
            [$wcId]
        ) ?? 0));
        break;
    }

    case 'retry-held': {
        $ids = $arrange();
        $wcId = $ids[TMC_P54_SKUS[0]];
        // A rule the manager already owns at the destination ⇒ held.
        update_post_meta($wcId, ProjectedQuantity::META_MIN, '6');
        update_post_meta($wcId, ProjectedQuantity::META_MAX, '12');
        update_post_meta($wcId, ProjectedQuantity::META_STEP, '1');
        $tx = new TmcP54Transaction($realTx ?? new WpDatabase($GLOBALS['wpdb']));
        $service = $build($tx);
        $held = $migrateOne($service, $wcId);
        $say('held_count', $countOf($held, 'held_by_manager'));
        $say('ledger_held', $ledgerRow($wcId));
        // The manager removes their rule — the cause is gone.
        foreach (ProjectedQuantity::keys() as $key) {
            delete_post_meta($wcId, $key);
        }
        $retry = $migrateOne($service, $wcId, false);
        // WITHOUT the explicit release the decision still stands — on both
        // trees — because nothing here can tell that the manager's rule was
        // removed on purpose rather than by accident. That is the designed
        // answer, not the finding; the finding is that `alpha.53` had NO WAY
        // OUT of it at all.
        $say('retry_migrated', $countOf($retry, 'migrated') + $countOf($retry, 'written_unenforced'));
        $say('retry_already_decided', $countOf($retry, 'already_decided'));
        $say('rows_after_retry', $rows($wcId));

        // THE PATH THIS ROUND ADDS. `release()` does not exist on `alpha.53`,
        // so it is called through `method_exists` and the key says plainly
        // that there was none (`alpha.32`).
        if (!method_exists($service, 'release')) {
            $say('release_available', 'no');
            $say('released_code', '__no_such_method__');
            break;
        }
        $say('release_available', 'yes');
        $released = $service->release($wcId);
        $say('released_code', $released->code);
        $say('released_was', (string) ($released->context['was'] ?? ''));
        $say('ledger_after_release', $ledgerRow($wcId));
        $say('history_rows_after_release', count($service->historyFor($wcId)));
        $after = $migrateOne($service, $wcId, false);
        $say('after_release_migrated', $countOf($after, 'migrated') + $countOf($after, 'written_unenforced'));
        $say('after_release_already_decided', $countOf($after, 'already_decided'));
        $say('rows_after_release', $rows($wcId));
        break;
    }

    // ================================================== controls
    case 'control-convertible': {
        $ids = $arrange('4/10/2');
        $wcId = $ids[TMC_P54_SKUS[0]];
        $tx = new TmcP54Transaction($realTx ?? new WpDatabase($GLOBALS['wpdb']));
        $batch = $migrateOne($build($tx), $wcId);
        $say('migrated', $countOf($batch, 'migrated') + $countOf($batch, 'written_unenforced'));
        $say('rows', $rows($wcId));
        $say('db_rows', $dbRows($wcId));
        $say('record', $ownershipRecord($wcId));
        break;
    }

    case 'control-refused': {
        $ids = $arrange('2/11/3');           // the step counts from the minimum
        $wcId = $ids[TMC_P54_SKUS[0]];
        $tx = new TmcP54Transaction($realTx ?? new WpDatabase($GLOBALS['wpdb']));
        $batch = $migrateOne($build($tx), $wcId);
        $say('refused', $countOf($batch, 'refused'));
        $say('migrated', $countOf($batch, 'migrated'));
        $say('rows', $rows($wcId));
        $row = $c->get(MinMaxHandoverRepositoryInterface::class)->find($wcId);
        $say('problems', (string) (($row['problems'] ?? '') ?: '__none__'));
        break;
    }

    case 'reset':
        $arrange();
        $say('reset', 'yes');
        break;

    case 'hand-back': {
        // GIVE BACK WHAT THIS PROBE SPENDS, which is more than its own rows.
        //
        // `arrange()` writes Min Max SOURCE metas onto three products, and
        // those rows are part of the SITE's Min Max state — so the previous
        // round's script, which counts «how many products carry a rule», saw
        // seven where its own fixture had made four and reported six failures
        // about a build that was working. `alpha.33`'s rule: a fixture that
        // spends something has to hand it back.
        $ids = $arrange();
        $handed = 0;
        foreach ($ids as $wcId) {
            foreach (['min_quantity', 'max_quantity', 'product_step', 'default_quantity'] as $field) {
                foreach (['', '_wcmmq_', '_wcmmq_s_'] as $prefix) {
                    if (metadata_exists('post', $wcId, $prefix . $field)) {
                        delete_post_meta($wcId, $prefix . $field);
                        $handed++;
                    }
                }
            }
            foreach (ProjectedQuantity::keys() as $key) {
                delete_post_meta($wcId, $key);
            }
            delete_post_meta($wcId, ProjectedQuantity::OWNED_META);
        }
        $db->execute(
            'DELETE FROM `' . $handoverTable() . '` WHERE post_id IN (%d, %d, %d)',
            array_values($ids)
        );
        $say('source_rows_removed', $handed);
        $say('products', implode(',', array_values($ids)));
        $say('hand_back', 'yes');
        break;
    }

    default:
        $say('unknown_verb', $verb);
}
