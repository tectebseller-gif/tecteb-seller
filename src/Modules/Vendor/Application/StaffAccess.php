<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Application;

use Tecteb\Marketplace\Modules\Vendor\Domain\StaffArea;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffLevel;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffMember;
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
     * Which shop this user is on the roster of — a question about MEMBERSHIP,
     * and deliberately not about permission.
     *
     * **Why this is separate from `storeFor()`, and must stay separate.**
     * `storeFor()` answers «which shop may this user act in», so it says `null`
     * for a member of a suspended shop and for a member whose own standing is
     * suspended or still invited. That is right, and it is the answer every
     * gate must keep using. But a screen that reads it as «which shop is this
     * person part of» concludes «none», and the only page left for somebody who
     * is part of no shop is the one that invites them to apply for one — so an
     * employee of a suspended shop was shown «هنوز درخواستی ثبت نکرده‌اید» and
     * a «شروع درخواست فروشندگی» button, above the shop they work in. Measured
     * on `alpha.33`.
     *
     * So: this says who somebody IS, `can()` says what they may DO, and nothing
     * here widens the second. Callers get a row, not a right — every existing
     * gate is untouched, and a caller that wanted a permission and reached for
     * this instead is reading a `StaffMember`, which grants nothing.
     *
     * The row is returned whatever its status, including `Suspended` and
     * `Invited`: «your access here is paused» is a true and useful sentence, and
     * it is the one a person in that state needs. There is no `deleted` status
     * (§3.1), so this never resurrects a membership that was ended — it cannot
     * be ended, only suspended, and a suspended member is told exactly that.
     */
    public function membershipFor(int $userId): ?StaffMember
    {
        if ($userId <= 0) {
            return null;
        }
        return $this->staff->findByUser($userId);
    }

    /**
     * Everybody who acts in this shop right now: the owner and their active staff.
     *
     * Asked here rather than assembled by each caller, for the same reason
     * every other question is: this is the ONE place that knows who a shop's
     * people are, and a suspension has to remove somebody from the answer
     * immediately. A notice addressed to a suspended staff member would be a
     * suspension that leaked.
     *
     * A shop that may not trade has nobody: its owner is still a user, but a
     * suspended shop is not a place work happens.
     *
     * @return list<int> user ids, the owner first, each appearing once
     */
    public function activeMembersOf(int $vendorUserId): array
    {
        if ($vendorUserId <= 0 || !$this->vendorCanTrade($vendorUserId)) {
            return [];
        }
        $members = [$vendorUserId];
        foreach ($this->staff->forVendor($vendorUserId) as $member) {
            $userId = (int) $member->staffUserId;
            if ($userId > 0 && $member->status->canAct() && !in_array($userId, $members, true)) {
                $members[] = $userId;
            }
        }
        return $members;
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
