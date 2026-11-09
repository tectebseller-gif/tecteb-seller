<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Application;

/**
 * Where a reader can open one WooCommerce order.
 *
 * ⛔ **A NEW INTERFACE RATHER THAN A METHOD ON `DependencyProbeInterface`.**
 * That interface is implemented by the real probe and by a double every
 * contract and database test builds; adding a method to it breaks every one of
 * them and, worse, breaks the evidence probes of earlier rounds that are the
 * only proof those rounds closed what they said (`alpha.32`, `alpha.55`).
 * A narrow interface nobody else implements costs one binding.
 *
 * `null` means «I cannot build one» — WooCommerce absent, or an id that is not
 * an order. The reader then prints the number with no link and says why,
 * because a link that goes nowhere is worse than no link (`alpha.88`).
 */
interface OrderAdminUrlInterface
{
    /**
     * The absolute wp-admin URL that edits this order, or `null`.
     *
     * Must cost no database query: the per-line report calls this once per
     * row, and a page that loaded an order to find its address would be the
     * page `alpha.96` had to take apart.
     */
    public function editUrl(int $wcOrderId): ?string;
}
