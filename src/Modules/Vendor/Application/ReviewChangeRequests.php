<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Vendor\Domain\ChangeRequest;
use Tecteb\Marketplace\Modules\Vendor\Domain\ChangeRequestStatus;

/** The manager's decision on a rename or a bank change. */
final class ReviewChangeRequests
{
    public function __construct(
        private readonly ChangeRequestRepositoryInterface $changes,
        private readonly StoreRepositoryInterface $stores,
        private readonly AuditLogger $audit,
        private readonly CapabilityCheckerInterface $capabilities
    ) {
    }

    public function approve(int $id, string $note = ''): OperationResult
    {
        return $this->decide($id, ChangeRequestStatus::Approved, $note);
    }

    public function reject(int $id, string $note): OperationResult
    {
        if (trim($note) === '') {
            return OperationResult::failure('note_required');
        }
        return $this->decide($id, ChangeRequestStatus::Rejected, $note);
    }

    private function decide(int $id, ChangeRequestStatus $status, string $note): OperationResult
    {
        if (!$this->capabilities->can(VendorCapabilities::REVIEW)) {
            return OperationResult::failure('forbidden');
        }
        $request = $this->changes->find($id);
        if ($request === null) {
            return OperationResult::failure('not_found');
        }
        if ($request->status !== ChangeRequestStatus::Pending) {
            return OperationResult::failure('already_decided', ['status' => $request->status->value]);
        }
        $reviewerId = (int) $this->capabilities->currentUserId();
        if (!$this->changes->decide($id, $status, $reviewerId, $note)) {
            return OperationResult::failure('storage_failed');
        }

        if ($status === ChangeRequestStatus::Approved) {
            $this->apply($request);
        }
        $this->audit->log(AuditEventCatalog::VENDOR_CHANGE_REVIEWED, $reviewerId, 'vendor_store', (string) $request->vendorUserId, [
            'vendor_id' => $request->vendorUserId,
            'field' => $request->field,
            'decision' => $status->value,
            'has_note' => trim($note) !== '' ? 1 : 0,
        ]);
        return OperationResult::success('change_' . $status->value);
    }

    /** Approval is what writes the field — never the vendor's own save. */
    private function apply(ChangeRequest $request): void
    {
        if ($request->field === ChangeRequest::FIELD_STORE_NAME) {
            $this->stores->renameStore($request->vendorUserId, $request->requestedValue);
            return;
        }
        if ($request->field === ChangeRequest::FIELD_BANK) {
            $bank = $this->stores->bank($request->vendorUserId);
            // Approving the account releases the settlement hold the request
            // put on it; rejecting deliberately leaves the hold in place.
            $this->stores->saveBank(
                $request->vendorUserId,
                $request->requestedValue,
                $bank['holder'],
                $bank['document_id'],
                'approved',
                false
            );
        }
    }
}
