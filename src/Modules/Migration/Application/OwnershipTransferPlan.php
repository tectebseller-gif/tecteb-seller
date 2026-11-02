<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\Product;

/**
 * One shop's `observed` rows, bucketed by whether accepting management would
 * need a human to look at anything — and NOTHING is written.
 *
 * ### The problem this is for, stated as a number
 *
 * Ownership of 184 products has to move one product at a time, from a page
 * that lists every observed row and, for each one, reads the WooCommerce
 * product THREE times (`risksFor()`, `storefrontReadable()`,
 * `storefrontDrift()`). That is 552 product loads in one admin request before
 * a single button is drawn, and the page's own `observed()` call has no cursor
 * and a default `LIMIT 200` — so past two hundred rows the only screen that
 * can transfer is also the screen that cannot reach them (`alpha.85`, one
 * module over).
 *
 * The owner's §4: «برای کاهش ۱۸۴ عملیات دستی، یک روش انتقال مرحله‌ای و قابل
 * توقف … بدون انتقال خودکار، با پیش‌نمایش، تأیید صریح مدیر برای هر دسته
 * عملیات، ثبت ممیزی و امکان توقف در اولین خطا.»
 *
 * ### Why this is safe, and it is a proof rather than a promise
 *
 * `ApplyOwnershipTransfer` calls `TransferOwnership::take()` with EMPTY
 * acknowledgement and EMPTY `seenShop` arrays. Those are the only two ways a
 * risk or a drift can be waived, so a row carrying either is refused by the
 * existing guard — not by a new check here, and not by this planner's
 * bucketing being correct. The batch therefore cannot move a product the
 * single button would refuse, whatever this class decides.
 *
 * What the bucketing does is stop the manager being shown a batch that will
 * fail: a row with risks or drift goes to `needs_decision`, is listed BY NAME
 * with its reason, and is left to the per-product screen where the actual
 * values are printed beside the tick. That is the half a batch cannot do
 * honestly — a risk is the same sentence for every product, but a drift is a
 * VALUE somebody has to read (`alpha.49`).
 *
 * ### Read-only, enforced by the absence of a method
 *
 * There is no write of any kind here, and
 * `StagedOwnershipTransferIsSafeTest` asserts that from this file's bytes.
 * One `detail()` read per row, used for BOTH answers, because that is the
 * whole cost saving (`CatalogueReaderInterface::preservationRisksFrom()`).
 */
final class OwnershipTransferPlan
{
    /** Rows read per page. The cursor carries the position between pages. */
    public const SCAN_LIMIT = 50;

    /**
     * How many products one explicit confirmation may move.
     *
     * A decision about the size of ONE request, not about the catalogue:
     * every unit is its own compensated write (`TransferOwnership::move()`),
     * the batch stops at the first failure, and a smaller number only means
     * more confirmations.
     */
    public const BATCH_LIMIT = 25;

    /** No risks, no drift, a storefront product, still `observed`. */
    public const READY = 'ready';

    /** Risks or drift: a human has to see the values. Excluded by name. */
    public const NEEDS_DECISION = 'needs_decision';

    /** The shop could not be read at all. «نمی‌دانم» is not «they agree». */
    public const UNREADABLE = 'unreadable';

    /** An imported row with no WooCommerce product behind it. */
    public const NO_STOREFRONT_PRODUCT = 'no_storefront_product';

    /** @var list<string> every bucket, so a page cannot forget one. */
    public const BUCKETS = [self::READY, self::NEEDS_DECISION, self::UNREADABLE, self::NO_STOREFRONT_PRODUCT];

    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly TransferOwnership $transfer,
        private readonly ?CatalogueReaderInterface $catalogue = null,
        private readonly ?ImportExistingProducts $intake = null
    ) {
    }

    /**
     * One page of one shop's observed rows, bucketed.
     *
     * @return array{
     *     vendor_user_id:int,
     *     rows:list<array{product_id:int, wc_product_id:int, title:string, bucket:string, risks:list<string>, drift:list<string>}>,
     *     buckets:array<string, list<int>>,
     *     counts:array<string, int>,
     *     ready:list<int>,
     *     digest:string,
     *     cursor:int,
     *     finished:bool,
     *     observed_total:int,
     *     checked:bool
     * }
     */
    public function plan(int $vendorUserId, int $afterId = 0, int $limit = self::SCAN_LIMIT): array
    {
        $tally = $this->products->ownershipTallyForVendor($vendorUserId);
        $rows = $this->products->observedForVendorAfter($vendorUserId, $afterId, $limit);

        $out = [];
        $buckets = array_fill_keys(self::BUCKETS, []);
        $cursor = $afterId;
        foreach ($rows as $product) {
            $productId = (int) ($product->id ?? 0);
            if ($productId > $cursor) {
                $cursor = $productId;
            }
            $row = $this->classify($product);
            $out[] = $row;
            $buckets[$row['bucket']][] = $productId;
        }

        $ready = $buckets[self::READY];
        return [
            'vendor_user_id' => $vendorUserId,
            'rows' => $out,
            'buckets' => $buckets,
            'counts' => array_map('count', $buckets),
            // Capped here rather than on the page, so the number the form
            // carries and the number the service will accept are one number.
            'ready' => array_slice($ready, 0, self::BATCH_LIMIT),
            'digest' => self::digestOf(array_slice($ready, 0, self::BATCH_LIMIT)),
            'cursor' => $cursor,
            // «I reached the end» is derived from the page being short, never
            // from a count: a read that came back empty for any other reason
            // must not read as «nothing left» (`alpha.85`).
            'finished' => count($rows) < $limit,
            'observed_total' => (int) $tally['observed'],
            // Whether the risk and drift questions could be asked at all. A
            // build without the catalogue reader or the intake answers
            // neither, and the screen says the check did not run rather than
            // implying it passed — `TransferOwnership`'s own rule for the
            // same two optional dependencies.
            'checked' => $this->catalogue !== null && $this->intake !== null,
        ];
    }

    /**
     * Which bucket one row is in, from ONE read of its WooCommerce product.
     *
     * @return array{product_id:int, wc_product_id:int, title:string, bucket:string, risks:list<string>, drift:list<string>}
     */
    private function classify(Product $product): array
    {
        $productId = (int) ($product->id ?? 0);
        $wcProductId = (int) ($product->wcProductId ?? 0);
        $base = [
            'product_id' => $productId,
            'wc_product_id' => $wcProductId,
            'title' => (string) ($product->details->title ?? ''),
            'risks' => [],
            'drift' => [],
        ];
        if ($wcProductId <= 0) {
            return $base + ['bucket' => self::NO_STOREFRONT_PRODUCT];
        }
        if ($this->catalogue === null || $this->intake === null) {
            // Not «ready». A build that cannot ask whether anything would be
            // lost must not answer that nothing would.
            return $base + ['bucket' => self::UNREADABLE];
        }
        $detail = $this->catalogue->detail($wcProductId);
        if ($detail === []) {
            return $base + ['bucket' => self::UNREADABLE];
        }
        $risks = $this->catalogue->preservationRisksFrom($detail);
        $drift = $this->intake->driftFrom($product, $detail);
        $base['risks'] = array_keys($risks);
        $base['drift'] = array_values(array_map(
            static fn (array $pair): string => (string) ($pair['label'] ?? ''),
            $drift
        ));
        return $base + [
            'bucket' => $risks === [] && $drift === [] ? self::READY : self::NEEDS_DECISION,
        ];
    }

    /**
     * The fingerprint of an ordered id list.
     *
     * Public and static so the page and the guard cannot compute it two ways
     * — the mistake `alpha.55` found in its own new code was a permission
     * that did not describe the thing it was given for.
     *
     * @param list<int> $productIds
     */
    public static function digestOf(array $productIds): string
    {
        $ids = array_map('intval', $productIds);
        return substr(hash('sha256', implode(',', $ids)), 0, 32);
    }
}
