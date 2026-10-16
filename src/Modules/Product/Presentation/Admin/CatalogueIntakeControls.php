<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation\Admin;

/**
 * Everything the intake half of the one list needs, read before the view runs.
 *
 * The view holds no container and asks no repository — the rule
 * `ProductCatalogueRow` states for a row, applied to the controls under it.
 * That matters more here than for a row: the shop picker's results come from a
 * search the manager typed, and a view that ran that search itself would be a
 * second place deciding which shops a manager may assign a product to.
 *
 * `null` in place of this object means «this manager may not take products
 * in», and the checkboxes, the picker and the buttons are then not drawn at
 * all. A disabled control that posts nothing is worse than an absent one: it
 * looks like a permission somebody could get by clicking harder.
 */
final class CatalogueIntakeControls
{
    /**
     * @param string $nonceField the rendered nonce input for the intake form
     * @param string $shopQuery what the manager typed into the shop search
     * @param list<array{vendor_user_id:int, store_name:string, source:string}> $shopMatches
     *        the shops that match it — bounded, because a term matching three
     *        hundred shops is not a choice but a different search
     * @param int    $assignTo the shop that is currently chosen, or 0
     * @param string $assignName the chosen shop's name, so the page can say
     *        which one it is without asking again
     * @param string $dokanUrl where a Dokan product's own transfer path lives,
     *        so a row can point at it instead of leaving the manager to find
     *        an unrelated screen
     * @param bool   $tooManyShopMatches whether the search hit its ceiling,
     *        said out loud rather than silently truncated
     */
    public function __construct(
        public readonly string $nonceField,
        public readonly string $shopQuery = '',
        public readonly array $shopMatches = [],
        public readonly int $assignTo = 0,
        public readonly string $assignName = '',
        public readonly string $dokanUrl = '',
        public readonly bool $tooManyShopMatches = false
    ) {
    }
}
