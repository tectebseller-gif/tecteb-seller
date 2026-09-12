<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Domain;

use Tecteb\Marketplace\Contracts\Files\UploadedFile;

/** Accepted, or refused with exactly one reason. */
final class UploadDecision
{
    private function __construct(
        public readonly bool $accepted,
        public readonly ?UploadRejection $reason,
        public readonly ?DocumentType $type
    ) {
    }

    public static function accept(DocumentType $type): self
    {
        return new self(true, null, $type);
    }

    public static function refuse(UploadRejection $reason, ?DocumentType $type = null): self
    {
        return new self(false, $reason, $type);
    }
}
