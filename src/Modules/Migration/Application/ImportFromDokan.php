<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\OptionStoreInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;

/**
 * Bringing a Dokan shop into the marketplace — as a dry run first, and
 * reversibly.
 *
 * Three properties make this safe enough to run on a live site, and they are
 * the reason the code looks the way it does:
 *
 * 1. **Nothing of Dokan's is written, ever.** Not the seller's user, not the
 *    product's `post_author`, not a single Dokan table. DokanReaderInterface
 *    has no write method, so the capability is absent rather than guarded.
 *    What the import creates is marketplace rows that POINT at the existing
 *    WooCommerce product — which is also how «شناسه، URL، محصول، کاربر و سفارش
 *    حفظ می‌شوند» is satisfied: nothing is recreated, so nothing changes id.
 * 2. **The dry run is the default.** `plan()` writes nothing and returns a
 *    row-by-row mapping with a verdict each. A conflict is reported, never
 *    resolved by guessing.
 * 3. **Every import is one run, and a run can be undone.** Each created row's
 *    id is written into a manifest under the run id, and `rollback()` deletes
 *    exactly those and nothing else. Because nothing outside our own tables
 *    was touched, undoing a run really does restore the state before it.
 *
 * What this does NOT do, deliberately: it does not make the imported products
 * the marketplace's to sell. They arrive as drafts AND as `observed` links,
 * which are two different refusals and both are needed.
 *
 * Draft alone was not enough, and finding that out is what produced the
 * second one. A `marketplace` link makes every "is this ours?" question
 * answer yes — so after a dry-run import, Dokan's own vendor product answered
 * `vendor_stopped` and `is_purchasable()` went false, and a stop would have
 * drafted a post Dokan manages. Nothing of Dokan's had been written; the hash
 * of Dokan's data was identical; and the shop behaved differently anyway.
 * That is precisely why a fingerprint is not a proof of unchanged behaviour,
 * and why TransferOwnership is a separate, explicit act.
 */
final class ImportFromDokan
{
    /** Where each run's manifest lives, so a rollback knows what it made. */
    public const RUNS_OPTION = 'tmc_dokan_migration_runs';

    public function __construct(
        private readonly DokanReaderInterface $dokan,
        private readonly VendorRepositoryInterface $vendors,
        private readonly ProductRepositoryInterface $products,
        private readonly OptionStoreInterface $options,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
        private readonly ?CapabilityCheckerInterface $capabilities = null,
        /**
         * Optional so an install without schema 15 still loads. A null here
         * means history is not imported, and the plan says so rather than
         * silently dropping it.
         */
        private readonly ?OrderHistoryRepositoryInterface $history = null
    ) {
    }

    /**
     * The dry run. Reads Dokan, reads us, writes nothing.
     */
    public function plan(): DokanMigrationPlan
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $runId = 'dokan-' . $this->clock->now()->format('Ymd-His');

        $vendorRows = [];
        foreach ($this->dokan->vendors() as $vendor) {
            $existing = $this->vendors->findProfileByUser($vendor['user_id']);
            $vendorRows[] = [
                'dokan_id' => $vendor['user_id'],
                'title' => $vendor['store_name'],
                'verdict' => $existing !== null ? DokanMigrationPlan::SKIP : DokanMigrationPlan::IMPORT,
                'reason' => $existing !== null ? 'already_a_marketplace_vendor' : '',
                'target' => $existing !== null ? 'tmc_vendor:' . $vendor['user_id'] : 'tmc_vendor:new',
            ];
        }

        $productRows = [];
        foreach ($this->dokan->products() as $product) {
            [$verdict, $reason] = $this->judgeProduct($product);
            $productRows[] = [
                'dokan_id' => $product['wc_product_id'],
                'title' => $product['title'],
                'verdict' => $verdict,
                'reason' => $reason,
                'target' => $verdict === DokanMigrationPlan::IMPORT
                    ? 'tmc_product:new → wc:' . $product['wc_product_id']
                    : 'wc:' . $product['wc_product_id'],
            ];
        }

        // Orders are REPORTED and not imported in this round. A Dokan order
        // line carries a commission worked out under Dokan's rules, and
        // re-recording it under ours would either invent a rate (FIN-02
        // forbids it) or write a ledger line that contradicts the money that
        // actually moved. So the plan counts them and says so.
        // The verdict changed in `alpha.14`, and the REASON it changed is worth
        // keeping. These orders were skipped because re-recording them under
        // our rules would either invent a rate (FIN-02 forbids it) or write a
        // ledger line contradicting money that already moved. Both are still
        // true — so the history arrives as a RECORD carrying Dokan's own
        // figures, with no ledger line and no rate applied. «Imported» here
        // means «written down», never «recalculated».
        $orderRows = [];
        foreach ($this->dokan->orders() as $order) {
            $orderRows[] = [
                'dokan_id' => $order['wc_order_id'],
                'title' => (string) $order['wc_order_id'],
                'verdict' => $this->history === null
                    ? DokanMigrationPlan::SKIP
                    : DokanMigrationPlan::IMPORT,
                'reason' => $this->history === null
                    ? 'history_storage_unavailable'
                    : 'history_recorded_without_recomputing',
                'target' => 'wc_order:' . $order['wc_order_id'],
            ];
        }

        $plan = new DokanMigrationPlan($runId, $vendorRows, $productRows, $orderRows, $now);
        $summary = $plan->summary();
        $this->audit->log(AuditEventCatalog::DOKAN_DRY_RUN, $this->actorId(), 'migration', $runId, [
            'vendors' => $summary['vendors'],
            'products' => $summary['products'],
            'orders' => count($orderRows),
            'conflicts' => $summary['conflicts'],
            'run_id' => $runId,
        ]);
        return $plan;
    }

    /**
     * Runs a plan for real — the rows it marked `import`, and nothing else.
     *
     * Refuses a plan with conflicts outright. «تطبیق داده‌ها» means a person
     * looked at the conflicts and dealt with them; importing around them would
     * make the dry run decorative.
     */
    public function import(DokanMigrationPlan $plan): OperationResult
    {
        if ($this->capabilities === null || !$this->capabilities->can(Capabilities::REVIEW_VENDOR)) {
            return OperationResult::failure('forbidden');
        }
        if (!$plan->isClean()) {
            return OperationResult::failure('migration_has_conflicts', [
                'conflicts' => $plan->summary()['conflicts'],
            ]);
        }
        $created = ['vendors' => [], 'products' => []];
        $byId = [];
        foreach ($this->dokan->vendors() as $vendor) {
            $byId[$vendor['user_id']] = $vendor;
        }

        foreach ($plan->vendors as $row) {
            if ($row['verdict'] !== DokanMigrationPlan::IMPORT) {
                continue;
            }
            $vendor = $byId[$row['dokan_id']] ?? null;
            if ($vendor === null) {
                continue;
            }
            // canSell false: an imported shop is present, not yet trading. The
            // owner turns that on per shop once the mapping has been read.
            $this->vendors->upsertProfile($vendor['user_id'], $vendor['store_name'], false, false);
            $created['vendors'][] = $vendor['user_id'];
        }

        $productsById = [];
        foreach ($this->dokan->products() as $product) {
            $productsById[$product['wc_product_id']] = $product;
        }
        foreach ($plan->products as $row) {
            if ($row['verdict'] !== DokanMigrationPlan::IMPORT) {
                continue;
            }
            $product = $productsById[$row['dokan_id']] ?? null;
            if ($product === null) {
                continue;
            }
            $productId = $this->products->create(
                $product['vendor_user_id'],
                new ProductDetails(
                    title: $product['title'],
                    categoryKey: '',
                    priceMinor: $product['price_minor'],
                    sku: $product['sku'],
                    stock: $product['stock']
                ),
                // Draft, always. Publishing would write to a post Dokan
                // manages, and that is a decision, not an import step.
                ProductStatus::Draft,
                // OBSERVED, always. The row knows which WooCommerce product it
                // corresponds to and nothing else follows: the purchase guard,
                // the storefront stop and the projector all skip it. Without
                // this, a dry-run import made Dokan's own vendor product
                // unpurchasable — measured, and the reason this value exists.
                LinkOwnership::Observed
            );
            if ($productId === 0) {
                continue;
            }
            // Linked to the EXISTING WooCommerce product, so the id and the
            // URL a customer bookmarked keep working.
            // The same stamp the paged path writes. Two import paths that record
            // their origin differently is two paths for a rollback to miss.
            $this->products->stampImportRun($productId, $plan->runId);
            $this->products->link($productId, $product['wc_product_id']);
            $created['products'][] = $productId;
        }

        // The history, through the same recorder the job uses. Two import
        // paths that write history differently is two paths for a rollback to
        // miss — the same lesson the run stamp just taught.
        $historyRecorded = 0;
        if ($this->history !== null) {
            foreach ($this->dokan->orders() as $order) {
                if ($this->history->record($plan->runId, $order)) {
                    $historyRecorded++;
                }
            }
        }

        $this->rememberRun($plan->runId, $created);
        $this->audit->log(AuditEventCatalog::DOKAN_IMPORTED, $this->actorId(), 'migration', $plan->runId, [
            'vendors' => count($created['vendors']),
            'products' => count($created['products']),
            'history' => $historyRecorded,
            'run_id' => $plan->runId,
            'mode' => 'trial',
        ]);
        return OperationResult::success('dokan_imported', [
            'run_id' => $plan->runId,
            'vendors' => count($created['vendors']),
            'products' => count($created['products']),
            'history' => $historyRecorded,
        ]);
    }

    /**
     * Undoes one run: exactly the rows it created.
     *
     * Deletion, not archiving, and that is the one place in this plugin where
     * deleting is the right answer: these rows are not a record of anything
     * that happened, they are a copy that turned out to be unwanted. Nothing
     * of Dokan's is touched, because nothing of Dokan's was ever written.
     */
    public function rollback(string $runId): OperationResult
    {
        if ($this->capabilities === null || !$this->capabilities->can(Capabilities::REVIEW_VENDOR)) {
            return OperationResult::failure('forbidden');
        }
        $runs = $this->runs();
        $run = $runs[$runId] ?? null;
        // Rows stamped with this run id are authoritative; the manifest is the
        // fallback for runs imported before the stamp existed, and for rows a
        // pre-stamp build created. A run with neither is genuinely unknown.
        $stamped = $this->products->idsFromImportRun($runId);
        if ($run === null && $stamped === []) {
            return OperationResult::failure('not_found', ['run_id' => $runId]);
        }
        // The union, de-duplicated: a row can be in both and must be deleted
        // once. This is also what makes a crash mid-page recoverable — those
        // rows are stamped and absent from the manifest.
        $productIds = array_values(array_unique(array_merge(
            array_map('intval', $run['products'] ?? []),
            $stamped
        )));
        sort($productIds);

        $removedProducts = 0;
        $keptProducts = [];
        foreach ($productIds as $productId) {
            $productId = (int) $productId;
            if ($this->products->linkOwnership($productId) === LinkOwnership::Marketplace) {
                // Somebody transferred operational ownership of this product
                // since the import. It is not a trial copy any more — it is a
                // product this marketplace now runs — so undoing the import
                // must not take it away silently.
                $keptProducts[] = $productId;
                continue;
            }
            if ($this->products->deleteDraft($productId)) {
                $removedProducts++;
            }
        }
        // The history goes with the products. A rollback that removed the
        // catalogue but left the past orders would leave a shop half-migrated
        // with no way back — and the next import would find its own rows there.
        $removedHistory = $this->history?->deleteRun($runId) ?? 0;

        $removedVendors = 0;
        foreach ($run['vendors'] ?? [] as $vendorUserId) {
            // The PROFILE goes; the WordPress user does not. The user was
            // Dokan's before this ran and is Dokan's after it.
            if ($this->vendors->deleteEmptyProfile((int) $vendorUserId)) {
                $removedVendors++;
            }
        }
        unset($runs[$runId]);
        $this->options->set(self::RUNS_OPTION, $runs);
        $this->audit->log(AuditEventCatalog::DOKAN_ROLLED_BACK, $this->actorId(), 'migration', $runId, [
            'vendors' => $removedVendors,
            'products' => $removedProducts,
            'history' => $removedHistory,
            'run_id' => $runId,
        ]);
        if ($keptProducts !== []) {
            return OperationResult::failure('rollback_kept_transferred', [
                'run_id' => $runId,
                'vendors' => $removedVendors,
                'products' => $removedProducts,
                'kept' => implode('، ', array_map('strval', $keptProducts)),
            ]);
        }
        return OperationResult::success('dokan_rolled_back', [
            'run_id' => $runId,
            'vendors' => $removedVendors,
            'products' => $removedProducts,
            'history' => $removedHistory,
        ]);
    }

    /** @return array<string,array{vendors:list<int>, products:list<int>, at:string}> */
    /**
     * Every run this install knows about — from the manifest AND from the rows.
     *
     * A run whose process died before writing its first manifest entry exists
     * only as stamped rows, and leaving it out of this list would leave a
     * manager looking at products they cannot undo. So the two sources are
     * merged, and a run present only in the rows is reported with the count
     * read back from them.
     *
     * @return array<string, array{vendors:list<int>, products:list<int>, at?:string, source?:string}>
     */
    public function runs(): array
    {
        $stored = $this->options->get(self::RUNS_OPTION, []);
        $runs = is_array($stored) ? $stored : [];
        foreach ($runs as $runId => $run) {
            $runs[$runId]['source'] = 'manifest';
        }
        foreach ($this->products->importRunIds() as $runId) {
            if (isset($runs[$runId])) {
                continue;
            }
            $runs[$runId] = [
                'vendors' => [],
                'products' => $this->products->idsFromImportRun($runId),
                'at' => '',
                // Named so the page can say «this run was interrupted» rather
                // than showing a row with no date and letting somebody guess.
                'source' => 'rows_only',
            ];
        }
        return $runs;
    }

    public function dokanIsPresent(): bool
    {
        return $this->dokan->isAvailable();
    }

    /**
     * @param array{wc_product_id:int, vendor_user_id:int, title:string, sku:string, price_minor:int, stock:int} $product
     * @return array{0:string, 1:string}
     */
    private function judgeProduct(array $product): array
    {
        if ($this->products->findByWcProduct($product['wc_product_id']) !== null) {
            return [DokanMigrationPlan::SKIP, 'already_owned_by_the_marketplace'];
        }
        if ($this->products->findObservedByWcProduct($product['wc_product_id']) !== null) {
            return [DokanMigrationPlan::SKIP, 'already_mapped_by_an_earlier_run'];
        }
        if ($product['sku'] !== ''
            && $this->products->skuTaken($product['vendor_user_id'], $product['sku'])) {
            // Two products with one SKU is a decision about which is the real
            // one, and nobody here can make it.
            return [DokanMigrationPlan::CONFLICT, 'sku_already_used_in_this_shop'];
        }
        if ($product['price_minor'] <= 0) {
            return [DokanMigrationPlan::CONFLICT, 'no_price_recorded'];
        }
        return [DokanMigrationPlan::IMPORT, ''];
    }

    /**
     * Write down what this run has created SO FAR — appending, never replacing.
     *
     * The old version built the whole list in memory and wrote it once, after
     * both loops. That made the manifest a record of runs that finished, and
     * only those: a run killed by a PHP timeout half way through had created
     * real rows and left nothing that knew about them, so `rollback()` could
     * not undo it and a re-run had no idea they were there. Appending after
     * every batch means the manifest is always at least as complete as the
     * work, which is the direction the error has to lean.
     *
     * @param array{vendors:list<int>, products:list<int>} $created
     */
    public function rememberRun(string $runId, array $created): void
    {
        $runs = $this->runs();
        $existing = $runs[$runId] ?? ['vendors' => [], 'products' => []];
        $runs[$runId] = [
            'vendors' => array_values(array_unique(array_merge(
                array_map('intval', $existing['vendors'] ?? []),
                $created['vendors']
            ))),
            'products' => array_values(array_unique(array_merge(
                array_map('intval', $existing['products'] ?? []),
                $created['products']
            ))),
            'at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ];
        $this->options->set(self::RUNS_OPTION, $runs);
    }

    /**
     * Import one page of vendors, starting strictly after `$afterUserId`.
     *
     * Idempotent against its cursor, because a worker can die between doing the
     * work and writing the checkpoint: `upsertProfile` on a shop that is
     * already there changes nothing, and the manifest de-duplicates.
     *
     * @return array{done:int, last:int, more:bool, created:array{vendors:list<int>, products:list<int>}}
     */
    public function importVendorPage(string $runId, int $afterUserId, int $limit): array
    {
        $created = ['vendors' => [], 'products' => []];
        // One page from the reader, not every seller sliced in PHP. The old
        // version read the whole shop on every batch — which is the cost a
        // batched job exists to avoid, paid once per batch instead of once.
        $page = $this->dokan->vendorsAfter($afterUserId, $limit);
        $last = $afterUserId;
        foreach ($page as $vendor) {
            $last = (int) $vendor['user_id'];
            if ($this->vendors->findProfileByUser($last) !== null) {
                continue;                       // already ours; the plan said skip
            }
            // canSell false: an imported shop is present, not yet trading.
            $this->vendors->upsertProfile($last, $vendor['store_name'], false, false);
            $created['vendors'][] = $last;
        }
        if ($created['vendors'] !== []) {
            $this->rememberRun($runId, $created);
        }
        return [
            'done' => count($page),
            'last' => $last,
            // A short page is the end. Asking «are there more?» by counting the
            // whole set again would undo the reason for paging at all.
            'more' => count($page) >= $limit,
            'created' => $created,
        ];
    }

    /**
     * Import one page of products, starting strictly after `$afterProductId`.
     *
     * A row the plan would have called a conflict is counted as failed and
     * SKIPPED rather than aborting the job, because a job that stops on the
     * first bad SKU leaves a half-imported shop and tells the manager nothing
     * about the other 300 rows. The reasons come back in the notes.
     *
     * @return array{done:int, failed:int, last:int, more:bool, created:array{vendors:list<int>, products:list<int>}, notes:list<string>}
     */
    public function importProductPage(string $runId, int $afterProductId, int $limit): array
    {
        $created = ['vendors' => [], 'products' => []];
        $notes = [];
        $page = $this->dokan->productsAfter($afterProductId, $limit);
        $last = $afterProductId;
        $failed = 0;
        foreach ($page as $product) {
            $last = (int) $product['wc_product_id'];
            [$verdict, $reason] = $this->judgeProduct($product);
            if ($verdict !== DokanMigrationPlan::IMPORT) {
                if ($verdict === DokanMigrationPlan::CONFLICT) {
                    $failed++;
                    $notes[] = $last . ':' . $reason;
                }
                continue;
            }
            $productId = $this->products->create(
                $product['vendor_user_id'],
                new ProductDetails(
                    title: $product['title'],
                    categoryKey: '',
                    priceMinor: $product['price_minor'],
                    sku: $product['sku'],
                    stock: $product['stock']
                ),
                ProductStatus::Draft,
                LinkOwnership::Observed
            );
            if ($productId === 0) {
                $failed++;
                $notes[] = $last . ':create_failed';
                continue;
            }
            // The stamp goes on FIRST, before the link and before the manifest.
            // It is the only record of this row's origin that cannot be lost
            // to a process dying between two writes: the row that exists is
            // the row that says which run made it.
            $this->products->stampImportRun($productId, $runId);
            $this->products->link($productId, $product['wc_product_id']);
            $created['products'][] = $productId;
        }
        if ($created['products'] !== []) {
            // Still written, and still useful — it is where the VENDOR ids
            // live, and vendors have no row of ours to stamp. For products it
            // is now a convenience rather than the source of truth.
            $this->rememberRun($runId, $created);
        }
        return [
            'done' => count($page) - $failed,
            'failed' => $failed,
            'last' => $last,
            'more' => count($page) >= $limit,
            'created' => $created,
            'notes' => array_slice($notes, 0, 20),
        ];
    }

    /**
     * Import one page of past orders, starting strictly after `$afterOrderId`.
     *
     * Nothing is computed. Each row carries the four numbers Dokan recorded —
     * total, the seller's net, the difference it called commission, and whether
     * it was refunded — and no rate from this marketplace touches any of them.
     * No ledger line is written, so settlement, the balance and every report
     * stay blind to these orders: Dokan owned them, and one engine stays
     * responsible per order.
     *
     * @return array{done:int, skipped:int, last:int, more:bool}
     */
    public function importOrderPage(string $runId, int $afterOrderId, int $limit): array
    {
        if ($this->history === null) {
            return ['done' => 0, 'skipped' => 0, 'last' => $afterOrderId, 'more' => false];
        }
        $page = $this->dokan->ordersAfter($afterOrderId, $limit);
        $last = $afterOrderId;
        $done = 0;
        $skipped = 0;
        foreach ($page as $order) {
            $last = max($last, (int) $order['wc_order_id']);
            // `false` here means the unique index already had it — a resumed
            // job re-running its last page, which is normal and not a failure.
            $this->history->record($runId, $order) ? $done++ : $skipped++;
        }
        return [
            'done' => $done,
            'skipped' => $skipped,
            'last' => $last,
            'more' => count($page) >= $limit,
        ];
    }

    /** What a shop's imported history adds up to, in Dokan's own figures. */
    public function historyFor(int $vendorUserId): array
    {
        if ($this->history === null) {
            return ['orders' => 0, 'total_minor' => 0, 'net_minor' => 0, 'available' => false];
        }
        return $this->history->summaryForVendor($vendorUserId) + ['available' => true];
    }

    /**
     * Record the audit line that closes a run. Separate from the loops because
     * a resumable import finishes in a different request from the one that
     * started it.
     */
    public function finishRun(string $runId, int $vendors, int $products, int $skipped): void
    {
        $this->audit->log(AuditEventCatalog::DOKAN_IMPORTED, $this->actorId(), 'migration', $runId, [
            'vendors' => $vendors,
            'products' => $products,
            'skipped' => $skipped,
            'run_id' => $runId,
            'mode' => 'trial',
        ]);
    }

    /** What a run has created so far, for a progress report mid-import. */
    public function runProgress(string $runId): array
    {
        $run = $this->runs()[$runId] ?? ['vendors' => [], 'products' => []];
        return [
            'vendors' => count($run['vendors'] ?? []),
            'products' => count($run['products'] ?? []),
        ];
    }

    private function actorId(): int
    {
        return $this->capabilities?->currentUserId() ?? 0;
    }
}
