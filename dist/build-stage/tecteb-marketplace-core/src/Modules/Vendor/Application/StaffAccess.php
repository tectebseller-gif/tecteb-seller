<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Application;

use Tecteb\Marketplace\Modules\Vendor\Domain\StaffArea;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffLevel;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffStatus;

/**
 * The single answer to "may this user do X in store Y".
 *
 * Every later stage — products, orders, finance — asks THIS, never the staff
 * table directly. Three rules from Master Spec §3.1 and AC-PRIV live here and
 * nowhere else:
 *
 *  - the owner of a store may do everything in it, and nothing in another;
 *  - a staff member's rights are derived from the store they belong to, so a
 *    membership in store A can never answer a question about store B;
 *  - suspension takes effect immediately — not at next login, not after a
 *    cache expires — so the status is read on every question.
 */
final class StaffAccess
{
    public function __construct(
        private readonly StaffRepositoryInterface $staff,
        private readonly VendorRepositoryInterface $vendors
    ) {
    }

    /** The store this user acts in: their own, or the one they are staff of. */
    public function storeFor(int $userId): ?int
    {
        if ($userId <= 0) {
            return null;
        }
        $profile = $this->vendors->findProfileByUser($userId);
        if ($profile !== null && $profile->canSell) {
            return $userId;
        }
        $membership = $this->staff->findByUser($userId);
        if ($membership === null || !$membership->status->canAct()) {
            return null;
        }
        // A suspended shop derives nothing: staff rights come FROM the vendor,
        // so the vendor's own standing is part of every staff answer.
        return $this->vendorCanTrade($membership->vendorUserId) ? $membership->vendorUserId : null;
    }

    /**
     * Whether this shop may operate at all.
     *
     * Read on every question rather than cached, for the same reason staff
     * suspension is: a suspension that takes effect at the next login is not
     * a suspension.
     */
    public function vendorCanTrade(int $vendorUserId): bool
    {
        $profile = $this->vendors->findProfileByUser($vendorUserId);
        return $profile !== null && $profile->canSell;
    }

    public function isOwner(int $userId, int $vendorUserId): bool
    {
        if ($userId <= 0 || $userId !== $vendorUserId) {
            return false;
        }
        return $this->vendorCanTrade($userId);
    }

    /** Owners: yes. Staff: only their own store, only while active. */
    public function can(int $userId, int $vendorUserId, StaffArea $area, StaffLevel $needed): bool
    {
        if ($this->isOwner($userId, $vendorUserId)) {
            return true;
        }
        $membership = $this->staff->findByUser($userId);
        if ($membership === null || $membership->vendorUserId !== $vendorUserId) {
            return false;
        }
        if ($membership->status !== StaffStatus::Active) {
            return false;
        }
        if (!$this->vendorCanTrade($vendorUserId)) {
            return false;       // suspending the shop suspends everyone in it
        }
        return $membership->permissions->allows($area, $needed);
    }

    /**
     * Managing staff and the shop's own settings is the owner's alone — the
     * store-manager preset stops short of it on purpose (UX §9.2), and this
     * is also what stops a staff member enrolling themselves.
     */
    public function canManageStore(int $userId, int $vendorUserId): bool
    {
        return $this->isOwner($userId, $vendorUserId);
    }
}
