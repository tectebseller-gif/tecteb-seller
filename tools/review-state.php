<?php
/**
 * Reviews and ratings, driven through the plugin's own services on a
 * DISPOSABLE WordPress.
 *
 * No `declare(strict_types=1)`: `wp eval-file` wraps this in eval().
 *
 *   wp eval-file tools/review-state.php buyer <email>
 *   wp eval-file tools/review-state.php buy <wc-product-id> <buyer-id> [qty]
 *   wp eval-file tools/review-state.php may-review <user-id> <wc-product-id>
 *   wp eval-file tools/review-state.php post-review <wc-product-id> <user-id> <stars> <body>
 *   wp eval-file tools/review-state.php reviews <vendor-id>
 *   wp eval-file tools/review-state.php reply-review <review-id> <actor> <vendor> <body>
 *   wp eval-file tools/review-state.php moderate-review <review-id> approve|hold
 *   wp eval-file tools/review-state.php rate <order-item-id> <buyer-id> <stars> [body]
 *   wp eval-file tools/review-state.php ratings <vendor-id>
 *   wp eval-file tools/review-state.php reply-rating <rating-id> <actor> <vendor> <body>
 *   wp eval-file tools/review-state.php moderate <rating-id> approve|reject [reason]
 *   wp eval-file tools/review-state.php standing <vendor-id>
 *   wp eval-file tools/review-state.php chart <vendor-id>
 *   wp eval-file tools/review-state.php comment-count <wc-product-id>
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Marketplace\Application\ManageReviews;
use Tecteb\Marketplace\Modules\Marketplace\Application\ProductReviewGatewayInterface;
use Tecteb\Marketplace\Modules\Marketplace\Domain\RatingStatus;
use Tecteb\Marketplace\Modules\Marketplace\Presentation\Charts;
use Tecteb\Marketplace\Modules\Marketplace\Presentation\NoticeMessages;
use Tecteb\Marketplace\Modules\Order\Application\CaptureOrder;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;

// --- refuses to run anywhere but the disposable install -------------------
//
// This file WRITES test data — shops, products, orders, refunds. On the
// owner's site that is not a seeding tool, it is damage. A docblock saying
// «DISPOSABLE» is documentation, not a guard: it stops nobody who pastes the
// command at the wrong shell.
//
// Two independent facts, the same pair tools/disposable-site.sh already
// trusts: the database must be the disposable one by name, and the site must
// be on a host nobody outside the container can reach. Deliberately NOT
// wp_get_environment_type(), which reports `production` on the disposable
// container itself because nobody set the constant.
if (!defined('DB_NAME') || DB_NAME !== 'tmc_wp_test') {
    fwrite(STDERR, "refused: DB_NAME is not the disposable tmc_wp_test. This tool writes test data and will not run here.\n");
    echo "refused=1 reason=database_is_not_the_disposable_one\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local. This tool writes test data and will not run here.\n");
    echo "refused=1 reason=home_url_is_not_local\n";
    return;
}


$command = (string) ($args[0] ?? 'standing');
$c = Bootstrap::container();
$manager = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
wp_set_current_user($manager);
$c->bind(CapabilityCheckerInterface::class, static function () use ($manager) {
    return new class ($manager) implements CapabilityCheckerInterface {
        public function __construct(private $id)
        {
        }

        public function can(string $capability): bool
        {
            return in_array($capability, Capabilities::all(), true);
        }

        public function currentUserId(): ?int
        {
            return $this->id;
        }
    };
});

$reviews = $c->get(ManageReviews::class);
$items = $c->get(OrderItemRepositoryInterface::class);
$gateway = $c->get(ProductReviewGatewayInterface::class);

switch ($command) {
    case 'buyer':
        // A real WordPress customer, because `wc_customer_bought_product()` is
        // asked by e-mail AND id and a made-up id answers neither.
        $email = (string) ($args[1] ?? 'buyer@example.test');
        $user = get_user_by('email', $email);
        if (!$user) {
            $id = wp_insert_user([
                'user_login' => 'tmc_' . substr(md5($email), 0, 8),
                'user_email' => $email,
                'user_pass' => wp_generate_password(),
                'role' => 'customer',
                'display_name' => 'خریدار آزمایشی',
            ]);
            $user = get_userdata(is_wp_error($id) ? 0 : $id);
        }
        printf("buyer id=%d email=%s\n", $user ? (int) $user->ID : 0, $email);
        break;

    case 'buy':
        // A completed order, owned by a real customer. Completed on purpose:
        // `wc_customer_bought_product()` only counts orders in a paid status,
        // so a pending one would prove nothing about who may review.
        $wcProductId = (int) ($args[1] ?? 0);
        $buyerId = (int) ($args[2] ?? 0);
        $quantity = max(1, (int) ($args[3] ?? 1));
        $product = $c->get(ProductRepositoryInterface::class)->findByWcProduct($wcProductId);
        $order = wc_create_order();
        $order->add_product(wc_get_product($wcProductId), $quantity);
        $order->set_customer_id($buyerId);
        $buyer = get_userdata($buyerId);
        if ($buyer) {
            $order->set_billing_email((string) $buyer->user_email);
        }
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();
        $itemId = 0;
        foreach ($order->get_items() as $id => $item) {
            $itemId = (int) $id;
        }
        $line = null;
        if ($product !== null) {
            $c->get(CaptureOrder::class)->capture((int) $order->get_id(), [[
                'order_item_id' => $itemId,
                'wc_product_id' => $wcProductId,
                'variation_id' => null,
                'title' => $product->details->title,
                'sku' => $product->details->sku,
                'quantity' => $quantity,
                'line_total_minor' => $product->details->priceMinor * $quantity,
                'line_tax_minor' => 0,
            ]]);
            $line = $items->findByOrderItem($itemId);
        }
        printf(
            "buy order=%d wc_item=%d item=%s vendor=%s buyer=%d\n",
            (int) $order->get_id(),
            $itemId,
            $line === null ? '0' : (string) $line->id,
            $line === null ? '0' : (string) $line->vendorUserId,
            $buyerId
        );
        break;

    case 'may-review':
        printf(
            "may-review user=%d product=%d allowed=%s\n",
            (int) ($args[1] ?? 0),
            (int) ($args[2] ?? 0),
            $reviews->mayReviewProduct((int) ($args[1] ?? 0), (int) ($args[2] ?? 0)) ? 'true' : 'false'
        );
        break;

    case 'post-review':
        // Through `wp_insert_comment` with a rating, exactly as WooCommerce's
        // own form does — so what is measured is a real review, not a row this
        // script invented a shape for.
        $productId = (int) ($args[1] ?? 0);
        $userId = (int) ($args[2] ?? 0);
        $user = get_userdata($userId);
        $id = wp_insert_comment([
            'comment_post_ID' => $productId,
            'comment_content' => (string) ($args[4] ?? 'نظر آزمایشی'),
            'comment_author' => $user ? (string) $user->display_name : '',
            'comment_author_email' => $user ? (string) $user->user_email : '',
            'user_id' => $userId,
            'comment_approved' => 0,
            'comment_type' => 'review',
        ]);
        if ($id) {
            update_comment_meta((int) $id, 'rating', (int) ($args[3] ?? 5));
        }
        printf("post-review id=%d product=%d user=%d\n", (int) $id, $productId, $userId);
        break;

    case 'reviews':
        $vendorId = (int) ($args[1] ?? 0);
        $list = $reviews->reviewsForVendor($vendorId, $vendorId, null, 50);
        printf("reviews vendor=%d count=%d\n", $vendorId, count($list));
        foreach ($list as $review) {
            printf(
                "  review=%d product=%d stars=%d approved=%s verified=%s reply=%s\n",
                $review->id,
                $review->wcProductId,
                $review->stars,
                $review->approved ? 'true' : 'false',
                $review->verifiedBuyer ? 'true' : 'false',
                $review->hasReply() ? (string) $review->replyId : '-'
            );
        }
        break;

    case 'reply-review':
        $result = $reviews->replyToReview(
            (int) ($args[2] ?? 0),
            (int) ($args[3] ?? 0),
            (int) ($args[1] ?? 0),
            (string) ($args[4] ?? 'پاسخ فروشگاه')
        );
        printf(
            "reply-review ok=%s code=%s reply_id=%s\n",
            $result->ok ? 'true' : 'false',
            $result->code,
            (string) ($result->context['reply_id'] ?? '-')
        );
        break;

    case 'moderate-review':
        $result = $reviews->moderateReview($manager, (int) ($args[1] ?? 0), (string) ($args[2] ?? 'approve') === 'approve');
        printf("moderate-review ok=%s code=%s\n", $result->ok ? 'true' : 'false', $result->code);
        break;

    case 'rate':
        $result = $reviews->rateVendor(
            (int) ($args[2] ?? 0),
            (int) ($args[1] ?? 0),
            (int) ($args[3] ?? 5),
            (string) ($args[4] ?? '')
        );
        printf(
            "rate ok=%s code=%s item=%s stars=%s pending=%s\n",
            $result->ok ? 'true' : 'false',
            $result->code,
            (string) ($result->context['order_item_id'] ?? '-'),
            (string) ($result->context['stars'] ?? '-'),
            !empty($result->context['awaiting_moderation']) ? 'true' : 'false'
        );
        break;

    case 'ratings':
        $vendorId = (int) ($args[1] ?? 0);
        $list = $reviews->ratingsForVendor($vendorId, $vendorId, null, 50);
        printf("ratings vendor=%d count=%d\n", $vendorId, count($list));
        foreach ($list as $rating) {
            printf(
                "  rating=%d stars=%d status=%s body=%s reply=%s reason=%s\n",
                $rating->id,
                $rating->stars,
                $rating->status->value,
                $rating->body === '' ? '-' : $rating->body,
                $rating->hasReply() ? 'yes' : '-',
                $rating->moderationReason === '' ? '-' : $rating->moderationReason
            );
        }
        break;

    case 'reply-rating':
        $result = $reviews->replyToRating(
            (int) ($args[2] ?? 0),
            (int) ($args[3] ?? 0),
            (int) ($args[1] ?? 0),
            (string) ($args[4] ?? 'پاسخ فروشگاه')
        );
        printf("reply-rating ok=%s code=%s\n", $result->ok ? 'true' : 'false', $result->code);
        break;

    case 'moderate':
        // Three, not two. `pending` is here so the evidence can actually ask
        // for «un-decide» and be refused — a tool that could only say approve
        // or reject would have made that check pass by accident.
        $status = match ((string) ($args[2] ?? 'approve')) {
            'approve' => RatingStatus::Approved,
            'pending' => RatingStatus::Pending,
            default => RatingStatus::Rejected,
        };
        $result = $reviews->moderateRating($manager, (int) ($args[1] ?? 0), $status, (string) ($args[3] ?? ''));
        printf(
            "moderate ok=%s code=%s status=%s\n",
            $result->ok ? 'true' : 'false',
            $result->code,
            (string) ($result->context['status'] ?? '-')
        );
        break;

    case 'standing':
        $standing = $reviews->standing((int) ($args[1] ?? 0));
        printf(
            "standing vendor=%d product_count=%d product_avg=%d product_available=%s vendor_count=%d vendor_avg=%d dist=%s\n",
            (int) ($args[1] ?? 0),
            $standing['product']['count'],
            $standing['product']['average_hundredths'],
            $standing['product']['available'] ? 'true' : 'false',
            $standing['vendor']['count'],
            $standing['vendor']['average_hundredths'],
            implode(',', array_map(
                static fn ($k, $v) => $k . ':' . $v,
                array_keys($standing['vendor']['distribution']),
                $standing['vendor']['distribution']
            ))
        );
        break;

    case 'chart':
        // The SVG itself, so the evidence can assert what is in it rather than
        // assert that a function was called.
        $rows = NoticeMessages::chartRows(
            \Tecteb\Marketplace\Modules\Marketplace\Application\Reports::PRODUCTS,
            ['available' => 1, 'published' => 3, 'in_review' => 1, 'needs_fix' => 0, 'draft' => 2, 'suspended' => 0]
        );
        echo Charts::card('محصول', $rows, 'تعداد محصول در هر وضعیت.') . "\n";
        break;

    case 'comment-count':
        $productId = (int) ($args[1] ?? 0);
        $all = get_comments(['post_id' => $productId, 'status' => 'all', 'type' => 'review']);
        $replies = get_comments(['post_id' => $productId, 'status' => 'all', 'meta_key' => '_tmc_vendor_reply']);
        printf("comments product=%d reviews=%d vendor_replies=%d\n", $productId, count($all), count($replies));
        break;

    case 'reset':
        // True isolation, on the DISPOSABLE site only.
        //
        // This script spends what it touches — a purchase can be rated once,
        // a review answered once — so a second run against the first run's
        // leftovers fails checks that have nothing to do with the code. That
        // lesson is already written down for `order-evidence.php reset`, and
        // it applies to every table hanging off a product or an order line.
        //
        // Ratings are dropped wholesale; review comments only on MARKETPLACE
        // products, because a comment on the shop's own product is not ours to
        // delete any more than it is ours to moderate.
        global $wpdb;
        $wpdb->query('DELETE FROM `' . $wpdb->prefix . 'tmc_vendor_ratings`');
        $ours = [];
        foreach ($c->get(ProductRepositoryInterface::class)->projected() as $product) {
            if ((int) $product->wcProductId > 0) {
                $ours[] = (int) $product->wcProductId;
            }
        }
        $removed = 0;
        foreach ($ours as $wcProductId) {
            foreach (get_comments(['post_id' => $wcProductId, 'status' => 'all']) as $comment) {
                wp_delete_comment((int) $comment->comment_ID, true);
                $removed++;
            }
        }
        printf("reset ratings=cleared comments=%d products=%d\n", $removed, count($ours));
        break;

    case 'gateway':
        printf("gateway class=%s available=%s\n", get_class($gateway), $gateway->isAvailable() ? 'true' : 'false');
        break;

    default:
        echo "unknown command\n";
        break;
}
