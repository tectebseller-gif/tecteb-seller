<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * Which edits to a LIVE product need the manager again (§4.2, A.1).
 *
 * The split is the whole point: a vendor who sold three units must be able to
 * correct the stock immediately, and a vendor who changes what the product
 * claims to be — its title, its category, its medical specification — must
 * not be able to do that silently on a product buyers are already looking at.
 *
 * Price is deliberately on the sensitive side. A.1 says the vendor sets the
 * price, and they do: on a draft, freely. On a published product the change
 * goes through a revision, because price is what the marketplace's commission
 * and the buyer's decision both rest on.
 */
final class SensitiveChange
{
    /** @var list<string> */
    public const SENSITIVE = [
        // `description` is here from `alpha.41`, beside `shortDescription`
        // and for the identical reason: it is what the product CLAIMS to be,
        // on a page buyers are already reading. Leaving it off the list would
        // have let a live product's whole description change with nobody
        // asked — and it would also have put the one new field of §4 outside
        // the approval baseline and the manager-edit protection that every
        // other text field has. No sales gate moves: a proposal still waits
        // for the manager exactly as a short-description change does.
        'title', 'type', 'categoryKey', 'brand', 'shortDescription', 'description',
        'priceMinor', 'salePriceMinor', 'saleFrom', 'saleTo',
        'weightGrams', 'dimensions', 'taxClass',
    ];

    /**
     * @var list<string> fields a vendor may change on a live product, applied
     *                   at once at every status
     */
    public const IMMEDIATE = ['sku', 'minPurchase', 'maxPurchase', 'purchaseStep'];

    /**
     * Immediate while the vendor may still edit in place, a PROPOSAL once the
     * product is live — the owner's decision of 11 October 2026.
     *
     * `stock` sat in `IMMEDIATE` from `alpha.6` with the reason written above:
     * a queue between a sale and its stock is how a shop oversells. The owner
     * decided the other way, and the trade is named rather than buried — a
     * vendor can no longer take a sold-out product down themselves on a live
     * listing; they say so and a manager agrees.
     *
     * What stays immediate is WooCommerce's OWN cycle. A sale reducing stock
     * and a refund restoring it never come through here: they are
     * `pullStock()` and `increaseStock()`, and neither asks anybody.
     *
     * It is NOT in `SENSITIVE`, because `between()` answers «which fields need
     * the manager REGARDLESS of status» and this one depends on the status. The
     * caller that knows the status asks `liveStockChanged()`.
     *
     * @var list<string>
     */
    public const IMMEDIATE_UNTIL_LIVE = ['stock'];

    /**
     * Whether a live product's stock is being changed, measured against the
     * number that is EFFECTIVE rather than the one the row remembers.
     *
     * The effective number is WooCommerce's when there is a storefront row
     * (ADR-008 makes ours the mirror), which is why it is passed in: this is a
     * domain rule and the domain does not read the shop.
     */
    public static function liveStockChanged(int $effectiveStock, ProductDetails $proposed): bool
    {
        return $effectiveStock !== $proposed->stock;
    }

    /**
     * @return list<string> the sensitive fields that actually differ
     */
    public static function between(ProductDetails $current, ProductDetails $proposed): array
    {
        $changed = [];
        foreach (self::SENSITIVE as $field) {
            if ($current->{$field} !== $proposed->{$field}) {
                $changed[] = $field;
            }
        }
        return $changed;
    }

    /** @param array<string,string> $currentSpecs @param array<string,string> $proposedSpecs */
    public static function specsChanged(array $currentSpecs, array $proposedSpecs): bool
    {
        ksort($currentSpecs);
        ksort($proposedSpecs);
        return $currentSpecs !== $proposedSpecs;
    }
}
