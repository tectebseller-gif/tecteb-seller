<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;

/**
 * One explicit confirmation, up to twenty-five rows RE-READ from their shop —
 * and not one byte written to a WooCommerce product.
 *
 * ### The number this is for
 *
 * Shop 102's hand-over screen offered «آماده انتقال: ۰» against 184 rows, and
 * the reason for most of them is an intake ARTEFACT rather than a change:
 * `ImportFromDokan` records five fields and leaves ten at `ProductDetails`'
 * defaults, so every field the shop holds and the Dokan intake never read is
 * permanent drift (`docs/dokan-exit-drift-analysis-alpha91-fa.md` §1). Those
 * rows are not in conflict with anything; our copy is simply thinner than the
 * product.
 *
 * `refreshFromStorefront()` has fixed exactly that since `alpha.56`, one
 * product at a time, which on 184 rows is 184 clicks. The owner's §2:
 * «re-snapshot دسته‌ای با سقف ۲۵ محصول … پیش‌نمایش، اثرانگشت، تأیید مدیر،
 * ممیزی و توقف هنگام خطا … فقط اطلاعات داخلی بازارگاه به‌روزرسانی شود؛ هیچ
 * تغییری روی محصولات ووکامرس انجام نشود.»
 *
 * ### Why it writes nothing to the shop, as a property rather than a promise
 *
 * Every unit goes through `ImportExistingProducts::refreshFrom()`, whose
 * whole write set is `updateDetails()` and `saveBaseline()` on our own tables
 * plus `recordMirroredStatusWhenAbsent()` on our own column — inside ONE
 * transaction, under the row's own version, re-reading the shop afterwards
 * and refusing (`intake_refresh_moved_again`) if a value moved while it
 * worked. It also refuses a row that is not `observed`, so a product this
 * marketplace already manages cannot be reverted to the shop's values by a
 * batch. `SnapshotBatchWritesNothingToTheShopTest` asserts the absent verbs
 * from this file's bytes and from that method's.
 *
 * ### What it copies from `ApplyOwnershipTransfer`, deliberately
 *
 * The same four guards, in the same order, because the two batches are the
 * same kind of act and a second shape would be a second set of mistakes:
 * capability, a RE-PLAN rather than the form's word, a digest compare, and an
 * exact set match that refuses the whole batch rather than filtering it —
 * «carrying out part of something nobody asked for is not a smaller version
 * of that request» (`alpha.91`). It stops at the first failure and names the
 * product, and the audit row is written on BOTH paths.
 *
 * ⛔ **It is not a transfer and it cannot become one.** There is no call to
 * `take()`, `move()` or `giveBack()` anywhere here: a row refreshed by this
 * batch is still `observed`, still owned by nobody, and still needs the
 * manager's separate, explicit transfer. That separation is the reason this
 * is a second button rather than a step inside the first.
 */
final class ApplyRefreshSnapshot
{
    public function __construct(
        private readonly OwnershipTransferPlan $plan,
        private readonly TransferOwnership $transfer,
        private readonly CapabilityCheckerInterface $capabilities,
        private readonly ?AuditLogger $audit = null
    ) {
    }

    /**
     * Re-read the products this page offered, in id order.
     *
     * @param list<int> $productIds the ids the form carried
     * @param string $seenDigest `OwnershipTransferPlan::digestOf()` of the
     *        refreshable list the page printed
     * @return array{ok:bool, code:string, refreshed:list<int>, stopped_at:int, reason:string, requested:int}
     */
    public function apply(int $vendorUserId, int $afterId, array $productIds, string $seenDigest): array
    {
        if (!$this->capabilities->can(Capabilities::REVIEW_VENDOR)) {
            return $this->answer(false, 'forbidden', [], 0, '', count($productIds));
        }
        $requested = array_values(array_unique(array_map('intval', $productIds)));
        sort($requested);
        if ($requested === []) {
            return $this->answer(false, 'nothing_selected', [], 0, '', 0);
        }

        // Read again, and compare against what the page printed. A price that
        // moved since the render changes which rows are refreshable, and
        // consent is about what was shown (`alpha.49`).
        $plan = $this->plan->plan($vendorUserId, $afterId);
        $offered = array_map('intval', (array) $plan['refreshable']);
        if (!hash_equals(OwnershipTransferPlan::digestOf($offered), trim($seenDigest))) {
            return $this->answer(false, 'plan_moved', [], 0, '', count($requested));
        }
        sort($offered);
        if ($requested !== $offered) {
            return $this->answer(false, 'selection_not_offered', [], 0, '', count($requested));
        }

        $refreshed = [];
        foreach ($requested as $productId) {
            $result = $this->transfer->refreshFromStorefront($productId);
            if (!$result->ok) {
                $this->record($vendorUserId, $refreshed, $productId, $result->code, count($requested));
                return $this->answer(false, 'stopped', $refreshed, $productId, $result->code, count($requested));
            }
            $refreshed[] = $productId;
        }

        $this->record($vendorUserId, $refreshed, 0, '', count($requested));
        return $this->answer(true, 'snapshot_batch_refreshed', $refreshed, 0, '', count($requested));
    }

    /**
     * @param list<int> $refreshed
     * @return array{ok:bool, code:string, refreshed:list<int>, stopped_at:int, reason:string, requested:int}
     */
    private function answer(
        bool $ok,
        string $code,
        array $refreshed,
        int $stoppedAt,
        string $reason,
        int $requested
    ): array {
        return [
            'ok' => $ok,
            'code' => $code,
            'refreshed' => $refreshed,
            'stopped_at' => $stoppedAt,
            'reason' => $reason,
            'requested' => $requested,
        ];
    }

    /**
     * One batch row, written whether the batch finished or stopped.
     *
     * The per-product rows are already there from `refreshFrom()`, which logs
     * `CATALOGUE_INTAKE` for each; this one says a human started a batch and
     * where it got to — the shape `ApplyOwnershipTransfer` uses, for the same
     * reason.
     *
     * @param list<int> $refreshed
     */
    private function record(int $vendorUserId, array $refreshed, int $stoppedAt, string $reason, int $requested): void
    {
        $this->audit?->log(
            AuditEventCatalog::CATALOGUE_SNAPSHOT_BATCH,
            $this->capabilities->currentUserId() ?? 0,
            'vendor',
            (string) $vendorUserId,
            [
                'vendor_id' => $vendorUserId,
                'requested' => $requested,
                'refreshed' => count($refreshed),
                'stopped_at' => $stoppedAt,
                'reason' => $reason,
            ]
        );
    }
}
