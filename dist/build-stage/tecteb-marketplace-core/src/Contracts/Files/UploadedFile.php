<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts\Files;

/**
 * One incoming file, already moved nowhere. `detectedMime` is what the server
 * decided by INSPECTING the bytes — never the browser's claim, which is
 * attacker-controlled.
 */
final class UploadedFile
{
    public function __construct(
        public readonly string $originalName,
        public readonly string $tempPath,
        public readonly int $sizeBytes,
        public readonly string $detectedMime,
        public readonly int $errorCode = 0
    ) {
    }
}
