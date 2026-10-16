<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation\Admin;

use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;

/**
 * One line of the manager's list — and deliberately not a product.
 *
 * The page reads everything a row shows before the view runs, so the view
 * holds no container and asks no repository. That was already the rule for the
 * review card; here it does something more: it puts a ceiling on what a long
 * list costs. A row carries a thumbnail URL, a store name and two statuses,
 * and that is all — no gallery, no specification table, no field-by-field
 * comparison against WooCommerce, no decision history. Those belong to one
 * product at a time, on its own page, because that is the only place somebody
 * reads them.
 */
final class ProductCatalogueRow
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $sku,
        public readonly string $storeName,
        /**
         * The marketplace state machine's own status — `null` when there is
         * no marketplace row at all.
         *
         * Nullable from `alpha.56`, and the null is a real state rather than
         * a missing value: a WooCommerce product nobody has imported has no
         * marketplace status, and inventing one («draft»? «published»?) would
         * put a decision of this plugin's on a product it does not run.
         */
        public readonly ?ProductStatus $status,
        public readonly bool $projected,
        /** WooCommerce's own status, read on the spot; '' when the post is gone. */
        public readonly string $shopStatus,
        public readonly string $updatedAt,
        public readonly string $thumbnailUrl,
        /** An unanswered proposal on a published product: the row a manager is looking for. */
        public readonly bool $hasPendingRevision,
        /**
         * An open brand request on this product.
         *
         * Its own flag rather than folded into the one above, because a row
         * with both is waiting on two different decisions and the badge should
         * say so. Appended with a default so every existing construction keeps
         * compiling (`alpha.45`'s convention).
         */
        public readonly bool $hasBrandRequest = false,
        /**
         * Whether this row OWNS its WooCommerce product, or merely points at
         * it.
         *
         * An intake of existing products creates `observed` rows, and they
         * appear in this list beside owned ones — which is exactly what §2 of
         * `alpha.55` asks for: «محصولات فعلی ابتدا بدون تغییر وضعیت انتشار و
         * مالکیت در فهرست مدیر دیده شوند». A manager who cannot tell the two
         * apart would read «this product is in the marketplace» off a row
         * that decides nothing about it, so the row carries the answer and
         * the view prints a badge for the observed case.
         */
        public readonly bool $ownsStorefrontProduct = true,
        /**
         * The WooCommerce product this row is about, when there is one.
         *
         * From `alpha.56` this list holds BOTH halves of the catalogue, so a
         * row can have a WooCommerce product and no marketplace row (`id` is
         * zero) or a marketplace row and no product (`wcProductId` is zero).
         * One of the two is always set; neither is optional in the sense of
         * «unknown».
         */
        public readonly int $wcProductId = 0,
        /** `CatalogueFacets::SOURCE_*` — which half of the catalogue this row is. */
        public readonly string $source = '',
        /** `CatalogueFacets::MANAGED_*` — whether this plugin runs the product. */
        public readonly string $management = '',
        /** The bucket's Persian name: «دکان», «فروشگاه اصلی», «مبهم»… */
        public readonly string $bucketLabel = '',
        /** `CatalogueBucket::*`, so the view can route a Dokan row to the Dokan path. */
        public readonly string $bucket = '',
        /**
         * Whether an intake could run on this row AS IT STANDS, with no
         * decision from anybody.
         *
         * `false` is never a failure — it is a question, and `$blockers`
         * holds the sentence.
         */
        public readonly bool $intakeReady = false,
        /**
         * Why not, in sentences a manager reads.
         *
         * **The LIST no longer prints any of them.** Until `alpha.56` it
         * printed the first one under every row it applied to, which on this
         * owner's catalogue was the same forty-word paragraph about Dokan's
         * transfer path repeated down the page: «جملهٔ تکراری … از زیر هر
         * محصول حذف شود. یک برچسب کوتاه «دکان» و جزئیات در صورت نیاز کافی
         * است.» The short tag is `bucketLabel`, the sentences are on the
         * product's own page in full, and the select column already says a
         * row cannot be taken in from here.
         *
         * The field stays because it is a FACT about the row that the page
         * already has in hand, and the next screen to need it — a bulk
         * preview, an export — should not have to recompute it. Nothing in
         * this plugin reads it today, and that is written down rather than
         * hidden.
         *
         * @var list<string>
         */
        public readonly array $blockers = [],
        /** How many variations a variable product has; 0 for everything else. */
        public readonly int $variations = 0
    ) {
    }
}
