<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts;

/**
 * Short-lived, read-once storage for state that must survive ONE redirect.
 *
 * WHY THIS EXISTS: the vendor area answers every write with a redirect, so a
 * refresh cannot repeat an upload. That redirect is also where a message's
 * details used to die: "the file is larger than the limit" arrived at the
 * next request with the code but without the limit, and the sentence printed
 * a zero. Putting the details in the URL would publish them and invite
 * tampering; recomputing them in the view would duplicate the rule that
 * produced the message. A flash entry keyed to the user is neither.
 *
 * take() is destructive on purpose: a message belongs to the request that
 * follows the write, and to no later one.
 */
interface FlashStoreInterface
{
    /** @param array<string,mixed> $payload */
    public function put(string $key, array $payload, int $ttlSeconds): bool;

    /** Reads and removes in one step. @return array<string,mixed>|null */
    public function take(string $key): ?array;
}
