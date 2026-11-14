<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Application;

use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;

/**
 * How many marketplace products would earn the marketplace NOTHING — read only.
 *
 * ⛔ **WHY THIS EXISTS, AND WHY IT IS NOT A DEFECT REPORT ABOUT EITHER PLUGIN.**
 *
 * Three facts, each of them the owner's own decision and each verified in
 * source:
 *
 *  1. A sale reads exactly two meta keys for a commission —
 *     `WpTectebCommissionSource::TYPE_META` and `::VALUE_META`.
 *  2. Nothing under `src/` reads any Dokan commission key. Measured by search:
 *     zero hits.
 *  3. `RecordCommission` treats a missing rule as `none`, and «none» is A
 *     RESULT RATHER THAN A REFUSAL: «نبود نرخ مانع … فروش نیست».
 *
 * Put together they say something no screen said: a product whose commission
 * only ever lived in Dokan's metadata sells, after Dokan is gone, at ZERO
 * marketplace commission — quietly, with a ledger that balances perfectly,
 * because a zero commission IS a balanced transaction. The owner's own
 * 2026-11-14 export counts 148 main products carrying Dokan commission
 * metadata, so the population is not hypothetical.
 *
 * What was missing was never a guard. It was a NUMBER. So this class counts,
 * names the ids, and writes nothing.
 *
 * **It asks the same reader the sale asks.** Not a query of its own: a second
 * query about one rule is how two screens come to disagree about one site
 * (`alpha.78`). `forItem()` answers «the variation's own rule, else the
 * parent's», and that is the answer a sale would get.
 *
 * **And its ceiling is announced.** A manager's page is not a job
 * (`alpha.92`): `MAX_PAGES` pages of `PAGE` rows, `finished` says whether the
 * end was reached, and a partial count is NEVER printed as a total.
 *
 * ⛔ **Scope, said rather than implied.** This counts PRODUCT rows, because a
 * marketplace row is a product. A variation carrying its own rule is read by
 * `forItem()` only when a sale names it, so a variation whose parent has a
 * rule is covered here through its parent, and a variation that CONTRADICTS
 * its parent is not visible at this level. The CLI tool
 * `tools/production-audit.php products` walks `product` AND
 * `product_variation` and is the place that answers per variation — plus the
 * one question this class cannot ask at all, because no Dokan source is
 * readable from this repository: WHICH foreign key held the old commission.
 */
final class CommissionCoverage
{
    /** Rows per page. */
    public const PAGE = 50;

    /** The announced ceiling: this many pages, and no more. */
    public const MAX_PAGES = 40;

    /** This product earns the marketplace a commission. */
    public const EARNS = 'earns';

    /** Somebody decided «no commission» for this product, explicitly. */
    public const DECIDED_NONE = 'decided_none';

    /**
     * ⛔ Nobody has decided anything, so the sale earns NOTHING.
     *
     * Not an error and not a refusal — the sale goes ahead. It is the one
     * bucket that needs a human before the marketplace opens for business.
     */
    public const NO_RULE = 'no_rule';

    /** A marketplace row with no WooCommerce product behind it. */
    public const NO_STOREFRONT = 'no_storefront_product';

    /** How many ids to carry back; the count is never capped by this. */
    private const NAMES = 500;

    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly TectebCommissionSourceInterface $rules
    ) {
    }

    /**
     * @return array{
     *     counts: array<string,int>,
     *     rows: int,
     *     uncovered: list<int>,
     *     uncovered_total: int,
     *     named_all: bool,
     *     finished: bool,
     *     ceiling: int
     * }
     */
    public function scan(): array
    {
        $counts = [
            self::EARNS => 0,
            self::DECIDED_NONE => 0,
            self::NO_RULE => 0,
            self::NO_STOREFRONT => 0,
        ];
        $uncovered = [];
        $uncoveredTotal = 0;
        $rows = 0;
        $finished = false;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $batch = $this->products->forManager(
                null,
                '',
                self::PAGE,
                $page * self::PAGE
            );
            foreach ($batch as $product) {
                // Only the marketplace's own rows: an `observed` row is a
                // product this marketplace does not sell yet, and counting it
                // would inflate the one number a person acts on.
                if ($product->linkOwnership !== LinkOwnership::MARKETPLACE_VALUE) {
                    continue;
                }
                $rows++;
                $wcId = (int) ($product->wcProductId ?? 0);
                if ($wcId <= 0) {
                    $counts[self::NO_STOREFRONT]++;
                    continue;
                }
                ['rule' => $rule, 'source' => $source] = $this->rules->forItem($wcId);
                if ($source === TectebCommissionSourceInterface::FROM_NONE) {
                    $counts[self::NO_RULE]++;
                    $uncoveredTotal++;
                    if (count($uncovered) < self::NAMES) {
                        $uncovered[] = $product->id;
                    }
                    continue;
                }
                $counts[$rule->isNone() ? self::DECIDED_NONE : self::EARNS]++;
            }
            if (count($batch) < self::PAGE) {
                $finished = true;
                break;
            }
        }

        return [
            'counts' => $counts,
            'rows' => $rows,
            'uncovered' => $uncovered,
            'uncovered_total' => $uncoveredTotal,
            'named_all' => $uncoveredTotal === count($uncovered),
            'finished' => $finished,
            'ceiling' => self::MAX_PAGES * self::PAGE,
        ];
    }
}
