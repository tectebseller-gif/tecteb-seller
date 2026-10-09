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

    /** @var list<string> fields a vendor may change on a live product */
    public const IMMEDIATE = ['stock', 'sku', 'minPurchase', 'maxPurchase'];

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
