<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Application;

use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Config\SettingsService;
use Tecteb\Marketplace\Modules\Vendor\Domain\InvitationToken;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffPermissions;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffRolePreset;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffStatus;

/**
 * Inviting, re-roling, suspending and reinstating staff.
 *
 * The vendor never gains a WordPress capability to create users: this service
 * asks StaffUserDirectory to do it, so the only way a user account appears is
 * through a path that has already checked ownership and the seat limit.
 */
final class ManageStaff
{
    public function __construct(
        private readonly StaffRepositoryInterface $staff,
        private readonly StaffUserDirectoryInterface $users,
        private readonly StaffAccess $access,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit
    ) {
    }

    /**
     * @param array<string,string> $custom levels for StaffRolePreset::Custom
     * @return OperationResult context carries the one-time link on success
     */
    public function invite(
        int $actorId,
        int $vendorUserId,
        string $firstName,
        string $lastName,
        string $username,
        string $email,
        string $mobile,
        StaffRolePreset $preset,
        array $custom = []
    ): OperationResult {
        if (!$this->access->canManageStore($actorId, $vendorUserId)) {
            return OperationResult::failure('forbidden');
        }
        $firstName = trim($firstName);
        $lastName = trim($lastName);
        $username = strtolower(trim($username));
        $email = trim($email);
        $mobile = trim($mobile);

        // Every field is required (UX §9.1). Names need not be unique; the
        // username, email and mobile must be.
        $missing = [];
        foreach (['first_name' => $firstName, 'last_name' => $lastName, 'username' => $username, 'email' => $email, 'mobile' => $mobile] as $key => $value) {
            if ($value === '') {
                $missing[] = $key;
            }
        }
        if ($missing !== []) {
            return OperationResult::failure('incomplete_staff', ['fields' => implode(',', $missing)]);
        }
        if (preg_match('/^[a-z0-9_.\-]{3,60}$/', $username) !== 1) {
            return OperationResult::failure('bad_username');
        }
        if (!$this->users->isEmail($email)) {
            return OperationResult::failure('bad_email');
        }

        $limit = $this->settings->load()->maxStaff;
        $used = $this->staff->countActiveAndInvited($vendorUserId);
        if ($limit > 0 && $used >= $limit) {
            return OperationResult::failure('staff_limit', ['limit' => $limit, 'used' => $used]);
        }
        if ($this->staff->usernameTaken($username) || $this->users->usernameTaken($username)) {
            return OperationResult::failure('username_taken', ['username' => $username]);
        }
        if ($this->users->emailTaken($email)) {
            return OperationResult::failure('email_taken');
        }

        $permissions = $preset === StaffRolePreset::Custom
            ? StaffPermissions::of($custom)
            : $preset->permissions();
        if ($permissions->isEmpty()) {
            return OperationResult::failure('no_permissions');
        }

        $displayName = trim($firstName . ' ' . $lastName);
        // ⛔ THE BRANCH THAT USED TO BE HERE WAS UNREACHABLE, AND ITS COMMENT
        // PROMISED THE OPPOSITE OF WHAT IT DID.
        //
        // It read «if this person is already staff, the account we just
        // created must not be left orphaned» — and then returned a failure
        // with the fresh account still on the site, which IS leaving it
        // orphaned. It could never run either: `create()` hands back a
        // brand-new id, so `findByUser()` of that id is always null. The
        // question it meant to ask is answered thirty lines above, BEFORE
        // anything is written — `$this->staff->usernameTaken($username)`
        // refuses somebody who is already on a roster.
        //
        // So it is gone rather than rewritten, and the real half of the
        // promise — «no orphan» — is kept below, where the failure that can
        // actually happen is handled. The `already_staff` sentence stays in
        // the catalogue: `GrantImportedStaff` uses that code too, and twenty
        // versions of audit rows carry the string (`alpha.57`).
        $staffUserId = $this->users->create($username, $email, $firstName, $lastName);
        if ($staffUserId <= 0) {
            return OperationResult::failure('user_not_created');
        }

        $token = InvitationToken::issue();
        $expires = $this->users->timestampInSeconds(InvitationToken::LIFETIME_SECONDS);
        $staffId = $this->staff->add(
            $vendorUserId,
            $staffUserId,
            $displayName,
            $username,
            $email,
            $mobile,
            $preset,
            $permissions,
            $token->hash,
            $expires
        );
        if ($staffId <= 0) {
            // ⛔ §7 CLAIM 9: THE ACCOUNT EXISTS AND ITS MEMBERSHIP DOES NOT.
            //
            // Two writes, and the second one failed. The first left a
            // WordPress user with the site's default role, a random password
            // nobody holds, and no membership row — so no shop access and no
            // way in. Harmless to USE, and a dead end for the person it was
            // for: `emailTaken()` now answers true, and nobody can ever be
            // invited with that address again.
            //
            // So the first write is taken back, and the ANSWER IS READ
            // (`alpha.90`): a compensation is itself a write and can fail.
            // Two outcomes, two sentences — «nothing was left behind», or the
            // username and the id of what was, so a manager can finish it by
            // hand in wp-admin where deleting a site user belongs.
            if ($this->users->deleteCreated($staffUserId)) {
                return OperationResult::failure('storage_failed');
            }
            // ⛔ `username` IS NOT IN THIS ROW, ON PURPOSE. The sanitiser keeps
            // only the keys `AuditEventCatalog::allowlist()` names for this
            // event and drops the rest WITHOUT SAYING SO (`alpha.91`), so a
            // key written here that is not there is a key nobody ever reads.
            // `staff_user_id` was added to that list and has a test that reads
            // it back out of the table; the login name goes on the screen,
            // where it is not stored.
            $this->audit->log(AuditEventCatalog::VENDOR_STAFF_INVITED, $actorId, 'vendor_staff', '0', [
                'vendor_id' => $vendorUserId,
                'status' => 'account_left',
                'staff_user_id' => $staffUserId,
            ]);
            return OperationResult::failure('storage_failed_account_left', [
                'username' => $username,
                'user_id' => $staffUserId,
            ]);
        }

        $this->audit->log(AuditEventCatalog::VENDOR_STAFF_INVITED, $actorId, 'vendor_staff', (string) $staffId, [
            'vendor_id' => $vendorUserId,
            'preset' => $preset->value,
            'status' => StaffStatus::Invited->value,
        ]);

        // The plain token is returned once, to be handed over by the vendor;
        // it is never stored, mailed or logged (Alpha sends nothing).
        return OperationResult::success('staff_invited', ['staff_id' => $staffId, 'token' => $token->plain]);
    }

    /** @param array<string,string> $custom */
    public function changeRole(int $actorId, int $staffId, StaffRolePreset $preset, array $custom = []): OperationResult
    {
        $member = $this->staff->find($staffId);
        if ($member === null) {
            return OperationResult::failure('not_found');
        }
        if (!$this->access->canManageStore($actorId, $member->vendorUserId)) {
            return OperationResult::failure('forbidden');
        }
        $permissions = $preset === StaffRolePreset::Custom ? StaffPermissions::of($custom) : $preset->permissions();
        if ($permissions->isEmpty()) {
            return OperationResult::failure('no_permissions');
        }
        if (!$this->staff->updateRole($staffId, $preset, $permissions)) {
            return OperationResult::failure('storage_failed');
        }
        $this->audit->log(AuditEventCatalog::VENDOR_STAFF_ROLE_CHANGED, $actorId, 'vendor_staff', (string) $staffId, [
            'vendor_id' => $member->vendorUserId,
            'preset' => $preset->value,
        ]);
        return OperationResult::success('staff_role_changed');
    }

    public function suspend(int $actorId, int $staffId): OperationResult
    {
        return $this->setStatus($actorId, $staffId, StaffStatus::Suspended, 'staff_suspended');
    }

    public function reinstate(int $actorId, int $staffId): OperationResult
    {
        $member = $this->staff->find($staffId);
        if ($member === null) {
            return OperationResult::failure('not_found');
        }
        // Someone who never accepted their invitation goes back to invited,
        // not active: reinstating must not hand out access nobody claimed.
        $target = $member->activatedAt === null ? StaffStatus::Invited : StaffStatus::Active;
        return $this->setStatus($actorId, $staffId, $target, 'staff_reinstated');
    }

    private function setStatus(int $actorId, int $staffId, StaffStatus $status, string $code): OperationResult
    {
        $member = $this->staff->find($staffId);
        if ($member === null) {
            return OperationResult::failure('not_found');
        }
        if (!$this->access->canManageStore($actorId, $member->vendorUserId)) {
            return OperationResult::failure('forbidden');
        }
        if (!$this->staff->updateStatus($staffId, $status)) {
            return OperationResult::failure('storage_failed');
        }
        $this->audit->log(AuditEventCatalog::VENDOR_STAFF_STATUS_CHANGED, $actorId, 'vendor_staff', (string) $staffId, [
            'vendor_id' => $member->vendorUserId,
            'status' => $status->value,
        ]);
        return OperationResult::success($code, ['status' => $status->value]);
    }
}
