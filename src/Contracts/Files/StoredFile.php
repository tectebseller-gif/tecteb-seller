<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts\Files;

/** Where a private file ended up. `relativePath` is all the database keeps. */
final class StoredFile
{
    public function __construct(
        public readonly string $relativePath,
        public readonly int $sizeBytes,
        public readonly string $mime
    ) {
    }
}
