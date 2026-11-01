<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Product\Domain\CategoryLink;

/**
 * The manager's explicit decision about a product that carries ONLY
 * WooCommerce's default term — and the only writer of it.
 *
 * The owner's ruling (option ج,
 * `docs/default-category-investigation-alpha88-fa.md` §5): «پذیرش مستقل،
 * صریح، ممیزی‌پذیر و قابل لغو دسته پیش‌فرض توسط مدیر.» Four properties, and
 * each one is a line of code rather than a sentence:
 *
 *  - **مستقل** — nothing here writes `tmc_products.category_key` or a
 *    `tmc_product_categories` row. Those two already mean «the spec template
 *    to hold this product to» and «a relationship this marketplace claims and
 *    the projector writes back»; using either to settle a counter is the
 *    shape `alpha.60` named worse than the defect it guards.
 *  - **صریح** — `accept()` takes one product id and the term the page
 *    PRINTED, and refuses when that term is not the shop's default any more
 *    (`alpha.49`: a manager's agreement is about the value they were shown,
 *    never about whatever is there when the form arrives).
 *  - **ممیزی‌پذیر** — the stored row itself carries `decided_by` and
 *    `decided_at`, so the decision is self-auditing even if the audit table
 *    write fails. The audit row is a second record, not the only one, and it
 *    is written AFTER the confirmed write (`alpha.86`).
 *  - **قابل لغو** — `revoke()` keeps the row and clears its live key, and it
 *    deliberately asks NOTHING about the product's current state: a decision
 *    you can only withdraw while the world still looks the way it did is not
 *    revocable.
 *
 * ⛔ **And the state is re-read here, never taken from the preview.** The
 * screen that offers the button rendered its rows from an earlier read; by
 * the time the form arrives the product may carry a real category, in which
 * case there is nothing to accept and the honest answer is a named refusal.
 */
final class AcceptDefaultCategory
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly ProductCategoryLinkReaderInterface $links,
        private readonly DefaultCategoryAcceptanceRepositoryInterface $acceptances,
        private readonly ?AuditLogger $audit = null
    ) {
    }

    /**
     * Accept the default term for ONE product.
     *
     * @param int $seenTermId the term id the page printed beside the button.
     *        A form that does not carry it is refused rather than trusted.
     * @return array{ok:bool, code:string, term_id:int}
     */
    public function accept(int $productId, int $seenTermId, int $actorId = 0): array
    {
        if ($productId <= 0) {
            return $this->no('nothing_selected');
        }
        if (!$this->links->isAvailable()) {
            // With no directory every id reads as unresolvable, so «this
            // product carries only the default term» is not a statement this
            // request can make.
            return $this->no('categories_unreadable');
        }
        $row = $this->products->find($productId);
        if ($row === null) {
            return $this->no('not_found');
        }
        if (!$row->imported) {
            // The gate this decision closes counts imported rows only, and a
            // row the marketplace created has a vendor's own category choice
            // on its form. Accepting a default term for one of those would be
            // answering a question nobody asked.
            return $this->no('not_imported');
        }
        $wcId = (int) $row->wcProductId;
        if ($wcId === 0) {
            return $this->no('no_storefront_product');
        }
        if (trim((string) $row->details->categoryKey) !== '' || $row->details->categoryIds !== []) {
            // Already answered, by a mapping or by its own relationship.
            // Recording an acceptance beside it would put two answers on one
            // product and no screen could say which one the gate read.
            return $this->no('category_already_recorded');
        }

        $defaultTermId = $this->links->defaultTermId();
        if ($defaultTermId <= 0) {
            // ⛔ «I cannot tell which term is the default» is not «there is
            // none». Accepting here would store a decision about an unnamed
            // term, and the predicate that reads it compares term ids.
            return $this->no('default_term_unreadable');
        }
        $link = $this->links->linksFor([$wcId])[$wcId] ?? CategoryLink::unreadable($wcId);
        if ($link->state !== CategoryLink::DEFAULT_ONLY) {
            // Every other state has its own answer elsewhere: a real term is
            // recordable by `ApplyCategoryLinks`, an unreadable link is not a
            // decision, and an empty relationship has no term to accept.
            return $this->no('not_default_only', $defaultTermId);
        }
        if ($seenTermId !== $defaultTermId) {
            return $this->no('default_term_moved', $defaultTermId);
        }

        $code = $this->acceptances->accept($productId, $defaultTermId, $actorId);
        if ($code !== '') {
            return $this->no($code, $defaultTermId);
        }

        $this->audit?->log(
            AuditEventCatalog::PRODUCT_DEFAULT_CATEGORY_ACCEPTED,
            $actorId,
            'product',
            (string) $productId,
            ['wc_product_id' => $wcId, 'term_id' => $defaultTermId]
        );
        return ['ok' => true, 'code' => 'default_category_accepted', 'term_id' => $defaultTermId];
    }

    /**
     * Withdraw the live acceptance for ONE product.
     *
     * ⛔ Deliberately asks nothing about the product's categories, the
     * directory or the shop's default setting. A revocation has to work on
     * exactly the states an acceptance can become stale in — that is what it
     * is for — and requiring the old conditions to still hold would make the
     * decision permanent in every case where somebody wants it back.
     *
     * @return array{ok:bool, code:string, term_id:int}
     */
    public function revoke(int $productId, int $actorId = 0): array
    {
        if ($productId <= 0) {
            return $this->no('nothing_selected');
        }
        $row = $this->products->find($productId);
        if ($row === null) {
            return $this->no('not_found');
        }
        $live = $this->acceptances->liveFor([$productId]);
        $termId = $live === null ? 0 : (int) ($live[$productId]['term_id'] ?? 0);

        $code = $this->acceptances->revoke($productId, $actorId);
        if ($code !== '') {
            return $this->no($code, $termId);
        }

        $this->audit?->log(
            AuditEventCatalog::PRODUCT_DEFAULT_CATEGORY_REVOKED,
            $actorId,
            'product',
            (string) $productId,
            ['wc_product_id' => (int) $row->wcProductId, 'term_id' => $termId]
        );
        return ['ok' => true, 'code' => 'default_category_revoked', 'term_id' => $termId];
    }

    /** @return array{ok:bool, code:string, term_id:int} */
    private function no(string $code, int $termId = 0): array
    {
        return ['ok' => false, 'code' => $code, 'term_id' => $termId];
    }
}
