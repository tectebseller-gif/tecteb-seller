<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffUserDirectoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffStatus;

/**
 * The gap between «Dokan had this person» and «this person may act here».
 *
 * Importing a shop writes its staff into `tmc_dokan_staff_history`. That is a
 * record of somebody else's arrangement and it grants nothing — the
 * operational question «who may touch this shop's orders» is answered only by
 * `tmc_vendor_staff`, and only this service ever moves a person across.
 *
 * Three rules hold it shut:
 *
 * 1. **Nothing without a decision.** A Dokan role with no entry in
 *    `StaffRoleMap` produces `AWAITING_DECISION` and no row. Not a view-only
 *    fallback and not a placeholder — «هیچ دسترسی نامشخصی خودکار اعطا نشود».
 * 2. **Nothing live on arrival.** A granted membership is created `Invited`,
 *    whose `canAct()` is false, with an **empty invite hash** — and
 *    `findByInviteHash('')` answers null, so there is no token in existence
 *    that could activate it. The only way to Active is `confirm()`, which a
 *    person presses.
 * 3. **Nothing to a stranger.** The WordPress account must still exist, and a
 *    person already attached to some shop here is refused rather than moved:
 *    one person belongs to one store, and quietly re-pointing a membership is
 *    how somebody ends up reading another shop's orders.
 */
final class GrantImportedStaff
{
    public const GRANTED = 'granted';
    public const AWAITING_DECISION = 'awaiting_decision';
    public const DECLINED = 'declined';
    public const ALREADY_STAFF = 'already_staff';
    public const NO_ACCOUNT = 'no_account';
    public const FAILED = 'failed';

    public function __construct(
        private readonly ShopRecordRepositoryInterface $records,
        private readonly StaffRoleMap $roles,
        private readonly StaffRepositoryInterface $staff,
        private readonly StaffUserDirectoryInterface $users,
        private readonly AuditLogger $audit,
        private readonly CapabilityCheckerInterface $caps
    ) {
    }

    /**
     * What granting would do, per person. Reads only — no row is written and
     * no permission changes.
     *
     * @return list<array{staff_user_id:int, display_name:string, dokan_role:string, verdict:string, preset:string}>
     */
    public function plan(int $vendorUserId): array
    {
        $out = [];
        foreach ($this->records->staffForVendor($vendorUserId) as $member) {
            [$verdict, $preset] = $this->judge($member);
            $out[] = [
                'staff_user_id' => (int) $member['staff_user_id'],
                'display_name' => (string) $member['display_name'],
                'dokan_role' => (string) $member['dokan_role'],
                'verdict' => $verdict,
                'preset' => $preset,
            ];
        }
        return $out;
    }

    /**
     * Create the memberships the map decided on, and only those.
     *
     * @return array{granted:int, awaiting_decision:int, declined:int, already_staff:int, no_account:int, failed:int, rows:list<array<string,mixed>>}
     */
    public function grant(int $vendorUserId): array
    {
        $tally = [
            self::GRANTED => 0,
            self::AWAITING_DECISION => 0,
            self::DECLINED => 0,
            self::ALREADY_STAFF => 0,
            self::NO_ACCOUNT => 0,
            self::FAILED => 0,
        ];
        $rows = [];
        if (!$this->caps->can(Capabilities::REVIEW_VENDOR)) {
            return $tally + ['rows' => [], 'forbidden' => true];
        }

        $actorId = $this->caps->currentUserId() ?? 0;
        foreach ($this->records->staffForVendor($vendorUserId) as $member) {
            [$verdict, $presetValue] = $this->judge($member);
            $staffId = 0;
            if ($verdict === self::GRANTED) {
                $staffId = $this->create($vendorUserId, $member);
                if ($staffId <= 0) {
                    $verdict = self::FAILED;
                } else {
                    $this->audit->log(
                        AuditEventCatalog::DOKAN_STAFF_GRANTED,
                        $actorId,
                        'vendor_staff',
                        (string) $staffId,
                        [
                            'vendor_id' => $vendorUserId,
                            'staff_user_id' => (int) $member['staff_user_id'],
                            'dokan_role' => (string) $member['dokan_role'],
                            'preset' => $presetValue,
                            'status' => StaffStatus::Invited->value,
                        ]
                    );
                }
            }
            $tally[$verdict]++;
            $rows[] = [
                'staff_user_id' => (int) $member['staff_user_id'],
                'dokan_role' => (string) $member['dokan_role'],
                'verdict' => $verdict,
                'preset' => $presetValue,
                'staff_id' => $staffId,
            ];
        }
        return $tally + ['rows' => $rows, 'forbidden' => false];
    }

    /**
     * Invited → Active: the moment a person decides this imported member
     * really does work here.
     *
     * Deliberately separate from `grant()`. Granting is bulk and reversible by
     * doing nothing; this is per-person and is the only thing that makes an
     * imported name able to touch an order.
     */
    public function confirm(int $staffId): string
    {
        if (!$this->caps->can(Capabilities::REVIEW_VENDOR)) {
            return self::FAILED;
        }
        $member = $this->staff->find($staffId);
        if ($member === null || $member->status !== StaffStatus::Invited) {
            return self::FAILED;
        }
        if (!$this->staff->activate($staffId)) {
            return self::FAILED;
        }
        $this->audit->log(
            AuditEventCatalog::VENDOR_STAFF_ACTIVATED,
            $this->caps->currentUserId() ?? 0,
            'vendor_staff',
            (string) $staffId,
            ['vendor_id' => $member->vendorUserId]
        );
        return self::GRANTED;
    }

    /**
     * @param array{staff_user_id:int, dokan_role:string} $member
     * @return array{0:string, 1:string} verdict, preset value ('' when none)
     */
    private function judge(array $member): array
    {
        $staffUserId = (int) $member['staff_user_id'];
        $role = (string) $member['dokan_role'];

        if ($this->roles->isDeclined($role)) {
            return [self::DECLINED, ''];
        }
        $preset = $this->roles->presetFor($role);
        if ($preset === null) {
            return [self::AWAITING_DECISION, ''];
        }
        if (!$this->users->exists($staffUserId)) {
            return [self::NO_ACCOUNT, $preset->value];
        }
        if ($this->staff->findByUser($staffUserId) !== null) {
            return [self::ALREADY_STAFF, $preset->value];
        }
        return [self::GRANTED, $preset->value];
    }

    /** @param array<string,mixed> $member */
    private function create(int $vendorUserId, array $member): int
    {
        $preset = $this->roles->presetFor((string) $member['dokan_role']);
        if ($preset === null) {
            return 0;
        }
        $email = (string) $member['user_email'];
        return $this->staff->adopt(
            $vendorUserId,
            (int) $member['staff_user_id'],
            (string) $member['display_name'],
            // The username is the membership's own label, and a Dokan staff
            // row does not carry one. Deriving it from the email keeps it
            // unique per person without inventing an identity; it is never
            // used to log in — the WordPress account already exists and keeps
            // its own credentials, which this path does not touch.
            $this->usernameFor($email, (int) $member['staff_user_id']),
            $email,
            $preset,
            $preset->permissions()
        );
    }

    private function usernameFor(string $email, int $staffUserId): string
    {
        $local = strtolower((string) strstr($email, '@', true));
        $local = (string) preg_replace('/[^a-z0-9_.\-]/', '', $local);
        $base = $local === '' ? 'dokan' : substr($local, 0, 40);
        return $base . '-' . $staffUserId;
    }
}
