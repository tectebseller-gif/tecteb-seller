<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Domain;

use Tecteb\Marketplace\Contracts\Files\UploadedFile;

/**
 * Decides whether one incoming file may be stored, using ONLY the manager's
 * own document type as the rule set.
 *
 * The MIME compared here is the one the server detected from the bytes. The
 * browser's Content-Type and the file extension are both attacker-controlled
 * and are never consulted.
 */
final class UploadPolicy
{
    public function decide(RequirementSet $requirements, string $typeSlug, UploadedFile $file): UploadDecision
    {
        $type = $requirements->typeBySlug($typeSlug);
        if ($type === null) {
            return UploadDecision::refuse(UploadRejection::UnknownType);
        }
        // PHP can refuse the file before our rule is ever consulted. Observed
        // on the real site: a 3 MB upload against a 2 MB upload_max_filesize
        // arrives with UPLOAD_ERR_INI_SIZE and no bytes, and calling that
        // "the transfer failed" sends the applicant looking for a network
        // problem they do not have. It is a size refusal, so say so.
        if ($file->errorCode === UPLOAD_ERR_INI_SIZE || $file->errorCode === UPLOAD_ERR_FORM_SIZE) {
            return UploadDecision::refuse(UploadRejection::TooLarge, $type);
        }
        if ($file->errorCode !== 0) {
            return UploadDecision::refuse(UploadRejection::TransferFailed, $type);
        }
        if ($file->originalName === '' || $file->tempPath === '') {
            return UploadDecision::refuse(UploadRejection::NoFile, $type);
        }
        if ($file->sizeBytes <= 0) {
            return UploadDecision::refuse(UploadRejection::Empty, $type);
        }
        if ($file->sizeBytes > $type->maxBytes) {
            return UploadDecision::refuse(UploadRejection::TooLarge, $type);
        }
        if (!$type->accepts($file->detectedMime)) {
            return UploadDecision::refuse(UploadRejection::MimeNotAllowed, $type);
        }
        return UploadDecision::accept($type);
    }
}
