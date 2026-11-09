<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Application;

/**
 * Which WooCommerce orders hold a marketplace product — asked of WooCommerce.
 *
 * ⛔ **THE ONE QUESTION RECONCILIATION CANNOT ANSWER FROM OUR OWN TABLES.**
 * Six of the seven classes in `OrderInconsistency` are about a line we
 * recorded, and a line we recorded is a row we can read. The seventh —
 * «a WooCommerce order containing a marketplace product and no line here» —
 * is about an order we have NO row for, so the only place it can come from is
 * WooCommerce itself.
 *
 * `null` means «I could not enumerate», and the audit reports `unchecked`
 * rather than clean. That is the whole point of the interface: without
 * WooCommerce the honest answer is a named gap, not silence (`alpha.79`).
 */
interface OrderLineReaderInterface
{
    /**
     * Recent WooCommerce order ids, newest first, bounded.
     *
     * Bounded on purpose and announced by the caller: enumerating every order
     * of a live shop inside an admin request is the shape that stalls a page
     * (`alpha.75`). A shop with more orders than the window gets a window,
     * and the screen says so.
     *
     * @return list<int>|null `null` when WooCommerce cannot be asked at all
     */
    public function recentOrderIds(int $limit): ?array;

    /**
     * The product ids one WooCommerce order's lines point at.
     *
     * @return list<int>|null `null` when that order could not be read
     */
    public function productIdsIn(int $wcOrderId): ?array;
}
