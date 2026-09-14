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
        $current = $this->stores->find($vendorUserId) ?? new StoreSettings();
        ['settings' => $settings, 'problems' => $problems] = StoreSettings::fromInput($input, $allowedNetworks, $allowedCarriers, $current);
        if (!$this->stores->save($vendorUserId, $settings)) {
            return OperationResult::failure('storage_failed');
        }
        $this->audit->log(AuditEventCatalog::VENDOR_STORE_UPDATED, $actorId, 'vendor_store', (string) $vendorUserId, [
            'vendor_id' => $vendorUserId,
            'tab' => (string) ($input['tab'] ?? 'general'),
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
