<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Modules\Order\Application\BuyerVerifierInterface;

/**
 * No WooCommerce, so no orders and nothing that can be proved bought.
 *
 * `isAvailable()` is false, and every caller checks it before treating a
 * `false` from the other two as «this person did not buy it». The difference
 * matters: refusing a rating because the purchase could not be verified is an
 * honest «نمی‌توان بررسی کرد», and refusing it as «شما این را نخریده‌اید» would
 * be an accusation this build cannot support.
 */
final class NullBuyerVerifier implements BuyerVerifierInterface
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function boughtLine(int $orderItemId, int $userId): bool
    {
        return false;
    }

    public function boughtProduct(int $userId, int $wcProductId): bool
    {
        return false;
    }
}
