<?php
declare(strict_types=1);

/*
 * A WooCommerce BASKET and its money helpers, stubbed. THIS IS NOT WOOCOMMERCE.
 *
 * Nothing measured against this file may be reported as a WooCommerce result.
 * Real WooCommerce stays `Not Run` in every delivery note, and the staging
 * checklist carries the basket scenarios as obligatory manual steps.
 *
 * It exists because `CommissionCartGuard` had NO behavioural suite at all —
 * only an architecture test that reads its bytes. That is the `alpha.80` trap
 * in the worst place: this release added a SECOND question to its cart loop
 * (did somebody else's coupon take money off a vendor's line?), and nothing
 * ran the loop. A rule nothing calls is not a guard, and an architecture
 * assertion survives `if (false)` with every string in place (`alpha.79`).
 *
 * So the stub models the narrow surface that loop touches and nothing more:
 *
 *   - the basket and its codes: `get_cart()`, `get_applied_coupons()`
 *   - `wc_add_notice()` / `wc_notice_count()` — recorded, so a test can read
 *     WHICH sentence reached the shopper
 *   - `wc_format_decimal()` — normalises, does NOT round when `$dp` is false,
 *     which is the behaviour the guard's docblock depends on (`alpha.62`)
 *   - `get_woocommerce_currency()`
 *   - `wc_get_coupon_id_by_code()` — above nought for a real `shop_coupon`
 *     post, which is the whole native-coupon question
 *
 * ⛔ **`WC()` IS NOT DEFINED HERE, and that is the point of a separate file.**
 * `woocommerce-runtime.php` holds it and `load.php` does NOT load it. Defining
 * it with the rest broke `OrderAdminUrlTest::testWithoutWooCommerceThereIsNoLink\
 * AtAll` on the first run of this round: that class detects WooCommerce with
 * `function_exists('WC')`, so a stub which always defines it makes «no
 * WooCommerce» INEXPRESSIBLE — the `alpha.42` rule, verbatim, that a double
 * you add can break a test about the absence of the very thing. A test that
 * wants a basket requires the runtime file itself.
 *
 * ⛔ What it deliberately does NOT model: coupon arithmetic. The cart's line
 * figures are DATA here, set by the test, because the guard reads them and
 * never computes them — modelling WooCommerce's discount engine would make
 * this file the thing under test instead of the guard.
 */

namespace TmcWpStubs {
    /**
     * The basket, as the guard sees it: a list of line arrays and a list of
     * applied codes.
     *
     * The line keys are the ones `WC_Cart::get_cart()` really carries and the
     * guard really reads — `data`, `product_id`, `variation_id`, `quantity`,
     * `line_subtotal` (before coupons) and `line_total` (after). A test that
     * wants «this basket cannot say what the line cost before coupons» omits
     * `line_subtotal`, because that absence is a state the guard answers
     * differently (null, not a refusal).
     */
    final class FakeWcCart
    {
        /** @param list<array<string,mixed>> $lines @param list<string> $coupons */
        public function __construct(
            private array $lines = [],
            private array $coupons = []
        ) {
        }

        /** @return list<array<string,mixed>> */
        public function get_cart(): array
        {
            return $this->lines;
        }

        /** @return list<string> */
        public function get_applied_coupons(): array
        {
            return $this->coupons;
        }
    }

    /** What `WC()` answers: the one property the guard reaches through. */
    final class FakeWooCommerce
    {
        public function __construct(public ?FakeWcCart $cart = null)
        {
        }
    }
}

namespace {
    if (!function_exists('wc_add_notice')) {
        function wc_add_notice(string $message, string $type = 'success'): void
        {
            \TmcWpStubs\State::$notices[] = ['message' => $message, 'type' => $type];
        }
    }

    if (!function_exists('wc_notice_count')) {
        function wc_notice_count(string $type = ''): int
        {
            if ($type === '') {
                return count(\TmcWpStubs\State::$notices);
            }
            return count(array_filter(
                \TmcWpStubs\State::$notices,
                static fn (array $n): bool => $n['type'] === $type
            ));
        }
    }

    if (!function_exists('get_woocommerce_currency')) {
        function get_woocommerce_currency(): string
        {
            return \TmcWpStubs\State::$currency;
        }
    }

    if (!function_exists('wc_format_decimal')) {
        /**
         * ⛔ `$dp === false` NORMALISES AND DOES NOT ROUND.
         *
         * That is the half the guard's own docblock turns on: the order item's
         * `set_total()` calls this with no `$dp`, so `49999.6` stays
         * `49999.6`, and a basket that rounded it to the currency's exponent
         * would pass a rule the order then fails (`alpha.62`/`alpha.63`).
         * Rounding here would hide exactly that, so this stub rounds only when
         * a caller asks for decimal places — and no caller in the guard does.
         */
        function wc_format_decimal(mixed $number, mixed $dp = false, bool $trim_zeros = false): string
        {
            if (!is_scalar($number)) {
                return '';
            }
            $text = trim((string) $number);
            if ($text === '') {
                return '';
            }
            // Thousand separators go; the decimal point stays.
            $text = str_replace([',', ' ', "\u{00A0}"], '', $text);
            if ($dp !== false && $dp !== null) {
                $text = number_format((float) $text, (int) $dp, '.', '');
            }
            if ($trim_zeros && str_contains($text, '.')) {
                $text = rtrim(rtrim($text, '0'), '.');
            }
            return $text;
        }
    }

    if (!function_exists('wc_get_coupon_id_by_code')) {
        /**
         * Above nought for a real `shop_coupon` post — the host shop's own
         * coupon, which can never be a marketplace one.
         *
         * `State::$nativeCoupons` maps a code to its post id, so a test says
         * «this code exists as a WooCommerce coupon» without a posts table.
         * Codes are matched case-insensitively, as WooCommerce does.
         */
        function wc_get_coupon_id_by_code(string $code, string $exclude = ''): int
        {
            unset($exclude);
            return (int) (\TmcWpStubs\State::$nativeCoupons[strtolower(trim($code))] ?? 0);
        }
    }
}
