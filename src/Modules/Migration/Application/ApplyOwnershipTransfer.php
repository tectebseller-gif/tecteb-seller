<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;

/**
 * One explicit manager action that moves ownership of SEVERAL products — and
 * stops at the first one that will not move.
 *
 * ### What the owner asked for, clause by clause
 *
 * «بدون انتقال خودکار» — there is no cron, no queue job and no hook that
 * reaches this class. One POST, one capability, one nonce, one batch.
 *
 * «با پیش‌نمایش» — `OwnershipTransferPlan` draws the page, has no write
 * method at all, and the form carries the digest of the exact ordered id list
 * it printed.
 *
 * «تأیید صریح مدیر برای هر دسته عملیات» — each batch is its own submission.
 * A second batch is a second click.
 *
 * «ثبت ممیزی» — every product writes its own `migration.ownership_changed`
 * row inside `TransferOwnership`, and the batch writes one more saying how
 * many moved and where it stopped. Two levels on purpose: the per-product
 * rows are what an auditor follows, and the batch row is what says a human
 * pressed something.
 *
 * «امکان توقف در اولین خطا» — the loop returns on the first failure, names
 * the product and the reason, and reports the ids that did move. Nothing is
 * retried and nothing is skipped: a batch that keeps going past a failure
 * would make «۱۸ از ۲۵ منتقل شد» a sentence nobody could act on.
 *
 * ### Why this cannot move a product the single button would refuse
 *
 * ⛔ **It passes EMPTY acknowledgement and EMPTY `seenShop` arrays.** Those
 * are the only two ways `TransferOwnership::take()` lets a preservation risk
 * or a storefront drift through, so a row carrying either is refused there —
 * by the guard that already exists, not by this class's bucketing being
 * right. That is the safety argument, and it holds even if the planner is
 * wrong: the worst a mis-bucketed row can do is make the batch STOP.
 *
 * And the plan is recomputed here rather than trusted from the form
 * (`alpha.81`): the page listed its rows from an earlier read, and by the time
 * the form arrives a price may have moved. If the page's ready list is no
 * longer the list this read produces, the whole batch is refused with
 * `plan_moved` — consent is about what was printed (`alpha.49`).
 */
final class ApplyOwnershipTransfer
{
    public function __construct(
        private readonly OwnershipTransferPlan $plan,
        private readonly TransferOwnership $transfer,
        private readonly CapabilityCheckerInterface $capabilities,
        private readonly ?AuditLogger $audit = null
    ) {
    }

    /**
     * Move ownership of the products this page offered, in id order.
     *
     * @param list<int> $productIds the ids the form carried
     * @param string $seenDigest `OwnershipTransferPlan::digestOf()` of the
     *        ready list the page printed
     * @return array{ok:bool, code:string, moved:list<int>, stopped_at:int, reason:string, requested:int}
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

        // Read again, and compare against what the page printed.
        $plan = $this->plan->plan($vendorUserId, $afterId);
        $ready = array_map('intval', (array) $plan['ready']);
        if (!hash_equals(OwnershipTransferPlan::digestOf($ready), trim($seenDigest))) {
            return $this->answer(false, 'plan_moved', [], 0, '', count($requested));
        }
        // ⛔ And the submitted set has to BE that list. A form carrying an id
        // the page did not offer is not a stale form, it is a different
        // request — so it is refused whole rather than filtered down, because
        // filtering would carry out part of something nobody asked for.
        $offered = $ready;
        sort($offered);
        if ($requested !== $offered) {
            return $this->answer(false, 'selection_not_offered', [], 0, '', count($requested));
        }

        $moved = [];
        foreach ($requested as $productId) {
            // Empty arrays, deliberately: see the class docblock. A risk or a
            // drift that appeared since the plan is refused HERE, by the
            // existing guard, and the batch stops.
            $result = $this->transfer->take($productId, [], []);
            if (!$result->ok) {
                $this->record($vendorUserId, $moved, $productId, $result->code, count($requested));
                return $this->answer(false, 'stopped', $moved, $productId, $result->code, count($requested));
            }
            $moved[] = $productId;
        }

        $this->record($vendorUserId, $moved, 0, '', count($requested));
        return $this->answer(true, 'ownership_batch_taken', $moved, 0, '', count($requested));
    }

    /**
     * @param list<int> $moved
     * @return array{ok:bool, code:string, moved:list<int>, stopped_at:int, reason:string, requested:int}
     */
    private function answer(bool $ok, string $code, array $moved, int $stoppedAt, string $reason, int $requested): array
    {
        return [
            'ok' => $ok,
            'code' => $code,
            'moved' => $moved,
            'stopped_at' => $stoppedAt,
            'reason' => $reason,
            'requested' => $requested,
        ];
    }

    /**
     * One batch row, written whether the batch finished or stopped.
     *
     * ⛔ Written on the failure path TOO, and that is the point: a batch that
     * moved eleven products and stopped on the twelfth has changed the
     * catalogue, and a trail that only recorded completed batches would have
     * nothing to say about it. The per-product rows are already there from
     * `TransferOwnership`; this one says a human started it and where it got
     * to.
     *
     * @param list<int> $moved
     */
    private function record(int $vendorUserId, array $moved, int $stoppedAt, string $reason, int $requested): void
    {
        $this->audit?->log(
            AuditEventCatalog::DOKAN_OWNERSHIP_BATCH,
            $this->capabilities->currentUserId() ?? 0,
            'vendor',
            (string) $vendorUserId,
            [
                'vendor_id' => $vendorUserId,
                'requested' => $requested,
                'moved' => count($moved),
                'stopped_at' => $stoppedAt,
                'reason' => $reason,
            ]
        );
    }
}
