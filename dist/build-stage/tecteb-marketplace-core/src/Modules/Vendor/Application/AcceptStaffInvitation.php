<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Application;

use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Vendor\Domain\InvitationToken;

/**
 * Turning a one-time link into a working account.
 *
 * The person is not signed in when they arrive — they have no account yet in
 * any useful sense — so the token IS the authorisation. That is why it is
 * long, hashed at rest, single use, expiring, and compared in constant time,
 * and why accepting it does three things in one step: set a password, mark
 * the membership active, and burn the token.
 */
final class AcceptStaffInvitation
{
    public const MIN_PASSWORD = 10;

    public function __construct(
        private readonly StaffRepositoryInterface $staff,
        private readonly StaffUserDirectoryInterface $users,
        private readonly AuditLogger $audit
    ) {
    }

    /** Looks up without consuming, so the form can be shown before the post. */
    public function inspect(string $token): OperationResult
    {
        if (!InvitationToken::looksWellFormed($token)) {
            return OperationResult::failure('invite_invalid');
        }
        $member = $this->staff->findByInviteHash(InvitationToken::hash($token));
        if ($member === null) {
            return OperationResult::failure('invite_invalid');
        }
        return OperationResult::success('invite_valid', [
            'staff_id' => $member->id,
            'display_name' => $member->displayName,
            'username' => $member->username,
        ]);
    }

    public function accept(string $token, string $password): OperationResult
    {
        $found = $this->inspect($token);
        if (!$found->ok) {
            return $found;
        }
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            return OperationResult::failure('password_too_short', ['minimum' => self::MIN_PASSWORD]);
        }
        $member = $this->staff->find((int) $found->context['staff_id']);
        if ($member === null) {
            return OperationResult::failure('invite_invalid');
        }
        if (!$this->users->setPassword($member->staffUserId, $password)) {
            return OperationResult::failure('storage_failed');
        }
        if (!$this->staff->activate($member->id)) {
            return OperationResult::failure('storage_failed');
        }
        $this->audit->log(AuditEventCatalog::VENDOR_STAFF_ACTIVATED, $member->staffUserId, 'vendor_staff', (string) $member->id, [
            'vendor_id' => $member->vendorUserId,
        ]);
        return OperationResult::success('staff_activated', ['username' => $member->username]);
    }
}
