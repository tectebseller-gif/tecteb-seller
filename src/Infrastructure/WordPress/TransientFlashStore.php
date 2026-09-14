<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\FlashStoreInterface;

/**
 * Transients: WordPress's own expiring storage, so a flash that is never
 * collected disappears on its own instead of accumulating rows.
 *
 * Reading is deliberately tolerant. A transient can come back false (expired,
 * evicted from an object cache, never written), and a message that cannot be
 * decorated must still render its sentence — never a fatal and never a wrong
 * number. VendorMessages carries that half of the contract.
 */
final class TransientFlashStore implements FlashStoreInterface
{
    private const PREFIX = 'tmc_flash_';

    public function put(string $key, array $payload, int $ttlSeconds): bool
    {
        return set_transient(self::PREFIX . $key, $payload, max(1, $ttlSeconds));
    }

    public function take(string $key): ?array
    {
        $stored = get_transient(self::PREFIX . $key);
        delete_transient(self::PREFIX . $key);
        return is_array($stored) ? $stored : null;
    }
}
