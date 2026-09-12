<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\Files\PrivateFileStorageInterface;
use Tecteb\Marketplace\Contracts\Files\UploadedFile;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Vendor\Domain\UploadPolicy;
use Tecteb\Marketplace\Modules\Vendor\Domain\UploadRejection;

/**
 * One upload, checked against the manager's own rule for that document type
 * and then stored where no URL can reach it.
 *
 * A refusal is a normal outcome, not an exception: the form has to show the
 * applicant which rule they hit, and the audit log records that it happened
 * without recording the file.
 */
final class UploadApplicationDocument
{
    public function __construct(
        private readonly VendorRepositoryInterface $applications,
        private readonly DocumentTypeRepositoryInterface $types,
        private readonly DocumentRepositoryInterface $documents,
        private readonly PrivateFileStorageInterface $storage,
        private readonly UploadPolicy $policy,
        private readonly AuditLogger $audit,
        private readonly CapabilityCheckerInterface $capabilities
    ) {
    }

    public function handle(int $userId, string $typeSlug, UploadedFile $file): OperationResult
    {
        if (!$this->capabilities->can(VendorCapabilities::APPLY)) {
            return OperationResult::failure('forbidden');
        }
        $application = $this->applications->findApplicationByUser($userId);
        if ($application === null) {
            return OperationResult::failure(UploadRejection::NotEditable->value);
        }
        if (!$application->status->isEditableByApplicant()) {
            return OperationResult::failure(UploadRejection::NotEditable->value, ['status' => $application->status->value]);
        }

        $decision = $this->policy->decide($this->types->requirementSet(), $typeSlug, $file);
        if (!$decision->accepted) {
            $reason = $decision->reason?->value ?? UploadRejection::NoFile->value;
            $this->audit->log(AuditEventCatalog::VENDOR_DOCUMENT_REJECTED, $userId, 'vendor_application', (string) $application->id, [
                'application_id' => $application->id,
                'type' => $typeSlug,
                'reason' => $reason,
                'bytes' => $file->sizeBytes,
            ]);
            return OperationResult::failure($reason, [
                'type' => $typeSlug,
                'max_bytes' => $decision->type?->maxBytes,
                'allowed' => $decision->type !== null ? implode(', ', $decision->type->allowedMime) : null,
            ]);
        }

        try {
            $stored = $this->storage->store($file, 'application-' . $application->id);
        } catch (\Throwable) {
            $this->audit->log(AuditEventCatalog::VENDOR_DOCUMENT_REJECTED, $userId, 'vendor_application', (string) $application->id, [
                'application_id' => $application->id,
                'type' => $typeSlug,
                'reason' => UploadRejection::StorageFailed->value,
                'bytes' => $file->sizeBytes,
            ]);
            return OperationResult::failure(UploadRejection::StorageFailed->value, ['type' => $typeSlug]);
        }

        // One document per type: replacing an upload removes the old bytes too,
        // so a rejected file does not linger on disk after a correction.
        $previous = $this->documents->deleteForType($application->id, $typeSlug);
        if ($previous !== null) {
            $this->storage->delete($previous);
        }
        $id = $this->documents->add($application->id, $typeSlug, $file->originalName, $stored->relativePath, $stored->mime, $stored->sizeBytes);
        if ($id <= 0) {
            $this->storage->delete($stored->relativePath);
            return OperationResult::failure(UploadRejection::StorageFailed->value, ['type' => $typeSlug]);
        }

        $this->audit->log(AuditEventCatalog::VENDOR_DOCUMENT_UPLOADED, $userId, 'vendor_application', (string) $application->id, [
            'application_id' => $application->id,
            'type' => $typeSlug,
            'bytes' => $stored->sizeBytes,
            'mime' => $stored->mime,
        ]);
        return OperationResult::success('document_uploaded', ['type' => $typeSlug, 'document_id' => $id]);
    }
}
