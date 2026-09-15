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
        private readonly ?CapabilityCheckerInterface $capabilities = null
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
        $orderRows = [];
        foreach ($this->dokan->orders() as $order) {
            $orderRows[] = [
                'dokan_id' => $order['wc_order_id'],
                'title' => (string) $order['wc_order_id'],
                'verdict' => DokanMigrationPlan::SKIP,
                'reason' => 'historic_commission_not_recomputed',
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
            $this->products->link($productId, $product['wc_product_id']);
            $created['products'][] = $productId;
        }

        $this->rememberRun($plan->runId, $created);
        $this->audit->log(AuditEventCatalog::DOKAN_IMPORTED, $this->actorId(), 'migration', $plan->runId, [
            'vendors' => count($created['vendors']),
            'products' => count($created['products']),
            'run_id' => $plan->runId,
            'mode' => 'trial',
        ]);
        return OperationResult::success('dokan_imported', [
            'run_id' => $plan->runId,
            'vendors' => count($created['vendors']),
            'products' => count($created['products']),
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
        if ($run === null) {
            return OperationResult::failure('not_found', ['run_id' => $runId]);
        }
        $removedProducts = 0;
        $keptProducts = [];
        foreach ($run['products'] ?? [] as $productId) {
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
        ]);
    }

    /** @return array<string,array{vendors:list<int>, products:list<int>, at:string}> */
    public function runs(): array
    {
        $stored = $this->options->get(self::RUNS_OPTION, []);
        return is_array($stored) ? $stored : [];
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

    /** @param array{vendors:list<int>, products:list<int>} $created */
    private function rememberRun(string $runId, array $created): void
    {
        $runs = $this->runs();
        $runs[$runId] = [
            'vendors' => $created['vendors'],
            'products' => $created['products'],
            'at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ];
        $this->options->set(self::RUNS_OPTION, $runs);
    }

    private function actorId(): int
    {
        return $this->capabilities?->currentUserId() ?? 0;
    }
}
