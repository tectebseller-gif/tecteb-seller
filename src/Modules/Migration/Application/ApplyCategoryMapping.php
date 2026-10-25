<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;

/**
 * The ONE place a saved mapping reaches a product that is already here.
 *
 * Saving the option decides what a source category means. It moves nothing:
 * the rows the import created carry `category_key = ''` and keep carrying it,
 * because a mapping written today cannot be allowed to rewrite a catalogue by
 * itself — «دستهٔ محصولات موجود باید در مسیری مشخص، قابل‌آزمایش و با اقدام
 * صریح مدیر اعمال شود».
 *
 * So this class exists, it is reached from one button, and what it is allowed
 * to touch is one column:
 *
 *   • `category_key`, on rows an import stamped, that are still empty, to a
 *     target that resolves to a real marketplace category RIGHT NOW.
 *
 * and what it cannot touch is everything else. Not a WooCommerce term — this
 * class never names one, and `NoParallelTaxonomyTest` already forbids term
 * writes anywhere under `src/`. Not `link_ownership`, not the status, not
 * `can_sell`, not a ledger entry, not a commission. Not a product: nothing
 * here creates one, so «بدون ورود مجدد یا ساخت محصول تکراری» is the absence of
 * a capability rather than a promise — there is no `create`, no `enqueue` and
 * no import on this path at all.
 *
 * **The manager approves what was shown.** The form carries, per product, the
 * target the page printed. This re-derives the target from the map and the
 * category directory as they are at the moment of the click and refuses the
 * row by name when the two differ (`target_moved`, with both values). A click
 * on a page rendered ten minutes ago is not consent to whatever the option
 * says now — the `alpha.49` rule, one decision over.
 *
 * **One row is one statement.** The condition — stamped, still empty — lives
 * inside the `UPDATE` (`alpha.50`), so there is no window between asking and
 * writing and no half-written unit to roll back. A row that fails is named and
 * the rest still go; nothing here needs a transaction, and claiming one would
 * describe a guarantee that is not being made.
 */
final class ApplyCategoryMapping
{
    public function __construct(
        private readonly CategoryMapping $mapping,
        private readonly ProductRepositoryInterface $products,
        private readonly AuditLogger $audit,
        private readonly CapabilityCheckerInterface $capabilities
    ) {
    }

    /**
     * Apply the mapping to the products named, and to no others.
     *
     * @param array<int,string> $shown product id → the target the page printed
     * @return array{
     *     written:int,
     *     outcomes:array<int,string>,
     *     refused:string
     * }
     */
    public function apply(array $shown): array
    {
        if (!$this->capabilities->can(Capabilities::REVIEW_VENDOR)) {
            return ['written' => 0, 'outcomes' => [], 'refused' => 'not_allowed'];
        }
        if ($shown === []) {
            return ['written' => 0, 'outcomes' => [], 'refused' => 'nothing_selected'];
        }

        // ONE plan, read once. Every decision below comes out of it, so the
        // answer this method gives is the answer the preview would give —
        // two derivations of the same thing is how the two drift apart
        // (`alpha.78`).
        $plan = $this->mapping->plan();
        if (!$plan['source_readable']) {
            return ['written' => 0, 'outcomes' => [], 'refused' => 'source_unreadable'];
        }
        $now = [];
        foreach ($plan['apply'] as $row) {
            $now[(int) $row['product_id']] = $row;
        }
        $heldFor = [];
        foreach ($plan['held'] as $reason => $rows) {
            foreach ($rows as $row) {
                $heldFor[(int) $row['product_id']] = (string) $reason;
            }
        }

        $outcomes = [];
        $written = 0;
        foreach ($shown as $productId => $target) {
            $productId = (int) $productId;
            $target = trim((string) $target);
            if ($productId <= 0) {
                continue;
            }
            $row = $now[$productId] ?? null;
            if ($row === null) {
                // Not applicable any more, and WHY is the reason the plan
                // gives rather than one word for six situations.
                $outcomes[$productId] = $heldFor[$productId] ?? $this->whyNotWaiting($productId);
                continue;
            }
            if ($target === '' || $target !== (string) $row['target']) {
                $outcomes[$productId] = 'target_moved';
                continue;
            }
            $code = $this->products->setCategoryWhenEmpty($productId, (string) $row['target']);
            $outcomes[$productId] = $code === '' ? 'written' : $code;
            if ($code === '') {
                $written++;
            }
        }

        if ($written > 0) {
            // What was decided, and on how many rows. The ids are not written
            // into the note: on a catalogue-wide application that is hundreds
            // of numbers in one row, and the rows themselves carry the result.
            $this->audit->log(
                AuditEventCatalog::DOKAN_CATEGORY_APPLIED,
                $this->capabilities->currentUserId() ?? 0,
                'product_category',
                null,
                ['products' => $written, 'requested' => count($shown)]
            );
        }

        return ['written' => $written, 'outcomes' => $outcomes, 'refused' => ''];
    }

    /**
     * Why a submitted product is not in the plan — READ, never guessed.
     *
     * ⛔ Three answers, because they ask for different things. «Somebody chose
     * a category» is the one the manager has to see: it means a human decided
     * while that page was on screen and this refused to overwrite them. «No
     * such row» is a stale form. And «empty, yet not listed» is the plan's own
     * page cap — a row the preview never showed, which must NOT be written
     * here, because the target it would be written with was never derived.
     *
     * The row is re-read rather than inferred from the plan's absence
     * (`alpha.28`): absence fuses all three, and calling the write to find out
     * would write on the third one.
     */
    private function whyNotWaiting(int $productId): string
    {
        $product = $this->products->find($productId);
        if ($product === null) {
            return 'not_found';
        }
        return $product->details->categoryKey !== '' ? 'already_set' : 'not_waiting';
    }
}
