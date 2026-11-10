<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: the guard for «the order module is not running» is not
 * registered by the order module.
 *
 * ⛔ **Why that is the whole test.** `ModuleLoader` asks a self-gated module
 * `blockedReason()` BEFORE `register()`, so when the order module is blocked
 * neither its services nor `OrderHooks` exist. A guard wired from inside it
 * would be wired in the one state it never runs — registered, plausible, and
 * dead. `alpha.80`'s rule with the stakes reversed: here the call site is the
 * feature.
 *
 * It also pins that the guard cannot refuse. Every hook it uses fires after
 * the order row exists, so throwing would abandon an order somebody may have
 * paid for; the refusal belongs at the cart, where `CartGuard` already has it.
 * A later round that «improves» this into a refusal has to come past this
 * test and read why.
 */
final class UnrecordableOrderGuardIsWiredOutsideTheModuleTest extends TestCase
{
    private const GUARD = 'src/Modules/Order/Infrastructure/WordPress/UnrecordableOrderGuard.php';
    private const BOOTSTRAP = 'src/Infrastructure/WordPress/Bootstrap.php';
    private const MODULE = 'src/Modules/Order/OrderModule.php';

    public function testBootstrapRegistersItAndTheOrderModuleDoesNot(): void
    {
        self::assertStringContainsString(
            'UnrecordableOrderGuard::register($container)',
            self::code(self::BOOTSTRAP),
            'the guard has to be wired where a blocked module cannot stop it'
        );
        self::assertStringNotContainsString(
            'UnrecordableOrderGuard',
            self::code(self::MODULE),
            'wired from the order module it would never run, because a blocked '
            . 'module does not reach register() at all'
        );
    }

    /**
     * And it is wired behind WooCommerce being there, not behind the gate.
     *
     * Asking the gate here would reintroduce the hole from the other side: the
     * module can also be blocked by a failed dependency or a throwing
     * `register()`, and in both of those the gate reads ready.
     */
    public function testTheWiringAsksAboutWooCommerceAndNotAboutTheGate(): void
    {
        $bootstrap = self::code(self::BOOTSTRAP);
        $at = strpos($bootstrap, 'UnrecordableOrderGuard::register');
        self::assertIsInt($at);
        $before = substr($bootstrap, max(0, $at - 200), 200);

        self::assertStringContainsString('$wooCommerceAvailable', $before);
        self::assertStringNotContainsString('OrderOperationsGate', $before);
    }

    /** It asks the load report, which covers all three ways the module stays off. */
    public function testItAsksWhetherTheModuleIsActiveRatherThanWhetherTheGateIsReady(): void
    {
        $code = self::code(self::GUARD);

        self::assertStringContainsString("status('order')", $code);
        foreach (['ModuleStatus::Blocked', 'ModuleStatus::Degraded', 'ModuleStatus::Planned'] as $state) {
            self::assertStringContainsString($state, $code, $state . ' is a state with no hooks');
        }
        self::assertStringNotContainsString(
            'OrderOperationsGate',
            $code,
            'the gate answers a narrower question than «did anything register the hooks»'
        );
    }

    /** The three doors an order comes in by — the same list `OrderHooks` captures on. */
    public function testItWatchesEveryWayAnOrderIsCreated(): void
    {
        $code = self::code(self::GUARD);

        foreach ([
            'woocommerce_checkout_order_processed',
            'woocommerce_store_api_checkout_order_processed',
            'woocommerce_new_order',
        ] as $hook) {
            self::assertStringContainsString($hook, $code, $hook . ' creates orders too');
        }
    }

    /**
     * It marks; it does not refuse, and it does not touch the order's state.
     *
     * `update_meta_data` and `save` are how the mark is written through the
     * order's own CRUD layer, so they are expected. Anything that changes what
     * the order IS, is not.
     */
    public function testItChangesNothingAboutTheOrderAndNeverThrows(): void
    {
        $code = self::code(self::GUARD);

        foreach ([
            'set_status', 'update_status', 'wc_reduce_stock_levels',
            'wc_increase_stock_levels', 'wc_maybe_reduce_stock_levels',
            'remove_item', 'add_item', 'delete(', 'wp_delete_post',
            'throw new', 'wp_die',
        ] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $code,
                $forbidden . ' would act on an order that already exists; the refusal belongs at the cart'
            );
        }
        self::assertStringContainsString(
            'catch (\Throwable)',
            $code,
            'a marketplace defect must never be why a customer order fails to save'
        );
    }

    /**
     * Scoped: it only ever acts on an order holding a marketplace line, and
     * the scoping comes from the one class that answers that question.
     */
    public function testItOnlyActsOnOrdersHoldingAMarketplaceLine(): void
    {
        $code = self::code(self::GUARD);

        self::assertStringContainsString('PurchasePolicy::NOT_OURS', $code);
        self::assertStringContainsString('get_product_id', $code, 'the parent id is the id tmc_products holds');
    }

    private static function code(string $relative): string
    {
        $raw = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
        $out = '';
        foreach (token_get_all($raw) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }
}
