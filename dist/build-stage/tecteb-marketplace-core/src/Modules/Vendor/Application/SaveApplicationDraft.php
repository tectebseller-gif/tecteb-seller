<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicantDetails;

/**
 * Saving a draft is always allowed while the application is editable, even
 * when the manager has defined no documents yet. Blocking the draft on a
 * decision the applicant cannot influence would strand them (plan §2.2).
 */
final class SaveApplicationDraft
{
    public function __construct(
        private readonly VendorRepositoryInterface $repository,
        private readonly CapabilityCheckerInterface $capabilities
    ) {
    }

    public function handle(int $userId, ApplicantDetails $details): OperationResult
    {
        if (!$this->capabilities->can(VendorCapabilities::APPLY)) {
            return OperationResult::failure('forbidden');
        }
        $existing = $this->repository->findApplicationByUser($userId);
        if ($existing !== null && !$existing->status->isEditableByApplicant()) {
            return OperationResult::failure('not_editable', ['status' => $existing->status->value]);
        }
        $id = $this->repository->saveDraft($userId, $details);
        if ($id <= 0) {
            return OperationResult::failure('storage_failed');
        }
        return OperationResult::success('draft_saved', ['application_id' => $id]);
    }
}
