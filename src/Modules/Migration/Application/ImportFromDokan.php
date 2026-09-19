<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\OptionStoreInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Finance\Application\LedgerRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductImageLibraryInterface;
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
        private readonly ?OrderHistoryRepositoryInterface $history = null,
        /**
         * The rest of a shop's past: staff, balance ledger, withdrawals.
         * Optional for the same reason as `$history` — an install without
         * schema 17 still loads, and the plan says so rather than pretending
         * it imported something it could not store.
         */
        private readonly ?ShopRecordRepositoryInterface $shopRecords = null,
        /**
         * Asked one question and one only: «does our own ledger already
         * account for this order?» FIN-02 says one financial engine per order,
         * and a rule nothing can check is a wish.
         */
        private readonly ?LedgerRepositoryInterface $ledger = null,
        // Optional: without it nothing is mapped and every product keeps an
        // empty category, which is the old behaviour rather than a wrong new
        // one.
        private readonly ?CategoryMap $categories = null,
        // Optional: without it a migrated product keeps no picture, which is
        // the old behaviour rather than a wrong new one.
        private readonly ?ProductImageLibraryInterface $images = null
    ) {
    }

    /**
     * Import one page of a shop's staff, balance ledger and withdrawals.
     *
     * Records, all three — Dokan's own figures, no rate applied, no ledger
     * line written. A balance is the clearest case: a shop that earned under a
     * 5% commission and is now on 8% must still see the number Dokan recorded,
     * so the amounts travel as strings straight into `DECIMAL(19,4)` columns
     * and nothing here multiplies anything.
     *
     * @return array{staff:int, balance:int, withdrawals:int, failed:int, last_balance:int, last_withdraw:int, more:bool}
     */
    public function importShopRecordPage(string $runId, int $afterBalanceId, int $afterWithdrawId, int $limit): array
    {
        $done = ['staff' => 0, 'balance' => 0, 'withdrawals' => 0, 'failed' => 0];
        if ($this->shopRecords === null) {
            return $done + ['last_balance' => $afterBalanceId, 'last_withdraw' => $afterWithdrawId, 'more' => false];
        }

        // Staff first, and only on the first page: a shop has a handful of
        // them, and paging a handful is machinery with nothing to do.
        if ($afterBalanceId === 0 && $afterWithdrawId === 0) {
            foreach ($this->vendors->idsFromImportRun($runId) ?: $this->dokanVendorIds() as $vendorUserId) {
                foreach ($this->dokan->staffFor((int) $vendorUserId) as $member) {
                    $this->count($done, 'staff', $this->shopRecords->recordStaff($runId, $member));
                }
            }
        }

        $balanceRows = $this->dokan->balanceRowsAfter($afterBalanceId, $limit);
        $lastBalance = $afterBalanceId;
        foreach ($balanceRows as $row) {
            $lastBalance = max($lastBalance, (int) ($row['row_id'] ?? $row['trn_id']));
            $this->count($done, 'balance', $this->shopRecords->recordBalance($runId, $row));
        }

        $withdrawRows = $this->dokan->withdrawalsAfter($afterWithdrawId, $limit);
        $lastWithdraw = $afterWithdrawId;
        foreach ($withdrawRows as $row) {
            $lastWithdraw = max($lastWithdraw, (int) $row['withdraw_id']);
            $this->count($done, 'withdrawals', $this->shopRecords->recordWithdrawal($runId, $row));
        }

        return $done + [
            'last_balance' => $lastBalance,
            'last_withdraw' => $lastWithdraw,
            'more' => count($balanceRows) >= $limit || count($withdrawRows) >= $limit,
        ];
    }

    /** What a shop's imported past adds up to, in Dokan's own figures. */
    public function shopRecordsFor(int $vendorUserId): array
    {
        if ($this->shopRecords === null) {
            return ['available' => false, 'staff' => 0, 'balance_rows' => 0, 'debit' => '0', 'credit' => '0', 'withdrawals' => 0, 'withdrawn' => '0'];
        }
        return $this->shopRecords->summaryForVendor($vendorUserId) + ['available' => true];
    }

    /**
     * Whether a past order may be recorded as history at all.
     *
     * FIN-02: one financial engine per order. If this marketplace's ledger
     * already carries lines for it — because the order was placed here, or
     * because somebody transferred it — then Dokan's figures for the same
     * order would be a second, contradictory answer to «what is this worth»,
     * with nothing to say which is authoritative. So it is refused and named,
     * not written and reconciled later.
     */
    public function orderIsAlreadyOurs(int $wcOrderId): bool
    {
        return $this->ledger?->coversOrder($wcOrderId) ?? false;
    }

    /** @param array<string,int> $tally */
    private function count(array &$tally, string $key, string $outcome): void
    {
        match ($outcome) {
            ShopRecordRepositoryInterface::RECORDED => $tally[$key]++,
            ShopRecordRepositoryInterface::FAILED => $tally['failed']++,
            default => null,                    // already there: a re-run
        };
    }

    /** @return list<int> */
    private function dokanVendorIds(): array
    {
        return array_map(static fn (array $v): int => (int) $v['user_id'], $this->dokan->vendors());
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
            // Stamped IN the insert, like the products: the profile that was
            // created IS the record that this run created it, and there is no
            // second write for a crash to fall between.
            $this->vendors->upsertProfile(
                $vendor['user_id'],
                $vendor['store_name'],
                false,
                false,
                $plan->runId
            );
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
                    // Mapped, or empty. Empty keeps the product a draft,
                    // which is the visible «somebody must decide this» —
                    // see `CategoryMap`.
                    categoryKey: $this->categories?->marketplaceCategoryFor(
                        (string) ($product['source_category_key'] ?? '')
                    ) ?? '',
                    priceMinor: $product['price_minor'],
                    sku: $product['sku'],
                    // Never below zero.
                    //
                    // Dokan permits backorders, so a live shop really does
                    // carry products at `-2`, and this marketplace's own
                    // validation refuses a negative stock — every such
                    // product would arrive and then be unsaveable, which is
                    // the worst of both. Zero is the conservative reading of
                    // «somebody already sold more than they had»: none
                    // available, nothing oversold twice. The count is
                    // reported, so it is a number the owner sees rather than
                    // a correction nobody was told about.
                    stock: max(0, (int) $product['stock'])
                ),
                // Draft, always. Publishing would write to a post Dokan
                // manages, and that is a decision, not an import step.
                ProductStatus::Draft,
                // OBSERVED, always. The row knows which WooCommerce product it
                // corresponds to and nothing else follows: the purchase guard,
                // the storefront stop and the projector all skip it. Without
                // this, a dry-run import made Dokan's own vendor product
                // unpurchasable — measured, and the reason this value exists.
                LinkOwnership::Observed,
                // Linked to the EXISTING WooCommerce product — in the SAME
                // insert, so the id and the URL a customer bookmarked keep
                // working AND a process killed here cannot leave a row whose
                // link is missing. An unlinked row is invisible to the resume
                // lookup, which then makes a second one.
                (int) $product['wc_product_id'],
                // And the same stamp the paged path writes, in that one
                // statement too. Two import paths that record their origin
                // differently is two paths for a rollback to miss.
                $plan->runId
            );
            if ($productId === 0) {
                continue;
            }
            // The picture the product already has.
            //
            // Unlike the CATEGORY — two vocabularies, no reliable
            // correspondence, so a mapping the owner writes — the featured
            // image needs no decision at all: it is the same photograph of
            // the same product, hanging on the very WooCommerce post this row
            // now points at. Carrying it is not a guess; leaving it behind
            // would just make every migrated product fail readiness with
            // `missing_image` and wait for somebody to re-upload a file that
            // is already there.
            //
            // A SECOND write, deliberately, and safe in a way the run stamp
            // was not: a row that lost its image between the two is still
            // findable, still stamped, still rolled back with its run — and
            // it says `missing_image` out loud instead of turning into a
            // duplicate on the next pass.
            $sourceImageId = (int) ($product['source_image_id'] ?? 0);
            if ($sourceImageId > 0 && $this->images !== null) {
                // COPIED into the vendor's own media, not referenced.
                //
                // The gallery only keeps images the vendor owns, and the
                // source attachment belongs to whoever uploaded it in the old
                // system — so a reference survives the import and then
                // vanishes the first time the vendor saves the product, which
                // is the worst possible moment to lose it. Measured: the
                // picture arrived, the vendor filled in the specs, and the
                // save dropped it again.
                $ownCopy = $this->images->duplicateForVendor($sourceImageId, (int) $product['vendor_user_id']);
                if ($ownCopy > 0) {
                    $this->products->saveImages($productId, [$ownCopy], $ownCopy);
                }
            }
            $created['products'][] = $productId;
        }

        // The history, through the same recorder the job uses. Two import
        // paths that write history differently is two paths for a rollback to
        // miss — the same lesson the run stamp just taught.
        $historyRecorded = 0;
        $historyFailed = 0;
        $historyRefused = 0;
        if ($this->history !== null) {
            foreach ($this->dokan->orders() as $order) {
                // FIN-02, enforced rather than asserted. An order our own
                // ledger already carries must not also get Dokan's figures:
                // two answers to «what is this worth» and nothing to say
                // which one settlement should believe.
                if ($this->orderIsAlreadyOurs((int) $order['wc_order_id'])) {
                    $historyRefused++;
                    continue;
                }
                match ($this->history->record($plan->runId, $order)) {
                    OrderHistoryRepositoryInterface::RECORDED => $historyRecorded++,
                    OrderHistoryRepositoryInterface::FAILED => $historyFailed++,
                    default => null,            // already there: a re-run, not a fault
                };
            }
        }

        $this->rememberRun($plan->runId, $created);
        $this->audit->log(AuditEventCatalog::DOKAN_IMPORTED, $this->actorId(), 'migration', $plan->runId, [
            'vendors' => count($created['vendors']),
            'products' => count($created['products']),
            'history' => $historyRecorded,
            // Counted separately and never folded into the total: a run that
            // reports «۶ سفارش وارد شد» while two of them failed is a run
            // somebody will trust.
            'history_failed' => $historyFailed,
            // Named separately from «failed»: an order this marketplace
            // already owns is not a fault, it is the rule working.
            'history_already_ours' => $historyRefused,
            'run_id' => $plan->runId,
            'mode' => 'trial',
        ]);
        return OperationResult::success('dokan_imported', [
            'run_id' => $plan->runId,
            'vendors' => count($created['vendors']),
            'products' => count($created['products']),
            'history' => $historyRecorded,
            'history_already_ours' => $historyRefused,
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
                // must not take it away silently. It stops carrying the run
                // for the same reason: the run is over, and the row is ours.
                $keptProducts[] = $productId;
                $this->products->stampImportRun($productId, '');
                continue;
            }
            if ($this->products->deleteDraft($productId)) {
                $removedProducts++;
                continue;
            }
            // Not deleted — a published row is a record of something and
            // `deleteDraft()` refuses it — but no longer this run's either.
            // A row a rollback leaves behind still carrying the run id makes
            // `runs()` resurrect a run that has been undone and offer to undo
            // it again. It is NOT added to `keptProducts`: that list is the
            // narrower «somebody took ownership» case, which is a failure of
            // the rollback, and widening it here would turn an ordinary
            // publish into one.
            $this->products->stampImportRun($productId, '');
        }
        // The history goes with the products. A rollback that removed the
        // catalogue but left the past orders would leave a shop half-migrated
        // with no way back — and the next import would find its own rows there.
        $removedHistory = $this->history?->deleteRun($runId) ?? 0;
        // The staff, balance and withdrawal records go with them. A rollback
        // that removed the catalogue and left a shop's balance history behind
        // would show figures for an import that no longer exists.
        $removedRecords = $this->shopRecords?->deleteRun($runId) ?? ['staff' => 0, 'balance' => 0, 'withdrawals' => 0];

        // The same union on the vendor side. `0014` stamped products only, and
        // the first evidence run with the manifest deleted removed the
        // products and left the shop standing, reporting «vendors=0» about a
        // profile that was plainly there. Half a fix is not a fix.
        $vendorIds = array_values(array_unique(array_merge(
            array_map('intval', $run['vendors'] ?? []),
            $this->vendors->idsFromImportRun($runId)
        )));
        sort($vendorIds);

        $removedVendors = 0;
        foreach ($vendorIds as $vendorUserId) {
            // The PROFILE goes; the WordPress user does not. The user was
            // Dokan's before this ran and is Dokan's after it.
            if ($this->vendors->deleteEmptyProfile((int) $vendorUserId)) {
                $removedVendors++;
                continue;
            }
            // The shop stays — it has an application of its own, or products
            // that are not this run's. The run does not: clearing the stamp is
            // what makes «the run is gone» true of the rows as well as of the
            // manifest.
            $this->vendors->stampImportRun((int) $vendorUserId, '');
        }
        unset($runs[$runId]);
        $this->options->set(self::RUNS_OPTION, $runs);
        $this->audit->log(AuditEventCatalog::DOKAN_ROLLED_BACK, $this->actorId(), 'migration', $runId, [
            'vendors' => $removedVendors,
            'products' => $removedProducts,
            'history' => $removedHistory,
            'staff' => $removedRecords['staff'],
            'balance' => $removedRecords['balance'],
            'withdrawals' => $removedRecords['withdrawals'],
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
            'staff' => $removedRecords['staff'],
            'balance' => $removedRecords['balance'],
            'withdrawals' => $removedRecords['withdrawals'],
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
        // Both tables, because a run killed between the vendor loop and the
        // product loop created a shop and no products at all — and asking
        // only the products would call that run nonexistent.
        $stampedRuns = array_values(array_unique(array_merge(
            $this->products->importRunIds(),
            $this->vendors->importRunIds()
        )));
        rsort($stampedRuns);
        foreach ($stampedRuns as $runId) {
            if (isset($runs[$runId])) {
                continue;
            }
            $runs[$runId] = [
                'vendors' => $this->vendors->idsFromImportRun($runId),
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
            $this->vendors->upsertProfile($last, $vendor['store_name'], false, false, $runId);
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
                    // Mapped, or empty. Empty keeps the product a draft,
                    // which is the visible «somebody must decide this» —
                    // see `CategoryMap`.
                    categoryKey: $this->categories?->marketplaceCategoryFor(
                        (string) ($product['source_category_key'] ?? '')
                    ) ?? '',
                    priceMinor: $product['price_minor'],
                    sku: $product['sku'],
                    // Never below zero.
                    //
                    // Dokan permits backorders, so a live shop really does
                    // carry products at `-2`, and this marketplace's own
                    // validation refuses a negative stock — every such
                    // product would arrive and then be unsaveable, which is
                    // the worst of both. Zero is the conservative reading of
                    // «somebody already sold more than they had»: none
                    // available, nothing oversold twice. The count is
                    // reported, so it is a number the owner sees rather than
                    // a correction nobody was told about.
                    stock: max(0, (int) $product['stock'])
                ),
                ProductStatus::Draft,
                LinkOwnership::Observed,
                // One statement: link and stamp included. The row that exists
                // is complete, carries the run that made it, and is findable
                // by its WooCommerce id — so a resumed page skips it instead
                // of creating a second one.
                (int) $product['wc_product_id'],
                $runId
            );
            if ($productId === 0) {
                $failed++;
                $notes[] = $last . ':create_failed';
                continue;
            }
            // The picture the product already has.
            //
            // Unlike the CATEGORY — two vocabularies, no reliable
            // correspondence, so a mapping the owner writes — the featured
            // image needs no decision at all: it is the same photograph of
            // the same product, hanging on the very WooCommerce post this row
            // now points at. Carrying it is not a guess; leaving it behind
            // would just make every migrated product fail readiness with
            // `missing_image` and wait for somebody to re-upload a file that
            // is already there.
            //
            // A SECOND write, deliberately, and safe in a way the run stamp
            // was not: a row that lost its image between the two is still
            // findable, still stamped, still rolled back with its run — and
            // it says `missing_image` out loud instead of turning into a
            // duplicate on the next pass.
            $sourceImageId = (int) ($product['source_image_id'] ?? 0);
            if ($sourceImageId > 0 && $this->images !== null) {
                // COPIED into the vendor's own media, not referenced.
                //
                // The gallery only keeps images the vendor owns, and the
                // source attachment belongs to whoever uploaded it in the old
                // system — so a reference survives the import and then
                // vanishes the first time the vendor saves the product, which
                // is the worst possible moment to lose it. Measured: the
                // picture arrived, the vendor filled in the specs, and the
                // save dropped it again.
                $ownCopy = $this->images->duplicateForVendor($sourceImageId, (int) $product['vendor_user_id']);
                if ($ownCopy > 0) {
                    $this->products->saveImages($productId, [$ownCopy], $ownCopy);
                }
            }
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
            return ['done' => 0, 'skipped' => 0, 'failed' => 0, 'already_ours' => 0, 'last' => $afterOrderId, 'more' => false];
        }
        $page = $this->dokan->ordersAfter($afterOrderId, $limit);
        $last = $afterOrderId;
        $done = 0;
        $skipped = 0;
        $failed = 0;
        $ours = 0;
        foreach ($page as $order) {
            $last = max($last, (int) $order['wc_order_id']);
            // «already there» is a resumed job re-running its last page, which
            // is normal. «failed» is not, and counting it as the first would
            // report a shop as migrated on the strength of writes that never
            // landed.
            if ($this->orderIsAlreadyOurs((int) $order['wc_order_id'])) {
                $ours++;
                continue;
            }
            match ($this->history->record($runId, $order)) {
                OrderHistoryRepositoryInterface::RECORDED => $done++,
                OrderHistoryRepositoryInterface::ALREADY => $skipped++,
                default => $failed++,
            };
        }
        return [
            'done' => $done,
            'skipped' => $skipped,
            'failed' => $failed,
            'already_ours' => $ours,
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
