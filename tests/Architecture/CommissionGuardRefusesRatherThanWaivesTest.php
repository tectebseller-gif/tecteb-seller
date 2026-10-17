<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: the commission guard never tells WooCommerce that a
 * refused order needs no payment.
 *
 * `alpha.62` filtered `woocommerce_order_needs_payment` to `false` for an
 * order whose line this rule refuses, and that is a payment bypass rather
 * than a guard. `needs_payment() === false` is not «رد شد» to WooCommerce; it
 * is «این سفارش پولی لازم ندارد», and two core branches act on it:
 *
 *  - `WC_Checkout::process_checkout()` (`class-wc-checkout.php:1444`) calls
 *    `process_order_without_payment()`,
 *  - the block checkout (`StoreApi/Routes/V1/Checkout.php:682`) calls
 *    `process_without_payment()`, which is `$order->payment_complete()`
 *    (`StoreApi/Utilities/CheckoutTrait.php:73`).
 *
 * Both end in a completed order with no money taken. The cart guard does
 * refuse first on those two paths, but not on the same reading — it measures
 * `wc_format_decimal($item['line_total'], $exponent)` where the order carries
 * `$item->get_total()` — so a line total that rounds UP to a figure the rule
 * allows passes the basket and is refused on the order. That request was the
 * free one.
 *
 * This test exists because the fix is an ABSENCE, and `alpha.48`'s rule says
 * an absence needs a test rather than a sentence: «ترتیب گارد نیست». It is
 * byte-level over the tokenised file, because the docblock of the class under
 * test now explains the filter at length and `alpha.22`'s rule is that a byte
 * scan cannot tell prose from code.
 */
final class CommissionGuardRefusesRatherThanWaivesTest extends TestCase
{
    private const GUARD = __DIR__
        . '/../../src/Modules/Finance/Infrastructure/WooCommerce/CommissionCartGuard.php';

    /**
     * Every filter that would let core conclude that no payment is due.
     *
     * Named one by one rather than matched, so that a reader can see which
     * answers are forbidden and why: each of these is read by core as a fact
     * about the ORDER's price, and this rule is not about the price.
     *
     * @return array<string,array{string}>
     */
    public static function waivers(): array
    {
        return [
            'order needs payment' => ['woocommerce_order_needs_payment'],
            'cart needs payment' => ['woocommerce_cart_needs_payment'],
            'valid statuses for payment' => ['woocommerce_valid_order_statuses_for_payment'],
            // And the completion verbs themselves: a guard has no business
            // moving an order forward.
            'payment complete' => ['payment_complete('],
            'status write' => ['->set_status('],
            'order save' => ['->save()'],
        ];
    }

    /**
     * @dataProvider waivers
     */
    public function testTheGuardNeverWaivesPayment(string $needle): void
    {
        $source = self::code();
        self::assertNotSame('', $source, 'the guard must be readable for this test to mean anything');
        self::assertStringNotContainsString($needle, $source, $needle);
    }

    /**
     * WHAT THIS PROVES: each pay path is refused on a hook that means refusal.
     *
     * The positive half. Removing the filter alone would leave the pay link
     * and both Store API routes unguarded, which is the defect the filter was
     * added for — so the absence above is only safe together with these three.
     */
    public function testEveryPayPathIsRefusedExplicitly(): void
    {
        $source = self::code();
        // The basket, both of them, through the one callback core runs on the
        // classic cart, the classic checkout POST and `validate_cart()`.
        self::assertStringContainsString("'woocommerce_check_cart_items'", $source);
        // The classic pay link. Core reaches `process_payment()` only when
        // the error count is nought, so the notice IS the refusal.
        self::assertStringContainsString("'woocommerce_before_pay_action'", $source);
        self::assertStringContainsString('wc_add_notice', $source);
        // Both Store API routes, before the payment branch.
        self::assertStringContainsString("'woocommerce_store_api_checkout_order_processed'", $source);
        self::assertStringContainsString('throw', $source);
        // …and the CLASSIC checkout at the same point in its own flow.
        self::assertStringContainsString("'woocommerce_checkout_order_processed'", $source);
    }

    /**
     * WHAT THIS PROVES: the basket measures the figure the ORDER will carry.
     *
     * The gap a security review found on `alpha.63`: the Store API had an
     * order-level refusal and the classic checkout had only the cart one, and
     * the cart one read a DIFFERENT number. Core writes the order line as
     * `'total' => $values['line_total']` (`class-wc-checkout.php:571`) and
     * `WC_Order_Item_Product::set_total()` normalises it with
     * `wc_format_decimal($value)` and **no** dp
     * (`class-wc-order-item-product.php:136`). Passing the currency's
     * exponent here rounds, so `49999.6` was compared as `50000`.
     *
     * Rounding to the exponent is right for a figure shown to a shopper and
     * wrong for one compared against a rule, so the needle is the call —
     * there must be no dp argument on the line-total reading.
     */
    public function testTheCartReadsTheSameFigureTheOrderCarries(): void
    {
        $source = self::code();
        self::assertStringContainsString("wc_format_decimal(\$item['line_total'] ?? 0)", $source);
        self::assertStringNotContainsString("wc_format_decimal(\$item['line_total'] ?? 0, \$exponent)", $source);
    }

    /**
     * The file with its comments blanked out.
     *
     * `alpha.22`'s rule — and this test needs it more than most, because the
     * class it reads names every forbidden filter in its own docblock in
     * order to explain why it does not use them.
     */
    private static function code(): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents(self::GUARD)) as $token) {
            if (is_array($token)) {
                $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }
}
