<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts\Files;

/**
 * Storage for files that must never be fetchable by URL.
 *
 * Implementations write OUTSIDE every directory the web server can serve —
 * not merely outside the media library — with unguessable names, and the
 * only way back out is read(), behind a capability and ownership check
 * (plan §7). Nothing here returns a URL, on purpose.
 *
 * An implementation that cannot find such a place must refuse to store.
 * Falling back to a servable directory would turn a visible failure into a
 * silent exposure, so unavailableReason() exists to let callers and admin
 * screens say what is wrong instead of guessing.
 */
interface PrivateFileStorageInterface
{
    /**
     * @throws PrivateStorageUnavailable when there is no directory outside
     *         the web roots to write to
     * @throws \RuntimeException when the bytes could not be stored
     */
    public function store(UploadedFile $file, string $scope): StoredFile;

    public function read(string $relativePath): ?string;

    public function exists(string $relativePath): bool;

    public function delete(string $relativePath): bool;

    /** Null when storing works; otherwise why it does not. */
    public function unavailableReason(): ?string;
}
