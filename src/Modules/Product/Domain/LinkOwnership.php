<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * Whether a marketplace row merely POINTS at a WooCommerce product, or
 * actually owns it.
 *
 * This exists because a trial import from Dokan created rows linked to
 * products another plugin runs, and everything that asks "is this ours?"
 * answered yes. Measured on a real site: after a dry-run import, Dokan's own
 * vendor product answered `vendor_stopped` and `is_purchasable()` went false —
 * a migration that had written nothing of Dokan's had still changed how
 * Dokan's shop behaved. The owner named exactly that: «یکسان‌بودن هش داده‌ها
 * به‌تنهایی ثابت نمی‌کند رفتار خرید دکان تغییر نکرده».
 *
 * So a link now carries its own meaning:
 *
 *   Marketplace  this row's product is the marketplace's to sell, guard,
 *                withdraw and project. Everything created by this plugin's
 *                own flow is this, and it is the default.
 *   Observed     the row knows which WooCommerce product corresponds to it,
 *                and nothing else follows. It is invisible to the purchase
 *                policy, to the storefront stop, to the projector and to the
 *                vendor's catalogue — a note in a ledger, not a claim.
 *
 * The move from Observed to Marketplace is an explicit act by a manager with
 * its own button, its own warning and its own audit line («انتقال مالکیت
 * عملیاتی»), because it is the moment this plugin starts deciding whether
 * somebody else's product may be sold.
 */
enum LinkOwnership: string
{
    case Marketplace = 'marketplace';
    case Observed = 'observed';

    /** Whether this plugin may decide anything about the linked product. */
    public function isOperational(): bool
    {
        return $this === self::Marketplace;
    }
}
