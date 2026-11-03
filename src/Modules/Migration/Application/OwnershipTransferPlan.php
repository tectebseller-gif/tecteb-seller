<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Modules\Migration\Domain\PreservationRisk;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ProjectedStatus;
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

    /** No blocking risk, no drift, a settled status, a storefront product. */
    public const READY = 'ready';

    /**
     * A publish state a projection would change, or one nobody could read.
     *
     * The gravest of the three stoppers, so it is reported first when a row
     * has more than one: the others cost a setting, this one takes a product
     * its vendor is selling off sale. A re-snapshot clears it by recording
     * the stamp.
     */
    public const STATUS_BLOCKED = 'status_blocked';

    /**
     * A blocking preservation risk: a human has to decide, per product.
     *
     * `PreservationRisk::BLOCKING` only. A row whose findings are all
     * disclosures — a shipping class, tags, dimensions — is `ready`, because
     * the projector has no setter for any of them and their own sentences
     * have always said so.
     */
    public const RISK_REAL = 'risk_real';

    /**
     * Values that differ from the shop, and nothing else wrong.
     *
     * Fixable without a human reading each one, because a re-snapshot copies
     * the SHOP's value into our row rather than the other way round — so
     * «قابل اصلاح» here is a statement about a path that exists, not a hope.
     */
    public const DRIFT_FIXABLE = 'drift_fixable';

    /** The shop could not be read at all. «نمی‌دانم» is not «they agree». */
    public const UNREADABLE = 'unreadable';

    /** An imported row with no WooCommerce product behind it. */
    public const NO_STOREFRONT_PRODUCT = 'no_storefront_product';

    /**
     * @var list<string> every bucket, so a page cannot forget one.
     *
     * ⛔ `needs_decision` is gone and was NOT kept beside these. The owner's
     * §4 asks for «تعداد آماده انتقال، اختلاف‌های قابل اصلاح و ریسک‌های واقعی
     * جدا», and a vague bucket alongside three precise ones is the «one
     * question, two answers» mistake that produced `alpha.66`'s green banner
     * over a shut gate.
     */
    public const BUCKETS = [
        self::READY,
        self::DRIFT_FIXABLE,
        self::STATUS_BLOCKED,
        self::RISK_REAL,
        self::UNREADABLE,
        self::NO_STOREFRONT_PRODUCT,
    ];

    /**
     * The buckets a re-snapshot batch can clear on its own.
     *
     * ⛔ Rows that ALSO carry a blocking risk are deliberately absent, even
     * though a refresh would clear their drift too. The promise this list
     * makes is «after the batch, these rows are ready», and a row with a real
     * risk would still not be — so including it would make the only number
     * the manager can check untrue. Those rows keep the per-product screen,
     * where the refresh button has existed since `alpha.56`.
     *
     * @var list<string>
     */
    public const REFRESHABLE = [self::DRIFT_FIXABLE, self::STATUS_BLOCKED];

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
     *     rows:list<array{product_id:int, wc_product_id:int, title:string, bucket:string, why:string, risks:list<string>, disclosed:list<string>, drift:list<string>, status:string, shop_status:string, would_write:string}>,
     *     buckets:array<string, list<int>>,
     *     counts:array<string, int>,
     *     ready:list<int>,
     *     digest:string,
     *     refreshable:list<int>,
     *     refresh_digest:string,
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
        // The second offer list, from the same single read: the rows a
        // re-snapshot can make ready without a human reading a value.
        $refreshable = [];
        foreach (self::REFRESHABLE as $bucket) {
            foreach ($buckets[$bucket] as $id) {
                $refreshable[] = $id;
            }
        }
        sort($refreshable);
        $refreshable = array_slice($refreshable, 0, self::BATCH_LIMIT);
        return [
            'vendor_user_id' => $vendorUserId,
            'rows' => $out,
            'buckets' => $buckets,
            'counts' => array_map('count', $buckets),
            // Capped here rather than on the page, so the number the form
            // carries and the number the service will accept are one number.
            'ready' => array_slice($ready, 0, self::BATCH_LIMIT),
            'digest' => self::digestOf(array_slice($ready, 0, self::BATCH_LIMIT)),
            // Capped and fingerprinted exactly as `ready` is, and with the
            // SAME function — so neither form can carry a list the service
            // computes differently (`alpha.55`).
            'refreshable' => $refreshable,
            'refresh_digest' => self::digestOf($refreshable),
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
            'disclosed' => [],
            'drift' => [],
            'status' => ProjectedStatus::UNREADABLE,
            'shop_status' => '',
            'would_write' => '',
        ];
        if ($wcProductId <= 0) {
            return $base + [
                'bucket' => self::NO_STOREFRONT_PRODUCT,
                'why' => 'این ردیف هیچ محصول ووکامرسی ندارد؛ انتقال مالکیت چیزی برای انتقال پیدا نمی‌کند.',
            ];
        }
        if ($this->catalogue === null || $this->intake === null) {
            // Not «ready». A build that cannot ask whether anything would be
            // lost must not answer that nothing would.
            return $base + [
                'bucket' => self::UNREADABLE,
                'why' => 'بررسی اجرا نشد: این نصب خوانندهٔ کاتالوگ را ندارد.',
            ];
        }
        $detail = $this->catalogue->detail($wcProductId);
        if ($detail === []) {
            return $base + [
                'bucket' => self::UNREADABLE,
                'why' => 'محصول ووکامرس خوانده نشد؛ «نمی‌دانم» با «تفاوتی نیست» یکی نیست.',
            ];
        }
        $risks = $this->catalogue->preservationRisksFrom($detail);
        $drift = $this->intake->driftFrom($product, $detail);
        $blocking = PreservationRisk::blockingIn($risks);
        $shopStatus = (string) ($detail['status'] ?? '');
        // The SAME pure rule the guard reaches, from the read already done.
        $status = ProjectedStatus::decide($product->status, $product->mirroredStatus, $shopStatus);
        $base['risks'] = $blocking;
        $base['disclosed'] = PreservationRisk::disclosuresIn($risks);
        $base['drift'] = array_values(array_map(
            static fn (array $pair): string => (string) ($pair['label'] ?? ''),
            $drift
        ));
        $base['status'] = $status;
        $base['shop_status'] = $shopStatus;
        $base['would_write'] = ProjectedStatus::statusOf($product->status);

        // PRECEDENCE, AND IT IS A DECISION RATHER THAN AN ORDER OF READING.
        //
        // A row can carry all three. What a manager must act on first is the
        // one that costs the most if ignored, so the publish state comes
        // before a setting and a setting before a stale value — and each row
        // prints ONE reason, because a row listing three reasons is a row
        // nobody reads to the end.
        if (!ProjectedStatus::settled($status)) {
            return $base + [
                'bucket' => self::STATUS_BLOCKED,
                'why' => $status === ProjectedStatus::UNREADABLE
                    ? 'وضعیت انتشار محصول در ووکامرس خوانده نشد؛ تا روشن‌نشدنش انتقال انجام نمی‌شود.'
                    : sprintf(
                        'اولین همگام‌سازی وضعیت «%s» را به «%s» تغییر می‌دهد. با «بازخوانی اطلاعات» وضعیت فعلی ثبت می‌شود و این مانع برداشته می‌شود.',
                        $shopStatus,
                        (string) $base['would_write']
                    ),
            ];
        }
        if ($blocking !== []) {
            return $base + [
                'bucket' => self::RISK_REAL,
                'why' => sprintf(
                    'ریسک واقعی: %s. تصمیمش با مدیر است و در صفحهٔ همین محصول با مقدارهایش گرفته می‌شود.',
                    implode('، ', array_map(
                        static fn (string $key): string => (string) ($risks[$key] ?? $key),
                        $blocking
                    ))
                ),
            ];
        }
        if ($drift !== []) {
            return $base + [
                'bucket' => self::DRIFT_FIXABLE,
                'why' => sprintf(
                    'اختلاف قابل اصلاح در: %s. «بازخوانی اطلاعات» مقدار فروشگاه را در ردیف بازارگاه می‌نویسد.',
                    implode('، ', $base['drift'])
                ),
            ];
        }
        return $base + ['bucket' => self::READY, 'why' => ''];
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
