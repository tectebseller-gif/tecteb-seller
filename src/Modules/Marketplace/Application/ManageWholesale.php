<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Marketplace\Domain\PriceTier;
use Tecteb\Marketplace\Modules\Marketplace\Domain\WholesaleAccount;
use Tecteb\Marketplace\Modules\Marketplace\Domain\WholesaleStatus;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffArea;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffLevel;

/**
 * Wholesale: who may see a ladder, and what the ladder says.
 *
 * The approved sentence is «خریدار عمده پس از تأیید مدیر قیمت پلکانی و حداقل
 * تعداد را می‌بیند», and it splits cleanly in two:
 *
 *  - **Who** is a manager's decision, recorded per buyer. A buyer who has only
 *    asked, or was rejected or suspended, sees the ordinary price — not a
 *    hidden one, not a crossed-out one. `priceFor()` simply does not consult
 *    the ladder for them.
 *  - **What** is the vendor's own data: each step is «from this many, each one
 *    costs this much», written by the shop for its own product.
 *
 * The marketplace has no tariff and no minimum of its own, because DEC-05
 * still needs «تعرفه/حداقل عمده». So there is no default step, no floor on the
 * discount and no cap: a shop with no ladder simply has no wholesale price,
 * which is a true statement rather than an invented one.
 */
final class ManageWholesale
{
    /** What DEC-05 has not fixed, named where a screen can show it. */
    public const OPEN_TERMS = ['wholesale_tariff_undecided', 'wholesale_minimum_undecided'];

    public const DECISION = 'DEC-05';

    public function __construct(
        private readonly EngagementRepositoryInterface $repository,
        private readonly ProductRepositoryInterface $products,
        private readonly StaffAccess $access,
        private readonly AuditLogger $audit,
        private readonly ?CapabilityCheckerInterface $capabilities = null
    ) {
    }

    /** A buyer asking. Decides nothing; a manager does that. */
    public function apply(int $userId, string $company, string $registrationId, string $note = ''): OperationResult
    {
        if ($userId <= 0) {
            return OperationResult::failure('not_found');
        }
        $existing = $this->repository->findWholesaleAccount($userId);
        if ($existing !== null) {
            return OperationResult::success('wholesale_already_applied', [
                'status' => $existing->status->value,
            ]);
        }
        $id = $this->repository->upsertWholesaleAccount(new WholesaleAccount(
            0,
            $userId,
            WholesaleStatus::Requested,
            trim($company),
            trim($registrationId),
            trim($note)
        ));
        if ($id === 0) {
            return OperationResult::failure('storage_failed');
        }
        $this->audit->log(AuditEventCatalog::WHOLESALE_APPLIED, $userId, 'wholesale', (string) $userId, [
            'user_id' => $userId,
            'has_registration' => trim($registrationId) !== '',
        ]);
        return OperationResult::success('wholesale_applied', ['account_id' => $id]);
    }

    /** The manager's decision. Nothing else moves a wholesale account. */
    public function decide(int $userId, WholesaleStatus $status, string $note = ''): OperationResult
    {
        if ($this->capabilities === null || !$this->capabilities->can(Capabilities::REVIEW_VENDOR)) {
            return OperationResult::failure('forbidden');
        }
        $account = $this->repository->findWholesaleAccount($userId);
        if ($account === null) {
            return OperationResult::failure('not_found');
        }
        $actorId = $this->capabilities->currentUserId() ?? 0;
        if (!$this->repository->setWholesaleStatus($userId, $status, $actorId, trim($note))) {
            return OperationResult::failure('storage_failed');
        }
        $this->audit->log(AuditEventCatalog::WHOLESALE_DECIDED, $actorId, 'wholesale', (string) $userId, [
            'user_id' => $userId,
            'from' => $account->status->value,
            'to' => $status->value,
        ]);
        return OperationResult::success('wholesale_' . $status->value, ['user_id' => $userId]);
    }

    /** @return list<WholesaleAccount> */
    public function queue(?WholesaleStatus $status = null): array
    {
        if ($this->capabilities === null || !$this->capabilities->can(Capabilities::REVIEW_VENDOR)) {
            return [];
        }
        return $this->repository->wholesaleAccounts($status);
    }

    public function mayBuyWholesale(int $userId): bool
    {
        return $userId > 0
            && ($this->repository->findWholesaleAccount($userId)?->status->maySeeWholesalePrices() ?? false);
    }

    /**
     * Replaces a product's whole ladder.
     *
     * Whole, not step by step, because the steps only mean anything together:
     * an edit that left «from 10» behind while «from 5» was rewritten would
     * price a basket of 12 off a step nobody meant to keep.
     *
     * @param array<int,int> $steps min quantity => unit price in minor units
     */
    public function setTiers(int $actorId, int $vendorUserId, int $productId, array $steps): OperationResult
    {
        if (!$this->access->can($actorId, $vendorUserId, StaffArea::Product, StaffLevel::Edit)) {
            return OperationResult::failure('forbidden');
        }
        $product = $this->products->find($productId);
        if ($product === null || $product->vendorUserId !== $vendorUserId) {
            return OperationResult::failure('not_found');
        }
        ksort($steps);
        $tiers = [];
        $previousPrice = null;
        foreach ($steps as $minQuantity => $unitPrice) {
            $minQuantity = (int) $minQuantity;
            $unitPrice = (int) $unitPrice;
            if ($minQuantity < 2) {
                // A "wholesale" step of one is the ordinary price with another
                // name, and it would make every single purchase a tier lookup.
                return OperationResult::failure('tier_quantity_invalid', ['min_quantity' => $minQuantity]);
            }
            if ($unitPrice <= 0 || $unitPrice > $product->details->priceMinor) {
                return OperationResult::failure('tier_price_invalid', ['min_quantity' => $minQuantity]);
            }
            if ($previousPrice !== null && $unitPrice >= $previousPrice) {
                // Buying more has to cost less per unit, or the ladder is not
                // a ladder and a shopper sees a "discount" that is not one.
                return OperationResult::failure('tier_not_descending', ['min_quantity' => $minQuantity]);
            }
            $previousPrice = $unitPrice;
            $tiers[] = new PriceTier(0, $productId, $vendorUserId, $minQuantity, $unitPrice);
        }
        if (!$this->repository->replaceTiers($productId, $vendorUserId, $tiers)) {
            return OperationResult::failure('storage_failed');
        }
        $this->audit->log(AuditEventCatalog::WHOLESALE_TIERS_SET, $actorId, 'product', (string) $productId, [
            'vendor_id' => $vendorUserId,
            'product_id' => $productId,
            'steps' => count($tiers),
        ]);
        return OperationResult::success('wholesale_tiers_saved', [
            'product_id' => $productId,
            'steps' => count($tiers),
        ]);
    }

    /** @return list<PriceTier> */
    public function tiersFor(int $productId): array
    {
        return $this->repository->tiersFor($productId);
    }

    /**
     * The unit price this buyer pays for this many.
     *
     * Returns null when nothing applies — no approval, no ladder, or too few —
     * so the caller uses the ordinary price rather than a "wholesale price"
     * that is the same number.
     */
    public function priceFor(int $userId, int $productId, int $quantity): ?int
    {
        if ($quantity < 2 || !$this->mayBuyWholesale($userId)) {
            return null;
        }
        $price = null;
        foreach ($this->repository->tiersFor($productId) as $tier) {
            if ($tier->appliesTo($quantity)) {
                $price = $tier->unitPriceMinor;      // tiers come back ascending
            }
        }
        return $price;
    }
}
