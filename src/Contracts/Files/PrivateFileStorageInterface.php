<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts\Files;

/**
 * Storage for files that must never be fetchable by URL.
 *
 * Implementations write outside the public media path, with unguessable
 * names, and the only way back out is read() behind a capability and
 * ownership check (plan §7). Nothing here returns a URL, on purpose.
 */
interface PrivateFileStorageInterface
{
    /** @throws \RuntimeException when the bytes could not be stored. */
    public function store(UploadedFile $file, string $scope): StoredFile;

    public function read(string $relativePath): ?string;

    public function exists(string $relativePath): bool;

    public function delete(string $relativePath): bool;
}
