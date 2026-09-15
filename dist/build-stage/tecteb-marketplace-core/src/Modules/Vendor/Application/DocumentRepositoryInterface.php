<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Application;

use Tecteb\Marketplace\Modules\Vendor\Domain\VendorDocument;

interface DocumentRepositoryInterface
{
    /** @return list<VendorDocument> */
    public function forApplication(int $applicationId): array;

    public function find(int $documentId): ?VendorDocument;

    public function add(
        int $applicationId,
        string $typeSlug,
        string $originalName,
        string $storedPath,
        string $mime,
        int $sizeBytes
    ): int;

    /** Replacing an upload for the same type keeps one row per type. */
    public function deleteForType(int $applicationId, string $typeSlug): ?string;
}
