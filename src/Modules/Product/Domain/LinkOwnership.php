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
 *                policy, to the storefront stop and to the projector — a note
 *                in a ledger, not a claim.
 *
 * ⛔ CORRECTION. This block used to say an observed row is also invisible to
 * «the vendor's catalogue», and that is not true of the row LISTS:
 * `forVendor()`, `countsByStatus()` and `countsByStatusForManager()` filter on
 * the vendor and the status and never on this column — measured, the column
 * name appears in none of those queries. Exactly four reads ask for
 * `marketplace`: `findByWcProduct()`, `allForVendor()`, `stockSummary()` and
 * `projected()`. So what an observed row is kept out of is the OPERATIONAL
 * CLAIM, not the catalogue a human reads; the row is there and it is counted,
 * which is the honest answer to «is the imported product still ours». The
 * sentence was wider than the code and promised a guarantee nothing enforced.
 * Behaviour is unchanged by this correction: it is a comment.
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

    /**
     * The same string as `Marketplace->value`, usable as a DEFAULT PARAMETER.
     *
     * An enum case cannot be a constant expression in a promoted property
     * default on PHP 8.1 — `EnumCase->value` inside a `const` is 8.2 and
     * fatals at COMPILE time on 8.1, which takes the whole site down rather
     * than one page (`alpha.7`, F-11). So `Product` defaults its ownership to
     * this literal instead.
     */
    public const MARKETPLACE_VALUE = 'marketplace';

    /** Whether this plugin may decide anything about the linked product. */
    public function isOperational(): bool
    {
        return $this === self::Marketplace;
    }
}
