<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;

/**
 * A manager deciding, explicitly, whether a shop IMPORTED FROM DOKAN may sell.
 *
 * The gap this closes was measured before a line of it was written: a shop
 * that arrived through the import is a row in the profile table with
 * `can_sell = 0` and a run stamp, and it has no application row at all —
 * the import writes none, anywhere. Every method of `ReviewApplication` takes
 * an `$applicationId` and opens with `findApplication()`, so the action the
 * handover page prescribed for its own selling gate could not be carried out:
 * `application_id_for_shop=__none__`, `approve_code=forbidden`,
 * `can_sell_after=no`. A gate whose only exit does not exist is worse than no
 * gate at all (`alpha.80`), and the owner's §2 names the remedy: an
 * independent, auditable path, «بدون ایجاد درخواست فروشندگی صوری و بدون
 * الزام فروشنده قدیمی به ثبت درخواست جدید».
 *
 * ### The permission is REVIEW_VENDOR, and no new one was minted
 *
 * This writes `can_sell` on the profile — byte for byte the thing approving
 * an application writes. Two capabilities for one consequence would let an
 * owner hand somebody this route while denying them the review route, which
 * is a privilege-separation hole that minting the key creates. Same
 * consequence, same permission.
 *
 * ### What it refuses, and why each refusal is necessary
 *
 *   shop_not_found        no profile: there is nothing to decide about
 *   not_an_imported_shop  no run stamp. This route is ONLY for imported
 *                         shops; an ordinary applicant goes through review,
 *                         and that separation is what keeps one column from
 *                         having two writers that do not know about each other
 *   application_exists    paperwork exists, so the review route is the door.
 *                         This is «no sham application» enforced from the far
 *                         side: the two routes can never both write
 *   no_user_account       no WordPress user with that id. A selling
 *                         permission for somebody who cannot sign in is not a
 *                         permission
 *   already_granted       the explicit act has nothing to do. It says so and
 *   already_withheld      writes NOTHING — no row, no audit line
 *   not_stored            the guarded UPDATE matched no row: somebody changed
 *                         the same permission in between. The row is re-read
 *                         and nothing is claimed
 *   storage_failed        the statement itself failed
 *
 * ### What it deliberately does NOT refuse
 *
 * The other five migration gates. Blocking on them would be a dead end for
 * exactly the shops this exists for: `ownership_taken` wants
 * `marketplace > 0 && observed === 0`, and every imported row is `observed`
 * the moment it is created — so that condition is closed from day one for
 * every imported shop. And the permission changes nothing for an `observed`
 * row anyway: only four reads filter on `marketplace`, so granting it to a
 * shop with nothing transferred yet makes nothing sellable. A guard that
 * creates a dead end is worse than the defect it came for (`alpha.68`), so
 * the handover page prints all six gates and a human decides with them in
 * view.
 *
 * ### What it cannot do
 *
 * Transfer ownership of a product. That is the absence of a capability rather
 * than a sentence: this file names no ownership writer, and
 * `ImportedShopSellingIsNotOwnershipTest` reads its bytes to say so. Granting
 * the right to sell and deciding whose product it is are two decisions, and
 * «این اقدام نباید به‌طور ضمنی مالکیت محصولات را منتقل کند».
 *
 * ### Withdrawing it does not draft anything
 *
 * `ReviewApplication::suspend()` drafts a shop's products through
 * `withdrawVendor()`. This does not, on purpose: drafting rows as a side
 * effect of a permission change is a data change nobody asked for, and it is
 * not needed — with `can_sell = 0`, `StaffAccess::vendorCanTrade()` answers
 * false and `PurchasePolicy` returns `vendor_stopped`, so a published product
 * refuses the purchase by itself.
 */
final class DecideImportedShopSelling
{
    public const GRANT = 'grant';
    public const WITHDRAW = 'withdraw';

    public function __construct(
        private readonly VendorRepositoryInterface $vendors,
        private readonly AuditLogger $audit,
        private readonly CapabilityCheckerInterface $capabilities,
        private readonly StaffUserDirectoryInterface $users
    ) {
    }

    /** Let an imported shop sell. Never automatic: one manager, one action. */
    public function grant(int $vendorUserId): OperationResult
    {
        return $this->decide($vendorUserId, true);
    }

    /** Take it back. The same permission, the same audit trail, no drafting. */
    public function withdraw(int $vendorUserId): OperationResult
    {
        return $this->decide($vendorUserId, false);
    }

    /**
     * Whether this route may decide about this shop at all, and why not.
     *
     * Separate from `decide()` and public, so the page can draw the button
     * only where it would work and print the reason where it would not. One
     * answer for the screen and the action: a page that offered a button the
     * service refuses is a page that teaches people to ignore it.
     *
     * @return array{eligible:bool, reason:string, can_sell:bool}
     */
    public function eligibility(int $vendorUserId): array
    {
        $profile = $this->vendors->findProfileByUser($vendorUserId);
        if ($profile === null) {
            return ['eligible' => false, 'reason' => 'shop_not_found', 'can_sell' => false];
        }
        $canSell = $profile->canSell;
        if ($this->vendors->importRunIdFor($vendorUserId) === '') {
            return ['eligible' => false, 'reason' => 'not_an_imported_shop', 'can_sell' => $canSell];
        }
        if ($this->vendors->findApplicationByUser($vendorUserId) !== null) {
            return ['eligible' => false, 'reason' => 'application_exists', 'can_sell' => $canSell];
        }
        if (!$this->users->exists($vendorUserId)) {
            return ['eligible' => false, 'reason' => 'no_user_account', 'can_sell' => $canSell];
        }
        return ['eligible' => true, 'reason' => '', 'can_sell' => $canSell];
    }

    private function decide(int $vendorUserId, bool $canSell): OperationResult
    {
        if (!$this->capabilities->can(VendorCapabilities::REVIEW)) {
            return OperationResult::failure('forbidden');
        }
        if ($vendorUserId <= 0) {
            return OperationResult::failure('shop_not_found');
        }
        $gate = $this->eligibility($vendorUserId);
        if (!$gate['eligible']) {
            return OperationResult::failure((string) $gate['reason'], ['vendor_id' => $vendorUserId]);
        }
        if ($gate['can_sell'] === $canSell) {
            // An explicit act with nothing to do. Reported, and not written:
            // an audit row for a change that did not happen is worse than no
            // row, because somebody will read it as one.
            return OperationResult::success(
                $canSell ? 'already_granted' : 'already_withheld',
                ['vendor_id' => $vendorUserId]
            );
        }

        $changed = $this->vendors->setSellingPermission($vendorUserId, $canSell, !$canSell);
        if ($changed === null) {
            return OperationResult::failure('storage_failed', ['vendor_id' => $vendorUserId]);
        }
        if ($changed === 0) {
            // Zero rows is neither an error nor a success here: the guard in
            // the statement did not match, which means the value moved between
            // the read and the write. Ask the row what it holds now and say
            // that, rather than reporting the change we wanted (`alpha.49`).
            $now = $this->vendors->findProfileByUser($vendorUserId);
            return OperationResult::failure('not_stored', [
                'vendor_id' => $vendorUserId,
                'can_sell' => $now !== null && $now->canSell,
            ]);
        }

        // AFTER the confirmed write, not before. The shop view writes its
        // audit row first on purpose — a read that fails halfway is still a
        // read — and a write is the opposite case: a trail that records a
        // permission change which never landed is a trail that lies.
        $this->audit->log(
            AuditEventCatalog::VENDOR_SELLING_DECIDED,
            $this->capabilities->currentUserId() ?? 0,
            'vendor',
            (string) $vendorUserId,
            [
                'vendor_id' => $vendorUserId,
                'actor_id' => $this->capabilities->currentUserId() ?? 0,
                'decision' => $canSell ? self::GRANT : self::WITHDRAW,
                'origin' => 'imported',
            ]
        );

        return OperationResult::success(
            $canSell ? 'selling_granted' : 'selling_withdrawn',
            ['vendor_id' => $vendorUserId]
        );
    }
}
