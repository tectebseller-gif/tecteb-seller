<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Domain;

/**
 * The one place a WooCommerce order status is read as a meaning.
 *
 * Pure: no WooCommerce, no database, no clock. The strings are WooCommerce's
 * own slugs with the `wc-` prefix it uses on the post status and the bare form
 * `WC_Order::get_status()` returns — both accepted, because the two reach this
 * class from different hooks and a mapping that knew only one of them would be
 * right half the time.
 *
 * **Why a table and not a match in the caller.** Three screens and one service
 * ask this question — the lifecycle sync, the detailed report's payment
 * column, the vendor's own list, and the manager's — and four readings of one
 * rule are four rules that agree until somebody adds a status. `alpha.78`'s
 * lesson, on order statuses: two halves that answer the same question must
 * read the same list.
 *
 * **Unmapped is `Unknown`, never a default that acts.** A plugin can register
 * any status it likes, and this marketplace's answer to one it has not been
 * taught is to write nothing and say the name out loud.
 */
final class WcOrderLifecycle
{
    /**
     * The order is real and nothing has been paid.
     *
     * `checkout-draft` is the block checkout's own holding status — an order
     * row that exists while the customer is still typing.
     */
    public const AWAITING_PAYMENT = ['pending', 'on-hold', 'checkout-draft'];

    /** The gateway said no. Retried far more often than abandoned. */
    public const PAYMENT_FAILED = ['failed'];

    /** WooCommerce considers the money received. */
    public const PAID = ['processing', 'completed'];

    /**
     * The sale did not happen.
     *
     * `trash` is here because a trashed order is not an order any more: it is
     * out of every WooCommerce report, and leaving its share in ours would
     * make the two disagree for ever. It is a post status rather than an order
     * status, which is why it is read from the same table.
     */
    public const VOID = ['cancelled', 'trash'];

    /** Money has moved somewhere this plugin cannot see. A person decides. */
    public const REFUNDED = ['refunded'];

    public static function of(string $status): WcOrderDisposition
    {
        $slug = self::normalise($status);
        if ($slug === '') {
            return WcOrderDisposition::Unknown;
        }
        if (in_array($slug, self::AWAITING_PAYMENT, true)) {
            return WcOrderDisposition::AwaitingPayment;
        }
        if (in_array($slug, self::PAYMENT_FAILED, true)) {
            return WcOrderDisposition::PaymentFailed;
        }
        if (in_array($slug, self::PAID, true)) {
            return WcOrderDisposition::Paid;
        }
        if (in_array($slug, self::VOID, true)) {
            return WcOrderDisposition::Void;
        }
        if (in_array($slug, self::REFUNDED, true)) {
            return WcOrderDisposition::Refunded;
        }
        return WcOrderDisposition::Unknown;
    }

    /**
     * Every status this release has a meaning for.
     *
     * Exists so a screen can offer the filter without a second list of its
     * own, and so a test can bind the two together in both directions: a slug
     * in the table that nothing maps, and a mapping for a slug not in the
     * table, are the two halves of the same mistake.
     *
     * @return list<string>
     */
    public static function known(): array
    {
        return array_values(array_merge(
            self::AWAITING_PAYMENT,
            self::PAYMENT_FAILED,
            self::PAID,
            self::VOID,
            self::REFUNDED
        ));
    }

    /**
     * WooCommerce's two spellings of one status, reduced to one.
     *
     * `get_status()` answers `cancelled`; the post status is `wc-cancelled`;
     * and `trash` carries no prefix at all because it is WordPress's, not
     * WooCommerce's. Case is folded because a status read back out of a
     * payload or a query string is not guaranteed to come back in the case it
     * went in as.
     */
    private static function normalise(string $status): string
    {
        $slug = strtolower(trim($status));
        if (str_starts_with($slug, 'wc-')) {
            $slug = substr($slug, 3);
        }
        return $slug;
    }
}
