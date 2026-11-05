<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontStatusReconciliation as Rule;

/**
 * The repair for `draft: 184` — this plugin's own status column, brought back
 * to what the storefront says, for rows whose status it has never written.
 *
 * ### What it is for
 *
 * The defect and its three consequences are in
 * `StorefrontStatusReconciliation`'s docblock; the short version is that an
 * intake older than `alpha.92` wrote `draft` for every imported row, and
 * nothing since brings that column back — so a shop whose products are live
 * reports every one of them as a draft, the review queue is bypassed for
 * them, and the `alpha.93` hazard stays open.
 *
 * ### Read-only until a manager presses something
 *
 * `plan()` has no write of any kind and `ReconcileStorefrontStatusIsReadOnly`
 * asserts that from this file's bytes. `apply()` is the only writer and its
 * whole write set is `reconcileStatusToStorefront()` on one column of our own
 * table plus an audit row — there is no call anywhere here to `take()`,
 * `move()`, `giveBack()`, `setLinkOwnership()`, `updateStatus()`,
 * `updateDetails()`, `updatePrice()` or any WooCommerce verb. So «قیمت،
 * موجودی، SKU، دسته‌بندی، تصویر، فروشنده، محدودیت خرید» are preserved by the
 * absence of a capability rather than by care, which is the only form of that
 * promise worth making.
 *
 * ⛔ **And it never writes to WooCommerce.** The storefront is the reference
 * here, not the subject: the owner's §3 says «وضعیت انتشار و دسته‌بندی‌های
 * موجود در ووکامرس مرجع اصلی هستند», so this reads `post_status` and writes
 * our column to match. A product that is `publish` in WooCommerce is still
 * `publish` in WooCommerce afterwards, byte for byte.
 *
 * ### The same four guards as the other two batches, deliberately
 *
 * Capability, a RE-PLAN rather than the form's word, a digest compare, and an
 * exact set match that refuses the whole batch rather than filtering it —
 * «carrying out part of something nobody asked for is not a smaller version
 * of that request» (`alpha.91`). It stops at the first failure and names the
 * product. A third shape here would be a third set of mistakes.
 *
 * And the row is read AGAIN inside the loop, so the status and the stamp
 * that go into the `WHERE` are the ones the row holds at the moment of the
 * write rather than the ones the preview printed — the preview decides WHICH
 * rows are offered, the statement decides whether each one may still move.
 */
final class ReconcileStorefrontStatus
{
    /** Rows read per page. The planner's own window, as `alpha.92`'s. */
    public const SCAN_LIMIT = 50;

    /** Rows one press may repair. */
    public const BATCH_LIMIT = 25;

    public function __construct(
        private readonly ProductRepositoryInterface $products,
        /**
         * ⛔ Nullable, because a build with no WooCommerce has no catalogue
         * to read — `CatalogueReaderInterface` is bound conditionally for
         * exactly that reason. A plan without it reads NOTHING and says so;
         * what it must never do is answer «every row agrees», which is the
         * `alpha.68` fault with a status in it.
         */
        private readonly ?CatalogueReaderInterface $reader,
        private readonly CapabilityCheckerInterface $capabilities,
        private readonly ?AuditLogger $audit = null
    ) {
    }

    /**
     * What one page of this shop's stamped rows would need, and nothing written.
     *
     * ⛔ **The cost, measured and stated rather than left to be found.** This
     * is ONE extra walk of the page the staged-transfer plan already walks:
     * one query for the rows plus one `detail()` per row, up to
     * `SCAN_LIMIT`. `detail()` is not memoised in the reader, and
     * `wc_get_product()` is cheap on a repeat (WooCommerce's instance cache,
     * `alpha.50`) while `brandOf()` is a query each time — so the honest
     * figure is «up to fifty extra reads on this one admin page». Bounded,
     * proportionate, and nothing like the 552 reads of three walks over 184
     * rows that `alpha.91` took apart; and the card is not drawn at all once
     * a page has nothing to repair, so a healthy site pays the row query and
     * stops.
     *
     * @return array{
     *     rows: list<array{product_id:int, wc_product_id:int, title:string,
     *                      row_status:string, shop_status:string,
     *                      verdict:string, target:string}>,
     *     repairable: list<int>, counts: array<string,int>,
     *     scanned: int, cursor: int, finished: bool, digest: string
     * }
     */
    public function plan(int $vendorUserId, int $afterId = 0): array
    {
        if ($this->reader === null || !$this->capabilities->can(Capabilities::REVIEW_VENDOR)) {
            return $this->emptyPlan($afterId);
        }
        $page = $this->products->stampedForVendorAfter($vendorUserId, max(0, $afterId), self::SCAN_LIMIT);
        $rows = [];
        $repairable = [];
        $counts = [];
        $cursor = max(0, $afterId);
        foreach ($page as $product) {
            $cursor = max($cursor, (int) $product->id);
            $wcId = (int) ($product->wcProductId ?? 0);
            // A read that failed and a product that is gone both come back as
            // `[]` from `detail()`, and neither is «the status agrees»
            // (`alpha.49`). Both land in `UNREADABLE`, which is reported by
            // name and never repaired.
            $detail = $wcId > 0 && $this->reader !== null ? $this->reader->detail($wcId) : [];
            $shopStatus = $detail === [] ? null : (string) ($detail['status'] ?? '');
            $verdict = Rule::decide($product->status, $product->mirroredStatus, $shopStatus);
            $counts[$verdict['verdict']] = ($counts[$verdict['verdict']] ?? 0) + 1;
            $rows[] = [
                'product_id' => (int) $product->id,
                'wc_product_id' => $wcId,
                'title' => (string) $product->details->title,
                'row_status' => $product->status->value,
                'shop_status' => $shopStatus ?? '',
                'verdict' => $verdict['verdict'],
                'target' => $verdict['target']?->value ?? '',
            ];
            if ($verdict['verdict'] === Rule::REPAIR && count($repairable) < self::BATCH_LIMIT) {
                $repairable[] = (int) $product->id;
            }
        }
        // «Finished» is derived from the page being short, not from the
        // repairable list being empty: a page of fifty rows that all agree is
        // still a page, and the walk has to carry on past it.
        return [
            'rows' => $rows,
            'repairable' => $repairable,
            'counts' => $counts,
            'scanned' => count($page),
            'cursor' => $cursor,
            'finished' => count($page) < self::SCAN_LIMIT,
            'digest' => OwnershipTransferPlan::digestOf($repairable),
        ];
    }

    /**
     * Repair the rows this page offered, in id order, stopping at the first
     * one that will not move.
     *
     * @param list<int> $productIds the ids the form carried
     * @param string $seenDigest the digest the page printed
     * @return array{ok:bool, code:string, repaired:list<int>, stopped_at:int,
     *               reason:string, requested:int, changes:list<array{product_id:int, from:string, to:string}>}
     */
    public function apply(int $vendorUserId, int $afterId, array $productIds, string $seenDigest): array
    {
        if ($this->reader === null) {
            return $this->answer(false, 'catalogue_unavailable', [], [], 0, '', count($productIds));
        }
        if (!$this->capabilities->can(Capabilities::REVIEW_VENDOR)) {
            return $this->answer(false, 'forbidden', [], [], 0, '', count($productIds));
        }
        $requested = array_values(array_unique(array_map('intval', $productIds)));
        sort($requested);
        if ($requested === []) {
            return $this->answer(false, 'nothing_selected', [], [], 0, '', 0);
        }
        $plan = $this->plan($vendorUserId, $afterId);
        $offered = array_map('intval', $plan['repairable']);
        if (!hash_equals(OwnershipTransferPlan::digestOf($offered), trim($seenDigest))) {
            return $this->answer(false, 'plan_moved', [], [], 0, '', count($requested));
        }
        sort($offered);
        if ($requested !== $offered) {
            return $this->answer(false, 'selection_not_offered', [], [], 0, '', count($requested));
        }

        $repaired = [];
        $changes = [];
        foreach ($requested as $productId) {
            $outcome = $this->repairOne($productId);
            if ($outcome['code'] !== '') {
                $this->record($vendorUserId, $repaired, $changes, $productId, $outcome['code'], count($requested));
                return $this->answer(
                    false,
                    'stopped',
                    $repaired,
                    $changes,
                    $productId,
                    $outcome['code'],
                    count($requested)
                );
            }
            $repaired[] = $productId;
            $changes[] = $outcome['change'];
        }

        $this->record($vendorUserId, $repaired, $changes, 0, '', count($requested));
        return $this->answer(true, 'status_reconciled', $repaired, $changes, 0, '', count($requested));
    }

    /**
     * One row, re-read and written under its own status and stamp.
     *
     * @return array{code:string, change:array{product_id:int, from:string, to:string}}
     */
    private function repairOne(int $productId): array
    {
        $blank = ['product_id' => $productId, 'from' => '', 'to' => ''];
        $product = $this->products->find($productId);
        if ($product === null) {
            return ['code' => 'status_row_not_found', 'change' => $blank];
        }
        $wcId = (int) ($product->wcProductId ?? 0);
        $detail = $wcId > 0 ? $this->reader->detail($wcId) : [];
        $shopStatus = $detail === [] ? null : (string) ($detail['status'] ?? '');
        $verdict = Rule::decide($product->status, $product->mirroredStatus, $shopStatus);
        if ($verdict['verdict'] !== Rule::REPAIR || $verdict['target'] === null) {
            // The row stopped needing this between the preview and the press.
            // Reported by NAME with the verdict in it, not skipped: a batch
            // that quietly did less than it offered is the shape `alpha.91`
            // refuses.
            return ['code' => 'status_' . $verdict['verdict'], 'change' => $blank];
        }
        $written = $this->products->reconcileStatusToStorefront(
            $productId,
            $verdict['target'],
            $product->status,
            (string) $product->mirroredStatus
        );
        if (!$written) {
            return ['code' => 'status_not_stored', 'change' => $blank];
        }
        return [
            'code' => '',
            'change' => [
                'product_id' => $productId,
                'from' => $product->status->value,
                'to' => $verdict['target']->value,
            ],
        ];
    }

    /** @return array{rows: list<mixed>, repairable: list<int>, counts: array<string,int>, scanned:int, cursor:int, finished:bool, digest:string} */
    private function emptyPlan(int $afterId): array
    {
        return [
            'rows' => [],
            'repairable' => [],
            'counts' => [],
            'scanned' => 0,
            'cursor' => max(0, $afterId),
            // NOT «finished»: a caller without the capability read nothing,
            // and telling it the walk is over would turn a refusal into a
            // claim about the catalogue (`alpha.68`).
            'finished' => false,
            'digest' => OwnershipTransferPlan::digestOf([]),
        ];
    }

    /**
     * @param list<int> $repaired
     * @param list<array{product_id:int, from:string, to:string}> $changes
     */
    private function record(
        int $vendorUserId,
        array $repaired,
        array $changes,
        int $stoppedAt,
        string $reason,
        int $requested
    ): void {
        $this->audit?->log(
            AuditEventCatalog::STATUS_RECONCILED,
            // WHO, and not a hardcoded nought: «همراه با تاریخچه ممیزی» is
            // about a person, and the sibling batch one file over has always
            // passed this. Reviewing my own diff against it is what found it.
            $this->capabilities->currentUserId() ?? 0,
            'vendor',
            (string) $vendorUserId,
            [
                'vendor_id' => $vendorUserId,
                'requested' => $requested,
                'repaired' => count($repaired),
                'stopped_at' => $stoppedAt,
                'reason' => $reason,
                // The before and after of every row that moved, because «قبل
                // از هر تغییر مهم، وضعیت قبلی ثبت شود» is about the value and
                // not only the count.
                'rows' => implode('، ', array_map(
                    static fn (array $c): string => $c['product_id'] . ':' . $c['from'] . '→' . $c['to'],
                    $changes
                )),
            ]
        );
    }

    /**
     * @param list<int> $repaired
     * @param list<array{product_id:int, from:string, to:string}> $changes
     * @return array{ok:bool, code:string, repaired:list<int>, stopped_at:int, reason:string, requested:int, changes:list<array{product_id:int, from:string, to:string}>}
     */
    private function answer(
        bool $ok,
        string $code,
        array $repaired,
        array $changes,
        int $stoppedAt,
        string $reason,
        int $requested
    ): array {
        return [
            'ok' => $ok,
            'code' => $code,
            'repaired' => $repaired,
            'changes' => $changes,
            'stopped_at' => $stoppedAt,
            'reason' => $reason,
            'requested' => $requested,
        ];
    }
}
