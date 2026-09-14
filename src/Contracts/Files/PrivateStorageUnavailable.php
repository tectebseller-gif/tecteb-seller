<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts\Files;

/**
 * Thrown instead of writing a document somewhere the web server could serve
 * it. Part of the storage contract rather than of one implementation: every
 * implementation owes callers this refusal, and callers must be able to name
 * it without depending on a module. Carries the placement reason so the page can tell the site owner what
 * to fix rather than "try again later", which would be false.
 */
final class PrivateStorageUnavailable extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('private storage unavailable: ' . $reason);
    }
}
