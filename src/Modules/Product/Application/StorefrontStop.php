<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;

/**
 * Takes the marketplace's OWN products out of sale, and brings back only the
 * ones that still qualify.
 *
 * This exists because "suspend the vendors first" is an instruction, and an
 * instruction is not a mechanism. Deactivating the plugin, or rolling the
 * package back, used to leave every projected product on sale in a shop whose
 * guard had just been removed — purchasable, with nothing recording the money.
 * Now deactivation runs stop(), and a manager can run it deliberately before
 * touching the package at all.
 *
 * Two properties matter more than anything else here:
 *
 *  - **Nothing is deleted.** A product goes to `draft` and out of the catalogue
 *    exactly as a suspension does, so every order that referenced it still
 *    resolves and every row on this side is untouched.
 *  - **Nothing that is not ours is read or written.** The loop is over
 *    `projected()` — marketplace rows carrying a WooCommerce link — so the
 *    shop's own products and Dokan's are never even looked at.
 *
 * And coming back is not the mirror image of going away. resume() republishes
 * only what still qualifies: the shop may trade, the product is still
 * published in the marketplace's own workflow, and it is still complete.
 * Anything else stays out of the shop and is reported by name, because a
 * reactivation that quietly puts a broken or suspended product back on sale is
 * the failure this class was written to stop.
 */
final class StorefrontStop
{
    public const REFUSED_VENDOR_STOPPED = 'vendor_stopped';
    public const REFUSED_NOT_PUBLISHED = 'not_published';
    public const REFUSED_INCOMPLETE = 'incomplete';
    public const REFUSED_STOREFRONT = 'storefront_failed';

    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly SyncCatalog $catalog,
        private readonly ProductReadiness $readiness,
        private readonly StaffAccess $access,
        private readonly StorefrontSwitch $switch,
        private readonly AuditLogger $audit
    ) {
    }

    public function isStopped(): bool
    {
        return $this->switch->isStopped();
    }

    /**
     * Every projected product out of the shop, and the flag set.
     *
     * The flag is set even when some withdrawals failed: a marketplace that
     * could not empty its shelf is precisely one that must keep refusing to
     * sell, and the purchase path reads the flag, not the shelf.
     *
     * @return array{withdrawn:int, failed:int, total:int}
     */
    public function stop(string $reason, int $actorId = 0): array
    {
        $withdrawn = 0;
        $failed = 0;
        $projected = $this->products->projected();
        foreach ($projected as $product) {
            if ($this->catalog->withdraw($product->id, $reason)->ok) {
                $withdrawn++;
                continue;
            }
            $failed++;
        }
        $this->switch->markStopped($reason, $actorId, $withdrawn);
        $this->audit->log(AuditEventCatalog::STOREFRONT_STOPPED, $actorId, 'storefront', 'marketplace', [
            'reason' => $reason,
            'withdrawn' => $withdrawn,
            'failed' => $failed,
            'total' => count($projected),
        ]);
        return ['withdrawn' => $withdrawn, 'failed' => $failed, 'total' => count($projected)];
    }

    /**
     * Back on sale — but only what still qualifies, one product at a time.
     *
     * @return array{published:int, refused:array<int,string>, total:int}
     */
    public function resume(int $actorId = 0): array
    {
        $published = 0;
        $refused = [];
        $projected = $this->products->projected();
        foreach ($projected as $product) {
            $reason = $this->refusalFor($product->id);
            if ($reason !== null) {
                $refused[$product->id] = $reason;
                continue;
            }
            if (!$this->catalog->publish($product->id)->ok) {
                $refused[$product->id] = self::REFUSED_STOREFRONT;
                continue;
            }
            $published++;
        }
        // The flag lifts even when some products were refused: the
        // marketplace may sell again, and the ones that may not are named.
        $this->switch->markResumed();
        $this->audit->log(AuditEventCatalog::STOREFRONT_RESUMED, $actorId, 'storefront', 'marketplace', [
            'published' => $published,
            'refused' => count($refused),
            'total' => count($projected),
        ]);
        return ['published' => $published, 'refused' => $refused, 'total' => count($projected)];
    }

    /**
     * Whether ONE product may go on sale right now.
     *
     * The same per-product question resume() asks, plus the marketplace-wide
     * one: while selling is stopped, nothing goes live — not a reinstated
     * vendor's catalogue, not a newly approved product. resume() does not use
     * this method, because resume() is the act of lifting that very stop.
     */
    public function mayGoLive(int $productId): bool
    {
        return !$this->switch->isStopped() && $this->refusalFor($productId) === null;
    }

    /** @return string|null the reason it may not go back, or null when it may */
    private function refusalFor(int $productId): ?string
    {
        $product = $this->products->find($productId);
        if ($product === null) {
            return self::REFUSED_NOT_PUBLISHED;
        }
        if (!$this->access->vendorCanTrade($product->vendorUserId)) {
            return self::REFUSED_VENDOR_STOPPED;
        }
        if ($product->status !== ProductStatus::Published) {
            return self::REFUSED_NOT_PUBLISHED;
        }
        // The same completeness check the vendor's submit and the manager's
        // approve run (F-10). A product that lost its last image while the
        // shop was closed does not come back just because the shop reopened.
        if (!$this->readiness->check($product)->ok) {
            return self::REFUSED_INCOMPLETE;
        }
        return null;
    }

    /** Result-shaped wrapper, for the screens that report to a person. */
    public function stopAsResult(string $reason, int $actorId = 0): OperationResult
    {
        $outcome = $this->stop($reason, $actorId);
        return OperationResult::success('storefront_stopped', $outcome);
    }

    public function resumeAsResult(int $actorId = 0): OperationResult
    {
        $outcome = $this->resume($actorId);
        return OperationResult::success('storefront_resumed', [
            'published' => $outcome['published'],
            'refused' => count($outcome['refused']),
            'total' => $outcome['total'],
        ]);
    }
}
