<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Modules\Product\Application\PurchasePolicy;
use Tecteb\Marketplace\Modules\Product\Domain\PurchaseQuantity;
use Tecteb\Marketplace\Modules\Product\Presentation\PurchaseMessages;

/**
 * The purchase-quantity rule, enforced — on the page, in the cart, and at the
 * till.
 *
 * `CartGuard` answers «may this be bought at all». This one answers «how
 * many», and it is a class of its own for that reason: the two questions have
 * different answers, different messages and different hooks, and one class
 * answering both would be a class nobody can read.
 *
 * ---------------------------------------------------------------------------
 * ONE FILTER DOES MOST OF THE WORK, AND THAT WAS MEASURED
 * ---------------------------------------------------------------------------
 *
 * WooCommerce 11.0.1's Store API — which is what the block cart and block
 * checkout speak — builds its own quantity limits out of the CLASSIC filter:
 *
 *     $args = wc_get_quantity_input_args( [], $product );
 *              — src/StoreApi/Utilities/QuantityLimits.php:52
 *
 * So `woocommerce_quantity_input_args` reaches the classic product page AND
 * the blocks, and WooCommerce then enforces the limits in the block cart
 * itself. Hand-rolling a second rule for the blocks would have been two rules
 * that disagree. The validators below are therefore a SECOND line, not the
 * first: they exist because a filter that only decorates an input box is
 * bypassed by any hand-built POST, and because the one thing WooCommerce
 * cannot know is the rule's scope.
 *
 * ---------------------------------------------------------------------------
 * HOW IT COMPOSES WITH WOOCOMMERCE'S OWN LIMITS
 * ---------------------------------------------------------------------------
 *
 * `WC_Product::get_max_purchase_quantity()` already answers `1` for a product
 * sold individually and the stock quantity for one that manages stock without
 * backorders. Those are WooCommerce's numbers about WooCommerce's subject, and
 * §4 asks that this rule not contradict them. It does not, by two rules that
 * are stated rather than emergent:
 *
 *  - **a ceiling is only ever lowered, never raised.** Where WooCommerce
 *    already names a maximum, ours applies only if it is smaller. Stock and
 *    backorder therefore keep deciding what they decide, and a vendor's
 *    «at most 10» cannot sell an eleventh unit that is not there.
 *  - **sold individually wins outright.** WooCommerce forces one unit, and a
 *    minimum of two over the top of that would make the product unbuyable:
 *    the input would ask for 2 and the cart would hold 1 for ever. So when a
 *    product is sold individually this rule stands down completely, and the
 *    contradiction is REPORTED (`CONTRADICTION_META`) rather than resolved in
 *    silence — a manager set one of the two and should be told the other
 *    exists.
 *
 * Stock is never read here. A maximum is the vendor's selling rule, stock is
 * WooCommerce's number (ADR-008), and they meet at the `min()` above.
 *
 * ---------------------------------------------------------------------------
 * THE WHOLE BASKET, NOT ONE LINE
 * ---------------------------------------------------------------------------
 *
 * WooCommerce will hold the same item in two cart lines. A maximum enforced
 * per line is a maximum of twice itself, so every check sums the quantity of
 * THAT item across the cart — the variation for a variable product, the
 * product for a simple one, which is the sellable item a buyer chose.
 *
 * Scoped like everything else in this plugin: an item that is not the
 * marketplace's own is left exactly as WooCommerce left it.
 */
final class QuantityGuard
{
    /**
     * Keys whose rule is suspended because WooCommerce's own setting
     * contradicts it. Written on the product, read by the manager's screens.
     */
    public const CONTRADICTION_META = '_tmc_quantity_contradiction';

    public static function register(ContainerInterface $container): void
    {
        $policy = static fn (): PurchasePolicy => $container->get(PurchasePolicy::class);

        // 1. The numbers on the page — and, through the Store API, in the
        //    blocks. Late priority so a theme or another plugin that lowers a
        //    ceiling has already done so and is not raised back up.
        add_filter('woocommerce_quantity_input_args', static function (mixed $args, mixed $product) use ($policy): mixed {
            if (!is_array($args)) {
                return $args;
            }
            $rule = self::ruleFor($policy(), $product);
            if ($rule === null) {
                return $args;           // not ours, or nothing to say
            }
            // AN IMPOSSIBLE RULE WIDENS NOTHING. There is no min/max/step
            // triple that expresses «no quantity at all» through this filter —
            // `wc_get_quantity_input_args()` reads a `max_value` of 0 as «no
            // maximum» — so the numbers are left exactly as WooCommerce
            // computed them and every validator below refuses instead. Writing
            // our own numbers here would be the one thing contradictory data
            // must not do: change what may be bought.
            if (!$rule->isSatisfiable()) {
                return $args;
            }
            $args['min_value'] = $rule->min;
            $args['step'] = $rule->step;
            $ceiling = $rule->largestAllowed();
            if ($ceiling !== null) {
                $theirs = (int) ($args['max_value'] ?? -1);
                // ONLY LOWER. `-1` and `0` are WooCommerce's «no maximum»;
                // anything positive is a real ceiling — stock, or somebody
                // else's rule — and raising it would oversell.
                $args['max_value'] = $theirs > 0 ? min($theirs, $ceiling) : $ceiling;
            }
            return $args;
        }, 20, 2);

        // 2. Adding to the cart, classic — and every hand-built POST that
        //    reaches the same handler, with or without JavaScript.
        add_filter(
            'woocommerce_add_to_cart_validation',
            static function (mixed $passed, mixed $productId, mixed $quantity, mixed $variationId = 0) use ($policy): mixed {
                if ($passed !== true) {
                    return $passed;     // already refused for some other reason
                }
                $itemId = (int) $variationId > 0 ? (int) $variationId : (int) $productId;
                $product = function_exists('wc_get_product') ? wc_get_product($itemId) : null;
                $rule = self::ruleFor($policy(), $product);
                if ($rule === null) {
                    return $passed;
                }
                // THE WHOLE BASKET. What is already in the cart plus what is
                // being added is the quantity this buyer would end up with.
                $wanted = (int) $quantity + self::inCart($itemId);
                return self::pass($rule, $wanted, $product);
            },
            20,
            4
        );

        // 3. Changing a quantity in the classic cart.
        add_filter(
            'woocommerce_update_cart_validation',
            static function (mixed $passed, mixed $cartItemKey, mixed $values, mixed $quantity) use ($policy): mixed {
                if ($passed !== true || !is_array($values)) {
                    return $passed;
                }
                $product = $values['data'] ?? null;
                $rule = self::ruleFor($policy(), $product);
                if ($rule === null) {
                    return $passed;
                }
                $itemId = is_object($product) && method_exists($product, 'get_id') ? (int) $product->get_id() : 0;
                // The other lines holding the same item, plus the new figure
                // for this one.
                $wanted = (int) $quantity + self::inCart($itemId, (string) $cartItemKey);
                return self::pass($rule, $wanted, $product);
            },
            20,
            4
        );

        // 4. THE TILL. Runs on the cart page and on the classic checkout, so
        //    a session that was filled before the rule changed — or a request
        //    that slipped past the two filters above — is caught before money
        //    is taken. This is the one check an attacker cannot route around,
        //    because WooCommerce itself calls it before placing the order.
        add_action('woocommerce_check_cart_items', static function () use ($policy): void {
            if (!function_exists('WC') || WC()->cart === null) {
                return;
            }
            foreach (self::basketTotals(WC()->cart) as $itemId => [$quantity, $product]) {
                $rule = self::ruleFor($policy(), $product);
                if ($rule === null) {
                    continue;
                }
                $reason = $rule->check($quantity);
                if ($reason !== PurchaseQuantity::OK && function_exists('wc_add_notice')) {
                    wc_add_notice(PurchaseMessages::quantity($reason, $rule, self::nameOf($product)), 'error');
                }
            }
        }, 30);

        // 5. The Store API's own validation points. WooCommerce already
        //    enforces the limits derived from filter 1, so reaching a refusal
        //    here means a request that bypassed them — which is exactly the
        //    case §4 asks about.
        add_action('woocommerce_store_api_validate_cart_item', static function (mixed $product, mixed $cartItem) use ($policy): void {
            $rule = self::ruleFor($policy(), $product);
            if ($rule === null) {
                return;
            }
            $itemId = is_object($product) && method_exists($product, 'get_id') ? (int) $product->get_id() : 0;
            $quantity = is_array($cartItem) ? (int) ($cartItem['quantity'] ?? 0) : 0;
            // The line's own quantity is in the cart already, so the basket
            // total is read whole rather than added to.
            $total = max($quantity, self::inCart($itemId));
            $reason = $rule->check($total);
            if ($reason === PurchaseQuantity::OK) {
                return;
            }
            self::refuse(PurchaseMessages::quantity($reason, $rule, self::nameOf($product)));
        }, 30, 2);
    }

    /**
     * The rule for one shop product, or `null` when there is nothing to say.
     *
     * `null` covers three different cases on purpose, because the caller does
     * the same thing in all three — nothing:
     *
     *  - the product is not the marketplace's own;
     *  - the rule is unrestricted, so saying it would change no number and
     *    add no notice;
     *  - the product is sold individually, where WooCommerce's own one-unit
     *    rule wins and ours would make the item unbuyable.
     */
    private static function ruleFor(PurchasePolicy $policy, mixed $product): ?PurchaseQuantity
    {
        if (!is_object($product) || !method_exists($product, 'get_id')) {
            return null;
        }
        // Ours? Asked with the same question `CartGuard` asks, so a variation
        // is answered by its parent's marketplace link rather than called
        // somebody else's.
        //
        // AND, SINCE `alpha.55`, ONE NARROWER QUESTION AFTER IT. A product
        // the marketplace does not own can still carry a quantity rule this
        // plugin wrote — that is the Min Max handover's `written_unenforced`
        // state, and until now those three rows were read by nothing:
        // measured with Min Max switched off, a non-marketplace product's
        // `4|6|8|10` became «any quantity allowed». A manager may now GRANT
        // that this marketplace enforces that one rule
        // (`MigrateMinMax::enforce()`), and the grant is read here and
        // nowhere else.
        //
        // What it deliberately does not do: `PurchasePolicy` still answers
        // `NOT_OURS` about the product, so the purchase guard, the
        // storefront stop, the projector, the vendor's catalogue and the
        // approval cycle all go on ignoring it. «این مجوز نباید خودبه‌خود
        // فروشنده، مالکیت محصول یا چرخهٔ تأیید آن را تغییر دهد.» And a
        // product with no grant behaves exactly as it did before this
        // existed — the grant is written by one explicit action on one
        // migrated product, so «no new behaviour for a product that was not
        // migrated» is a property of where the key comes from, not a check.
        //
        // AND THE GRANT IS THE RULE, NOT A FLAG. It is stored as a
        // fingerprint of the three values it was granted for, so a rule that
        // drifts afterwards — a hand in Min Max, a hand in the WooCommerce
        // panel — is not enforced: the grant describes numbers that are no
        // longer there, and acting on it would be enforcing a rule nobody
        // approved. Such a product then behaves exactly as it did before any
        // grant existed, which is the state the exit report calls
        // `written_unenforced`.
        $rows = self::quantityRows($product);
        if (PurchaseGuard::ask($policy, $product) === null && !self::ruleIsGranted($rows)) {
            return null;
        }
        $rule = self::storedRule($policy, $product, $rows['meta']);
        if ($rule->isUnrestricted()) {
            return null;
        }
        if (method_exists($product, 'is_sold_individually') && $product->is_sold_individually() === true) {
            self::recordContradiction($product);
            return null;
        }
        // An UNSATISFIABLE rule is returned, not dropped: dropping it would
        // let the product be bought under WooCommerce's own defaults, which is
        // exactly the increase in purchase permission that contradictory data
        // must not produce. It is recorded too, so the manager's screens can
        // say why the product cannot be bought.
        if (!$rule->isSatisfiable()) {
            self::recordContradiction($product, 'unsatisfiable');
        }
        return $rule;
    }

    /**
     * The three rows and the grant written beside them, AS ONE PAIR.
     *
     * Read off the product object the hook was handed, so the common case
     * costs no query: `WC_Product` already has its meta loaded.
     *
     * The pairing is the point. A variation-level Min Max rule is migrated on
     * the variation and granted on the variation; a parent-level rule is
     * migrated and granted on the parent. So when this object has no rows of
     * its own, the parent's rows AND the parent's grant are taken together —
     * never one without the other, which would be a grant checked against
     * somebody else's numbers. The parent has to be FETCHED to ask, because
     * `WC_Data::get_meta()` does not fall back to the parent for an arbitrary
     * key (read from `abstract-wc-data.php`: it reads this object's own
     * `meta_data` and nothing else; `WC_Product_Variation`'s parent fallbacks
     * are per-property getters — sku, weight, stock — not meta).
     *
     * @return array{meta: array<string,string>, grant: string}
     */
    private static function quantityRows(mixed $product): array
    {
        $meta = [];
        foreach (ProjectedQuantity::keys() as $key) {
            $meta[$key] = '';
        }
        if (!is_object($product) || !method_exists($product, 'get_meta')) {
            return ['meta' => $meta, 'grant' => ''];
        }
        foreach (ProjectedQuantity::keys() as $key) {
            $meta[$key] = (string) $product->get_meta($key, true);
        }
        $grant = (string) $product->get_meta(ProjectedQuantity::ENFORCE_META, true);
        if (!self::allEmpty($meta)) {
            return ['meta' => $meta, 'grant' => $grant];
        }
        $parentId = method_exists($product, 'get_parent_id') ? (int) $product->get_parent_id() : 0;
        if ($parentId <= 0 || !function_exists('wc_get_product')) {
            return ['meta' => $meta, 'grant' => $grant];
        }
        $parent = wc_get_product($parentId);
        if (!is_object($parent) || !method_exists($parent, 'get_meta')) {
            return ['meta' => $meta, 'grant' => $grant];
        }
        foreach (ProjectedQuantity::keys() as $key) {
            $meta[$key] = (string) $parent->get_meta($key, true);
        }
        return [
            'meta' => $meta,
            'grant' => (string) $parent->get_meta(ProjectedQuantity::ENFORCE_META, true),
        ];
    }

    /**
     * Whether the manager has granted that this marketplace enforces THE RULE
     * THESE ROWS NOW HOLD.
     *
     * The stored grant is a fingerprint of the three values it was granted
     * for (`ProjectedQuantity::ruleFingerprint()`), so this is an equality,
     * not a truthiness test: a rule that changed after the grant does not
     * match and is not enforced. `hash_equals` because the two sides are
     * hashes and this is the comparison a permission hangs on.
     *
     * @param array{meta: array<string,string>, grant: string} $rows
     */
    private static function ruleIsGranted(array $rows): bool
    {
        if ($rows['grant'] === '') {
            return false;
        }
        return hash_equals($rows['grant'], ProjectedQuantity::ruleFingerprint($rows['meta']));
    }

    /**
     * The rule in force for one shop product.
     *
     * `$meta` is the three rows `quantityRows()` already resolved — read from
     * the PRODUCT the hook was handed, with the parent's rows substituted when
     * this object has none. It is passed in rather than read again because the
     * grant check needs exactly the same three values: two reads of one thing
     * are one more way to disagree.
     *
     * ---------------------------------------------------------------------
     * AND WHEN THERE ARE NO ROWS AT ALL, THE MARKETPLACE ROW ANSWERS
     * ---------------------------------------------------------------------
     *
     * `ProjectedQuantity::ruleFrom()` reads an absent row as the semantics
     * `PurchaseQuantity` writes down — minimum 1, no maximum, step 1 — which
     * is right for a product this plugin has never projected: WooCommerce's
     * own defaults are the answer there, and nothing downstream has to
     * re-check. It is WRONG for a product that HAS a rule and whose rows are
     * momentarily gone, and `alpha.46` built exactly that: the panel's «back
     * to automatic» deleted the three rows and waited for the next
     * projection. Measured on `alpha.46` in a fresh request, with the
     * marketplace rule reading `2/6/2`: `input_args=1/40/1` and quantities
     * 1, 3 and 8 all `allowed`, in the classic cart and through the Store
     * API alike. A reset increased what could be bought.
     *
     * So absence on OUR OWN product asks the marketplace row — the source of
     * truth these three rows are a projection of (ADR-008) — and only falls
     * back to «unrestricted» when there is no row to ask. Three things make
     * that safe rather than clever:
     *
     *  - it costs no query. `ruleFor()` has already asked
     *    `PurchaseGuard::ask()`, so the row is in `PurchasePolicy`'s
     *    per-request memo;
     *  - it can only ever narrow. The marketplace rule is the declared
     *    rule; reading it instead of WooCommerce's defaults removes no
     *    restriction and adds no permission;
     *  - and it is the same answer for every cause of absence — this
     *    plugin's reset, a manager deleting the rows by hand, a projection
     *    that half failed. A fix that only closed the reset path would leave
     *    the other two open.
     *
     * @param array<string,string> $meta
     */
    private static function storedRule(
        PurchasePolicy $policy,
        mixed $product,
        array $meta
    ): PurchaseQuantity {
        if (self::allEmpty($meta)) {
            $row = PurchaseGuard::marketplaceProduct($policy, $product);
            if ($row !== null) {
                return $row->details->purchaseQuantity();
            }
        }
        return ProjectedQuantity::ruleFrom($meta);
    }

    /** @param array<string,string> $meta */
    private static function allEmpty(array $meta): bool
    {
        foreach ($meta as $value) {
            if (trim($value) !== '') {
                return false;
            }
        }
        return true;
    }

    /**
     * How many of this item the cart already holds, across every line.
     *
     * `$exceptKey` leaves out the line being changed, so a quantity update is
     * compared against the OTHER lines plus its new figure rather than
     * against itself twice.
     */
    private static function inCart(int $itemId, string $exceptKey = ''): int
    {
        if ($itemId <= 0 || !function_exists('WC') || WC()->cart === null) {
            return 0;
        }
        $total = 0;
        foreach (WC()->cart->get_cart() as $key => $item) {
            if ((string) $key === $exceptKey || !is_array($item)) {
                continue;
            }
            $lineId = (int) ($item['variation_id'] ?? 0) > 0
                ? (int) $item['variation_id']
                : (int) ($item['product_id'] ?? 0);
            if ($lineId === $itemId) {
                $total += (int) ($item['quantity'] ?? 0);
            }
        }
        return $total;
    }

    /**
     * Every sellable item in the cart with its TOTAL quantity.
     *
     * @return array<int,array{0:int,1:mixed}>
     */
    private static function basketTotals(mixed $cart): array
    {
        if (!is_object($cart) || !method_exists($cart, 'get_cart')) {
            return [];
        }
        $totals = [];
        foreach ($cart->get_cart() as $item) {
            if (!is_array($item)) {
                continue;
            }
            $itemId = (int) ($item['variation_id'] ?? 0) > 0
                ? (int) $item['variation_id']
                : (int) ($item['product_id'] ?? 0);
            if ($itemId <= 0) {
                continue;
            }
            $quantity = (int) ($item['quantity'] ?? 0);
            if (isset($totals[$itemId])) {
                $totals[$itemId][0] += $quantity;
                continue;
            }
            $totals[$itemId] = [$quantity, $item['data'] ?? null];
        }
        return $totals;
    }

    /** `true` to let it through, `false` after saying why. */
    private static function pass(PurchaseQuantity $rule, int $wanted, mixed $product): bool
    {
        $reason = $rule->check($wanted);
        if ($reason === PurchaseQuantity::OK) {
            return true;
        }
        if (function_exists('wc_add_notice')) {
            wc_add_notice(PurchaseMessages::quantity($reason, $rule, self::nameOf($product)), 'error');
        }
        return false;
    }

    private static function nameOf(mixed $product): string
    {
        return is_object($product) && method_exists($product, 'get_name') ? (string) $product->get_name() : '';
    }

    /**
     * Sold individually AND a quantity rule: recorded, not resolved.
     *
     * Written only when it changes, so a product in this state does not take
     * a database write on every page view.
     */
    private static function recordContradiction(mixed $product, string $kind = 'sold_individually'): void
    {
        if (!method_exists($product, 'get_id') || !function_exists('update_post_meta')) {
            return;
        }
        $id = (int) $product->get_id();
        if ($id <= 0) {
            return;
        }
        if ((string) get_post_meta($id, self::CONTRADICTION_META, true) !== $kind) {
            update_post_meta($id, self::CONTRADICTION_META, $kind);
        }
    }

    /** The type the Store API's own validator catches (`CartGuard`'s rule). */
    private static function refuse(string $message): void
    {
        $routeException = 'Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';
        if (class_exists($routeException)) {
            throw new $routeException('tmc_quantity_not_allowed', $message, 409);
        }
        throw new \RuntimeException($message);
    }
}
