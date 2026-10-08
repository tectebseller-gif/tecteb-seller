<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Modules\Order\Infrastructure\WordPress\WcOrderReader;
use TmcWpStubs\State;

/**
 * WHAT THIS PROVES: the unit of money crosses the WooCommerce boundary as a
 * fact, and an amount that cannot cross it exactly does not cross it at all.
 *
 * **What `alpha.38` did here.** `WcOrderReader::lines()` wrote
 * `(int) round((float) $item->get_total())` into a field called
 * `line_total_minor`, and `OrderHooks` called
 * `capture($id, $lines)` — no currency, no exponent — so `CaptureOrder`'s
 * `'IRR', 0` default decided the unit of every order on every site. For a
 * ریال shop that is right by accident; for any other it silently rounded the
 * amount and mislabelled the currency, and the refund path then divided the
 * result by a hundred.
 *
 * The order object here is a DOUBLE shaped like `WC_Order`. It proves what the
 * reader does with what WooCommerce hands over — totals as decimal strings,
 * which is how `wc_format_decimal()` stores them. The real WooCommerce path
 * stays `Not Run` in this environment and is reported as such.
 */
final class OrderMoneyUnitTest extends ContractTestCase
{
    protected function tearDown(): void
    {
        State::$priceDecimals = 0;
        parent::tearDown();
    }

    /**
     * WHAT THIS PROVES: a ریال order — no decimals — is read at exponent 0 and
     * its amount is unchanged.
     */
    public function testAZeroDecimalOrderIsReadAtExponentZero(): void
    {
        State::$priceDecimals = 0;
        $reader = new WcOrderReader();
        $order = self::order('IRR', [['total' => '100000', 'tax' => '9000', 'qty' => 1]]);

        self::assertSame(['currency' => 'IRR', 'exponent' => 0], $reader->unit($order));
        $lines = $reader->lines($order);
        self::assertCount(1, $lines);
        self::assertSame(100000, $lines[0]['line_total_minor']);
        self::assertSame(9000, $lines[0]['line_tax_minor']);
        self::assertSame('IRR', $lines[0]['currency']);
        self::assertSame(0, $lines[0]['exponent']);
        self::assertSame('', $lines[0]['unit_error']);
    }

    /**
     * WHAT THIS PROVES: تومان is carried as the code the order was placed in,
     * not translated into something else.
     *
     * The contract, stated: the CURRENCY is whatever WooCommerce says, verbatim
     * and upper-cased; the SCALE is however many decimals the store keeps. A
     * تومان shop configured with no decimals behaves exactly like the ریال one
     * above — same arithmetic, different three letters on the record — and
     * nothing in this plugin converts between the two. That conversion is a
     * business decision and is not made here.
     */
    public function testTomanIsCarriedAsItsOwnCodeAndNotConverted(): void
    {
        State::$priceDecimals = 0;
        $reader = new WcOrderReader();
        $lines = $reader->lines(self::order('irt', [['total' => '2450000', 'tax' => '0', 'qty' => 1]]));

        self::assertSame('IRT', $lines[0]['currency'], 'upper-cased, never rewritten');
        self::assertSame(2450000, $lines[0]['line_total_minor'], 'and not divided by ten into ریال');
        self::assertSame(0, $lines[0]['exponent']);
        self::assertSame('', $lines[0]['unit_error']);
    }

    /**
     * WHAT THIS PROVES: a two-decimal currency is read at exponent 2, exactly.
     *
     * «ارز اعشاری در صورت پشتیبانی» — it is supported, because the exponent
     * travels with the amount instead of being assumed. `'10.50'` becomes
     * 1050 at 1e2, which is what `Money` and `tmc_ledger` then carry.
     */
    public function testATwoDecimalOrderIsReadAtExponentTwo(): void
    {
        State::$priceDecimals = 2;
        $reader = new WcOrderReader();
        $order = self::order('USD', [['total' => '10.50', 'tax' => '0.84', 'qty' => 2]]);

        self::assertSame(['currency' => 'USD', 'exponent' => 2], $reader->unit($order));
        $lines = $reader->lines($order);
        self::assertSame(1050, $lines[0]['line_total_minor']);
        self::assertSame(84, $lines[0]['line_tax_minor']);
        self::assertSame(2, $lines[0]['exponent']);
        self::assertSame('', $lines[0]['unit_error']);
    }

    /**
     * WHAT THIS PROVES: an amount with more places than the store keeps is
     * NAMED, not rounded.
     *
     * This is the case `(int) round((float) …)` answered silently. A total of
     * `'100000.50'` on a shop configured with no decimals is half a ریال that
     * cannot be stored; the line comes back with `amount_not_exact` and
     * `CaptureOrder` refuses it by name rather than recording 100,000 or
     * 100,001 and leaving nobody able to tell which.
     */
    public function testAnAmountTheStoreCannotHoldIsNamedRatherThanRounded(): void
    {
        State::$priceDecimals = 0;
        $reader = new WcOrderReader();
        $lines = $reader->lines(self::order('IRR', [['total' => '100000.50', 'tax' => '0', 'qty' => 1]]));

        self::assertSame('amount_not_exact', $lines[0]['unit_error']);
        self::assertSame(0, $lines[0]['line_total_minor'], 'no guess is carried either');
    }

    /** WHAT THIS PROVES: an order with no readable currency is named too. */
    public function testAnUnreadableCurrencyIsNamed(): void
    {
        $reader = new WcOrderReader();
        $lines = $reader->lines(self::order('', [['total' => '1000', 'tax' => '0', 'qty' => 1]]));
        self::assertSame('currency_unreadable', $lines[0]['unit_error']);

        $lines = $reader->lines(self::order('RIAL', [['total' => '1000', 'tax' => '0', 'qty' => 1]]));
        self::assertSame('currency_unreadable', $lines[0]['unit_error'], 'four letters is not a currency code');
    }

    /**
     * WHAT THIS PROVES: a store configured past what `Money` holds is refused
     * rather than clamped.
     */
    public function testAnExponentBeyondWhatMoneyHoldsIsRefused(): void
    {
        State::$priceDecimals = 9;
        $reader = new WcOrderReader();
        self::assertSame(-1, $reader->unit(self::order('USD', []))['exponent']);
        $lines = $reader->lines(self::order('USD', [['total' => '1.000000000', 'tax' => '0', 'qty' => 1]]));
        self::assertSame('exponent_unsupported', $lines[0]['unit_error']);
    }

    /**
     * An object shaped like `WC_Order`, with totals as the decimal STRINGS
     * WooCommerce stores.
     *
     * @param list<array{total:string, tax:string, qty:int}> $items
     */
    private static function order(string $currency, array $items): object
    {
        $lineObjects = [];
        foreach ($items as $i => $item) {
            $lineObjects[7100 + $i] = new class ($item) {
                /** @param array{total:string, tax:string, qty:int} $data */
                public function __construct(private readonly array $data)
                {
                }

                public function get_product_id(): int
                {
                    return 5001;
                }

                public function get_variation_id(): int
                {
                    return 0;
                }

                public function get_product(): ?object
                {
                    return null;
                }

                public function get_name(): string
                {
                    return 'کالای آزمون';
                }

                public function get_quantity(): int
                {
                    return $this->data['qty'];
                }

                public function get_total(): string
                {
                    return $this->data['total'];
                }

                public function get_total_tax(): string
                {
                    return $this->data['tax'];
                }
            };
        }
        return new class ($currency, $lineObjects) {
            /** @param array<int,object> $items */
            public function __construct(private readonly string $currency, private readonly array $items)
            {
            }

            public function get_id(): int
            {
                return 7000;
            }

            public function get_currency(): string
            {
                return $this->currency;
            }

            /** @return array<int,object> */
            public function get_items(): array
            {
                return $this->items;
            }
        };
    }
}
