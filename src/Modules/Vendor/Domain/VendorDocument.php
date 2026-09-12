<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Domain;

/** A stored document. `storedPath` is private and never leaves the server. */
final class VendorDocument
{
    public function __construct(
        public readonly int $id,
        public readonly int $applicationId,
        public readonly string $typeSlug,
        public readonly string $originalName,
        public readonly string $storedPath,
        public readonly string $mime,
        public readonly int $sizeBytes,
        public readonly string $uploadedAt
    ) {
    }
}
