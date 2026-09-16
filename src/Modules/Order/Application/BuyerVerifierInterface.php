<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Application;

/**
 * «آیا این شخص واقعاً این را خریده؟» — asked of WooCommerce, which is the only
 * thing that knows.
 *
 * This is deliberately NOT a `buyer_user_id` column on `tmc_order_items`. Who
 * bought an order is the customer on the WooCommerce order, and ADR-008 puts
 * the order and the customer on WooCommerce's side of the line. Copying that id
 * into this plugin's own table would have made a second answer that can drift —
 * a guest order later attached to an account, a customer id changed by a
 * migration, an order reassigned in wp-admin — and a marketplace that then
 * disagreed with the shop about who bought something.
 *
 * So it is a question, asked every time, of the row that owns the answer. It
 * costs a lookup and it cannot go stale.
 *
 * It lives in the Order module rather than next to the reviews that use it,
 * because «did this person buy this» is a fact about an order, and the next
 * feature that needs it (a returns form on «حساب من», say) should not have to
 * reach into the review module to ask.
 */
interface BuyerVerifierInterface
{
    /** Whether there is a WooCommerce here to ask. */
    public function isAvailable(): bool;

    /**
     * Whether this user is the customer on the order that this marketplace
     * order line belongs to.
     *
     * False for a guest order, always: a guest has no user id, so there is
     * nobody for the answer to be true of. That also means a rating can never
     * be attached to a purchase nobody can be identified as having made.
     */
    public function boughtLine(int $orderItemId, int $userId): bool;

    /**
     * Whether this user has ever bought this product, in any order.
     *
     * Asked of WooCommerce's own purchase history rather than of this plugin's
     * captured lines, because a product can have been bought before this
     * marketplace started capturing — and the shopper who bought it is still a
     * real buyer of it.
     */
    public function boughtProduct(int $userId, int $wcProductId): bool;
}
