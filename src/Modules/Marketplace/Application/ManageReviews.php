<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Marketplace\Domain\ProductReview;
use Tecteb\Marketplace\Modules\Marketplace\Domain\RatingStatus;
use Tecteb\Marketplace\Modules\Marketplace\Domain\VendorRating;
use Tecteb\Marketplace\Modules\Order\Application\BuyerVerifierInterface;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffArea;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffLevel;

/**
 * «نظرات و امتیاز» — Master A.5, in the exact shape the spec approved and no
 * wider.
 *
 * Three sentences from that section do all the design work here:
 *
 *  1. **«نظر فقط برای خریدار واقعی است.»** Nobody can say anything about a
 *     product they have not bought, or a shop they have not bought from. For a
 *     product that is WooCommerce's own purchase history, asked through the
 *     gateway; for a shop it is a recorded `tmc_order_items` line, which is
 *     also what the rating hangs off — so the proof cannot drift away from the
 *     thing it proves.
 *  2. **«امتیاز محصول و فروشنده جداست.»** Two stores, two averages, and they
 *     are never added together. A product's stars are WooCommerce's, counted
 *     the way the storefront counts them; a shop's stars are this plugin's,
 *     because a shop is a user and there is no post to hang a comment on.
 *  3. **«فروشنده فقط پاسخ می‌دهد.»** There is exactly one method on this class
 *     a vendor may reach that writes anything — `replyToReview()` and
 *     `replyToRating()` — and neither can touch a star, a body or a status.
 *     There is no vendor path to approve, reject, edit or delete, not even a
 *     guarded one, because a guard is a thing somebody later decides to widen.
 *
 * And one sentence from UX §12: **moderation is the manager's**. A rating is
 * invisible to shoppers until a manager approves it, and visible to the shop it
 * is about from the moment it is written — a shop finding out what was said
 * only after the public does would be the wrong way round.
 *
 * Nothing here is automatic. No auto-approval after N days, no sentiment
 * scoring, no «verified» badge beyond the one the spec names («فروشنده
 * تأییدشده», and that one is the vendor module's). The owner's instruction for
 * this round is «امکانات خودکار صرفاً در محدودهٔ مصوب ساخته شوند», and none of
 * those is in the approved scope.
 */
final class ManageReviews
{
    /** How the manager's queue is ordered: oldest undecided first. */
    public const QUEUE_LIMIT = 100;

    public function __construct(
        private readonly RatingRepositoryInterface $ratings,
        private readonly OrderItemRepositoryInterface $items,
        private readonly BuyerVerifierInterface $buyers,
        private readonly StaffAccess $access,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
        private readonly ?CapabilityCheckerInterface $capabilities = null,
        // Optional: a site without WooCommerce still has shops and still has
        // ratings of them. Only the product half goes quiet.
        private readonly ?ProductReviewGatewayInterface $reviews = null,
        private readonly ?Notify $notify = null
    ) {
    }

    // --- the buyer's side ---------------------------------------------------

    /**
     * A buyer rates the shop they bought from.
     *
     * The order line is the argument rather than the vendor, deliberately. A
     * rating of «this shop» with no purchase named would have needed a search
     * for some purchase to justify it, and a search that finds one is a search
     * that can find the wrong one.
     */
    public function rateVendor(int $buyerUserId, int $orderItemId, int $stars, string $body): OperationResult
    {
        if ($buyerUserId <= 0) {
            return OperationResult::failure('forbidden');
        }
        if (!VendorRating::starsAreValid($stars)) {
            return OperationResult::failure('rating_stars_out_of_range', [
                'min' => VendorRating::MIN_STARS,
                'max' => VendorRating::MAX_STARS,
            ]);
        }
        $item = $this->items->find($orderItemId);
        if ($item === null) {
            return OperationResult::failure('not_found');
        }
        // «خریدار واقعی»: the line must be this person's own purchase. Asked of
        // the WooCommerce order rather than of a copied column — see
        // `BuyerVerifierInterface` for why there is no `buyer_user_id` here.
        if (!$this->buyers->isAvailable()) {
            return OperationResult::failure('rating_purchase_unverifiable');
        }
        if (!$this->buyers->boughtLine($orderItemId, $buyerUserId)) {
            return OperationResult::failure('rating_not_your_purchase');
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $written = $this->ratings->add(new VendorRating(
            0,
            $item->vendorUserId,
            $buyerUserId,
            $orderItemId,
            $stars,
            trim($body),
            RatingStatus::Pending,
            '',
            null,
            null,
            null,
            '',
            $now,
            $now
        ));
        if (!$written) {
            // The unique index answered. A purchase already rated is not an
            // error state to recover from — it is the rule working.
            return OperationResult::failure('rating_already_given', ['order_item_id' => $orderItemId]);
        }
        $this->audit->log(AuditEventCatalog::VENDOR_RATING_GIVEN, $buyerUserId, 'order_item', (string) $orderItemId, [
            'vendor_user_id' => $item->vendorUserId,
            'stars' => $stars,
        ]);
        $this->notify?->toStore(
            $item->vendorUserId,
            Notify::RATING_RECEIVED,
            'order_item',
            (string) $orderItemId,
            ['stars' => $stars]
        );
        return OperationResult::success('rating_recorded', [
            'order_item_id' => $orderItemId,
            'stars' => $stars,
            // Said now rather than discovered later: a buyer who expects their
            // words on the page immediately would otherwise think it failed.
            'awaiting_moderation' => true,
        ]);
    }

    /** Whether this person may review this product — asked before the form is shown. */
    public function mayReviewProduct(int $userId, int $wcProductId): bool
    {
        return $this->reviews !== null && $this->reviews->canReview($userId, $wcProductId);
    }

    /** Whether this purchase is still un-rated, so the form is worth showing. */
    public function mayRateVendor(int $buyerUserId, int $orderItemId): bool
    {
        return $this->items->find($orderItemId) !== null
            && $this->buyers->isAvailable()
            && $this->buyers->boughtLine($orderItemId, $buyerUserId)
            && $this->ratings->findByOrderItem($orderItemId) === null;
    }

    // --- the shop's side: replying, and nothing else -------------------------

    /**
     * The shop answers a review of one of its products.
     *
     * Everything that decides whether this is allowed is asked of somebody
     * else: `StaffAccess` for whether this person speaks for this shop, and the
     * gateway for whether the review is even on a product this marketplace
     * owns. A review on a Dokan vendor's product resolves to no vendor at all,
     * so there is nothing to compare and the answer is no.
     */
    public function replyToReview(int $actorId, int $vendorUserId, int $reviewId, string $body): OperationResult
    {
        if (!$this->maySpeakFor($actorId, $vendorUserId)) {
            return OperationResult::failure('forbidden');
        }
        if ($this->reviews === null || !$this->reviews->isAvailable()) {
            return OperationResult::failure('reviews_woocommerce_missing');
        }
        $body = trim($body);
        if ($body === '') {
            return OperationResult::failure('reply_body_required');
        }
        $review = $this->reviews->find($reviewId);
        if ($review === null) {
            return OperationResult::failure('not_found');
        }
        if ($review->vendorUserId !== $vendorUserId) {
            return OperationResult::failure('not_ours');
        }
        if ($review->hasReply()) {
            return OperationResult::failure('reply_already_given', ['review_id' => $reviewId]);
        }
        $replyId = $this->reviews->reply($reviewId, $vendorUserId, $actorId, $body);
        if ($replyId <= 0) {
            return OperationResult::failure('reply_failed', ['review_id' => $reviewId]);
        }
        $this->audit->log(AuditEventCatalog::PRODUCT_REVIEW_REPLIED, $actorId, 'review', (string) $reviewId, [
            'vendor_user_id' => $vendorUserId,
            'reply_id' => $replyId,
        ]);
        return OperationResult::success('reply_recorded', ['review_id' => $reviewId, 'reply_id' => $replyId]);
    }

    /** The shop answers a rating of itself. One answer, and no way to change the stars. */
    public function replyToRating(int $actorId, int $vendorUserId, int $ratingId, string $body): OperationResult
    {
        if (!$this->maySpeakFor($actorId, $vendorUserId)) {
            return OperationResult::failure('forbidden');
        }
        $body = trim($body);
        if ($body === '') {
            return OperationResult::failure('reply_body_required');
        }
        $rating = $this->ratings->find($ratingId);
        if ($rating === null) {
            return OperationResult::failure('not_found');
        }
        if ($rating->vendorUserId !== $vendorUserId) {
            return OperationResult::failure('not_ours');
        }
        if ($rating->hasReply()) {
            return OperationResult::failure('reply_already_given', ['rating_id' => $ratingId]);
        }
        // The shop id is in the WHERE clause too, not only in the check above:
        // one of the two is a race, both of them is not.
        if (!$this->ratings->reply($ratingId, $vendorUserId, $body, $this->clock->now()->format('Y-m-d H:i:s'))) {
            return OperationResult::failure('reply_failed', ['rating_id' => $ratingId]);
        }
        $this->audit->log(AuditEventCatalog::VENDOR_RATING_REPLIED, $actorId, 'rating', (string) $ratingId, [
            'vendor_user_id' => $vendorUserId,
        ]);
        return OperationResult::success('reply_recorded', ['rating_id' => $ratingId]);
    }

    // --- the manager's side: moderation --------------------------------------

    public function moderateRating(int $actorId, int $ratingId, RatingStatus $status, string $reason = ''): OperationResult
    {
        if (!$this->mayModerate()) {
            return OperationResult::failure('forbidden');
        }
        if ($status === RatingStatus::Pending) {
            // «Un-decide» is not a moderation decision; it is a way to make an
            // audit trail lie about whether anybody looked.
            return OperationResult::failure('moderation_cannot_undecide');
        }
        $rating = $this->ratings->find($ratingId);
        if ($rating === null) {
            return OperationResult::failure('not_found');
        }
        if ($status === RatingStatus::Rejected && trim($reason) === '') {
            return OperationResult::failure('moderation_reason_required');
        }
        if (!$this->ratings->moderate($ratingId, $status, $actorId, trim($reason), $this->clock->now()->format('Y-m-d H:i:s'))) {
            return OperationResult::failure('moderation_failed', ['rating_id' => $ratingId]);
        }
        $this->audit->log(AuditEventCatalog::VENDOR_RATING_MODERATED, $actorId, 'rating', (string) $ratingId, [
            'status' => $status->value,
            'vendor_user_id' => $rating->vendorUserId,
            'reason' => trim($reason),
        ]);
        $this->notify?->toStore(
            $rating->vendorUserId,
            Notify::RATING_MODERATED,
            'rating',
            (string) $ratingId,
            ['status' => $status->value]
        );
        return OperationResult::success('rating_moderated', [
            'rating_id' => $ratingId,
            'status' => $status->value,
        ]);
    }

    public function moderateReview(int $actorId, int $reviewId, bool $approved): OperationResult
    {
        if (!$this->mayModerate()) {
            return OperationResult::failure('forbidden');
        }
        if ($this->reviews === null || !$this->reviews->isAvailable()) {
            return OperationResult::failure('reviews_woocommerce_missing');
        }
        $review = $this->reviews->find($reviewId);
        if ($review === null) {
            // Including a review on a product this marketplace does not own:
            // the gateway never returns one, so there is no path from here to
            // moderating the shop's own or a Dokan vendor's reviews.
            return OperationResult::failure('not_found');
        }
        if (!$this->reviews->moderate($reviewId, $approved)) {
            return OperationResult::failure('moderation_failed', ['review_id' => $reviewId]);
        }
        $this->audit->log(AuditEventCatalog::PRODUCT_REVIEW_MODERATED, $actorId, 'review', (string) $reviewId, [
            'approved' => $approved,
            'vendor_user_id' => $review->vendorUserId,
        ]);
        return OperationResult::success('review_moderated', [
            'review_id' => $reviewId,
            'approved' => $approved,
        ]);
    }

    // --- reading -------------------------------------------------------------

    /** @return list<VendorRating> */
    public function ratingsForVendor(int $actorId, int $vendorUserId, ?RatingStatus $status = null, int $limit = 50): array
    {
        if (!$this->access->can($actorId, $vendorUserId, StaffArea::Report, StaffLevel::View)) {
            return [];
        }
        return $this->ratings->forVendor($vendorUserId, $status, $limit);
    }

    /** @return list<ProductReview> */
    public function reviewsForVendor(int $actorId, int $vendorUserId, ?bool $approved = null, int $limit = 50): array
    {
        if ($this->reviews === null || !$this->access->can($actorId, $vendorUserId, StaffArea::Report, StaffLevel::View)) {
            return [];
        }
        return $this->reviews->forVendor($vendorUserId, $approved, $limit);
    }

    /** @return list<VendorRating> */
    public function ratingQueue(?RatingStatus $status = RatingStatus::Pending, int $limit = self::QUEUE_LIMIT): array
    {
        return $this->mayModerate() ? $this->ratings->queue($status, $limit) : [];
    }

    /** @return list<ProductReview> */
    public function reviewQueue(?bool $approved = false, int $limit = self::QUEUE_LIMIT): array
    {
        return ($this->mayModerate() && $this->reviews !== null) ? $this->reviews->queue($approved, $limit) : [];
    }

    /**
     * The two standings of one shop, side by side and never added together.
     *
     * The shape says the separation out loud: `product` and `vendor` are
     * distinct keys with distinct counts, and there is no `overall`. A caller
     * that wanted one number would have to invent it, and would then own the
     * decision of how — which is exactly the decision Master A.5 already made
     * by keeping them apart.
     *
     * @return array{
     *     product: array{count:int, average_hundredths:int, available:bool},
     *     vendor: array{count:int, average_hundredths:int, distribution:array<int,int>}
     * }
     */
    public function standing(int $vendorUserId): array
    {
        $available = $this->reviews !== null && $this->reviews->isAvailable();
        $product = $available
            ? $this->reviews->summaryFor($vendorUserId)
            : ['count' => 0, 'average_hundredths' => 0];
        return [
            'product' => [
                'count' => (int) $product['count'],
                'average_hundredths' => (int) $product['average_hundredths'],
                // Not «zero reviews» when WooCommerce is absent — «no reviews
                // could be read». A screen must be able to tell those apart.
                'available' => $available,
            ],
            'vendor' => $this->ratings->summaryFor($vendorUserId),
        ];
    }

    // --- who may do what ------------------------------------------------------

    private function mayModerate(): bool
    {
        return $this->capabilities !== null && $this->capabilities->can(Capabilities::MODERATE_REVIEWS);
    }

    /**
     * Whether this person may answer on this shop's behalf.
     *
     * `StaffArea::Report` at `Respond` — the level the spec's own permission
     * matrix (Master §3.1) named for exactly this: a member who may answer but
     * may not change the thing they are answering. «فروشنده فقط پاسخ می‌دهد»
     * is the same sentence one layer up, and a staff member with view-only
     * reporting reads the reviews and cannot answer them.
     */
    private function maySpeakFor(int $actorId, int $vendorUserId): bool
    {
        return $this->access->can($actorId, $vendorUserId, StaffArea::Report, StaffLevel::Respond);
    }
}
