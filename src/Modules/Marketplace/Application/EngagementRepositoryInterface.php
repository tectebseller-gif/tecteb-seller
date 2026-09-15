<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Application;

use Tecteb\Marketplace\Modules\Marketplace\Domain\Coupon;
use Tecteb\Marketplace\Modules\Marketplace\Domain\PriceTier;
use Tecteb\Marketplace\Modules\Marketplace\Domain\Ticket;
use Tecteb\Marketplace\Modules\Marketplace\Domain\TicketMessage;
use Tecteb\Marketplace\Modules\Marketplace\Domain\WholesaleAccount;
use Tecteb\Marketplace\Modules\Marketplace\Domain\WholesaleStatus;

/**
 * Coupons, wholesale accounts, price tiers and tickets — one interface,
 * because they share a table group and a lifetime, and splitting them into
 * four would mean four bindings that always appear together.
 */
interface EngagementRepositoryInterface
{
    // --- coupons ---
    /** @return int row id, 0 when the code is taken or the write failed */
    public function createCoupon(Coupon $coupon): int;

    public function findCoupon(int $id): ?Coupon;

    public function findCouponByCode(string $code): ?Coupon;

    /** @return list<Coupon> */
    public function couponsForVendor(int $vendorUserId, int $limit = 100): array;

    public function setCouponStatus(int $id, string $status): bool;

    /**
     * Records that one order consumed this coupon.
     *
     * False when this order already used it: the unique key on
     * `(coupon_id, wc_order_id)` decides, so a retried checkout callback
     * cannot count one use twice.
     */
    public function recordCouponUse(int $couponId, int $wcOrderId, int $customerUserId, int $amountMinor): bool;

    public function couponUseCount(int $couponId, int $customerUserId = 0): int;

    // --- wholesale ---
    /** @return int row id, 0 on failure; the existing id when already applied */
    public function upsertWholesaleAccount(WholesaleAccount $account): int;

    public function findWholesaleAccount(int $userId): ?WholesaleAccount;

    /** @return list<WholesaleAccount> */
    public function wholesaleAccounts(?WholesaleStatus $status = null, int $limit = 100): array;

    public function setWholesaleStatus(int $userId, WholesaleStatus $status, int $actorId, string $note): bool;

    // --- price tiers ---
    /** Replaces this product's whole ladder in one go; [] clears it. @param list<PriceTier> $tiers */
    public function replaceTiers(int $productId, int $vendorUserId, array $tiers): bool;

    /** @return list<PriceTier> cheapest step first */
    public function tiersFor(int $productId): array;

    // --- tickets ---
    /** @return int row id, 0 on failure */
    public function openTicket(Ticket $ticket): int;

    public function findTicket(int $id): ?Ticket;

    /** @return list<Ticket> */
    public function ticketsForVendor(int $vendorUserId, int $limit = 100): array;

    /** @return list<Ticket> every shop's, for the marketplace's queue */
    public function allTickets(?string $status = null, int $limit = 100): array;

    public function countTickets(?string $status = null, int $vendorUserId = 0): int;

    /** @return int row id, 0 on failure */
    public function addTicketMessage(TicketMessage $message): int;

    /** @return list<TicketMessage> oldest first */
    public function ticketMessages(int $ticketId): array;

    public function setTicketState(int $id, string $status, bool $locked, ?string $lastReplyAt, string $lastReplyRole): bool;

    public function hideTicketMessage(int $messageId, int $actorId, string $reason): bool;
}
