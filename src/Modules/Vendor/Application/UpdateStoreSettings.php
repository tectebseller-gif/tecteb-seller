<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Application;

use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Vendor\Domain\ChangeRequest;
use Tecteb\Marketplace\Modules\Vendor\Domain\StoreSettings;

/**
 * The shop's own settings, and the two things the shop may not settle alone.
 *
 * Renaming and banking do not write the field; they open a change request.
 * The spec is explicit about both (UX §11: «نام/slug/حقوقی نیازمند مدیر»،
 * «مالک + OTP + تأیید مدیر»), and the reason is the same in each case: the
 * name is how buyers identify a seller, and the IBAN is where money goes.
 *
 * The OTP step of the banking rule CANNOT be performed in this build — there
 * is no provider — so it is not silently skipped: the result says so, the
 * screen says so, and settlement is put on hold the moment an IBAN changes.
 */
final class UpdateStoreSettings
{
    public function __construct(
        private readonly StoreRepositoryInterface $stores,
        private readonly ChangeRequestRepositoryInterface $changes,
        private readonly StaffAccess $access,
        private readonly AuditLogger $audit,
        private readonly MobileVerification $mobile
    ) {
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $allowedNetworks
     * @param list<string> $allowedCarriers
     */
    public function save(int $actorId, int $vendorUserId, array $input, array $allowedNetworks, array $allowedCarriers): OperationResult
    {
        if (!$this->access->canManageStore($actorId, $vendorUserId)) {
            return OperationResult::failure('forbidden');
        }
        // A tab the page does not have is refused rather than treated as
        // «general». A save whose tab is unknown has no allowed field set, so
        // guessing one would either write nothing while reporting success or
        // write somebody's general fields from a form that never showed them.
        $tab = (string) ($input['tab'] ?? '');
        if (!StoreSettings::isTab($tab)) {
            return OperationResult::failure('bad_tab', ['tab' => $tab]);
        }
        // Normalised HERE, so no caller can get the scope wrong: whatever was
        // handed in, only the tab's own fields reach the Domain. The route
        // filters too, but for a different reason — it must not read an image
        // upload for a tab that has no file input — and a rule enforced in one
        // place is a rule, while a rule enforced only at the edge is a habit.
        $scoped = StoreSettings::fieldsOfTab($tab, $input);
        $current = $this->stores->find($vendorUserId) ?? new StoreSettings();
        ['settings' => $settings, 'problems' => $problems] = StoreSettings::fromInput($scoped, $allowedNetworks, $allowedCarriers, $current);
        // Only the columns this tab owns are written, which is the other half
        // of the fix and the half that survives two browser tabs. Filtering
        // the INPUT stops a shipping save from carrying an empty city; it does
        // not stop it from writing the city it read a moment earlier, and two
        // tabs open on two different sections would then each write the
        // other's fields back from their own stale read. The repository is
        // told which fields are in play and names only those columns — the
        // shape `saveBank()` has used in this same class since `alpha.5`.
        if (!$this->stores->save($vendorUserId, $settings, StoreSettings::TAB_FIELDS[$tab])) {
            return OperationResult::failure('storage_failed');
        }
        $this->audit->log(AuditEventCatalog::VENDOR_STORE_UPDATED, $actorId, 'vendor_store', (string) $vendorUserId, [
            'vendor_id' => $vendorUserId,
            'tab' => $tab,
            'problems' => implode(',', $problems),
        ]);
        return $problems === []
            ? OperationResult::success('store_saved')
            : OperationResult::success('store_saved_with_problems', ['problems' => implode('، ', $problems)]);
    }

    public function requestRename(int $actorId, int $vendorUserId, string $newName): OperationResult
    {
        if (!$this->access->canManageStore($actorId, $vendorUserId)) {
            return OperationResult::failure('forbidden');
        }
        $newName = trim($newName);
        if ($newName === '') {
            return OperationResult::failure('incomplete_form');
        }
        if ($this->changes->pendingFor($vendorUserId, ChangeRequest::FIELD_STORE_NAME) !== null) {
            return OperationResult::failure('change_already_pending');
        }
        $current = $this->stores->find($vendorUserId)?->storeName ?? '';
        if ($current === $newName) {
            return OperationResult::failure('no_change');
        }
        if ($this->changes->open($vendorUserId, ChangeRequest::FIELD_STORE_NAME, $current, $newName) <= 0) {
            return OperationResult::failure('storage_failed');
        }
        $this->audit->log(AuditEventCatalog::VENDOR_CHANGE_REQUESTED, $actorId, 'vendor_store', (string) $vendorUserId, [
            'vendor_id' => $vendorUserId,
            'field' => ChangeRequest::FIELD_STORE_NAME,
        ]);
        return OperationResult::success('change_requested');
    }

    /**
     * Banking. Only the owner, always through the manager, and never claiming
     * an OTP step that did not happen.
     */
    public function requestBankChange(int $actorId, int $vendorUserId, string $iban, string $holder, int $documentId): OperationResult
    {
        if (!$this->access->canManageStore($actorId, $vendorUserId)) {
            return OperationResult::failure('forbidden');
        }
        $iban = strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
        $holder = trim($holder);
        if ($holder === '' || $iban === '') {
            return OperationResult::failure('incomplete_form');
        }
        if (preg_match('/^IR[0-9]{24}$/', $iban) !== 1) {
            return OperationResult::failure('bad_iban');
        }
        if ($this->changes->pendingFor($vendorUserId, ChangeRequest::FIELD_BANK) !== null) {
            return OperationResult::failure('change_already_pending');
        }

        $bank = $this->stores->bank($vendorUserId);
        // The rule the plan's gate names: a changed IBAN stops settlement
        // until a manager approves it. Recorded now, even though nothing
        // settles yet, so the flag is already true when settlement arrives.
        if (!$this->stores->saveBank($vendorUserId, $bank['iban'], $holder, $documentId, 'pending', true)) {
            return OperationResult::failure('storage_failed');
        }
        if ($this->changes->open($vendorUserId, ChangeRequest::FIELD_BANK, $bank['iban'], $iban) <= 0) {
            return OperationResult::failure('storage_failed');
        }
        $this->audit->log(AuditEventCatalog::VENDOR_CHANGE_REQUESTED, $actorId, 'vendor_store', (string) $vendorUserId, [
            'vendor_id' => $vendorUserId,
            'field' => ChangeRequest::FIELD_BANK,
        ]);
        return OperationResult::success('bank_change_requested', [
            'otp_available' => $this->mobile->available() ? 'yes' : 'no',
        ]);
    }
}
