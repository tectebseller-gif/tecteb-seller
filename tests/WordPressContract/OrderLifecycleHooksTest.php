<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Core\Container;
use Tecteb\Marketplace\Modules\Order\Application\SyncOrderLifecycle;
use Tecteb\Marketplace\Modules\Order\Infrastructure\WordPress\OrderHooks;
use TmcWpStubs\State;

/**
 * The order's status reaches the marketplace.
 *
 * ⛔ **Why this file exists, and why the falsification loop wrote it rather
 * than I did.** Every test of `alpha.99`'s P0 called `SyncOrderLifecycle`
 * directly. The whole fix hangs on two `add_action()` calls, and a mutation
 * that deleted BOTH of them left all five suites green — `alpha.80`'s rule
 * («a function with no caller is not a feature») in the one place it would
 * have cost the most, because the defect being closed is itself a hook that
 * was registered and did the wrong thing.
 *
 * So this asks the question through the hook: the action is FIRED, with the
 * arguments WooCommerce fires it with, and what the service was told is read
 * off a spy. Registration alone is not asserted anywhere here — a hook
 * registered with one accepted argument would be registered and would hand
 * the sync an empty status.
 */
final class OrderLifecycleHooksTest extends ContractTestCase
{
    /**
     * WHAT THIS PROVES: an ordinary status change reaches the sync, and the
     * status it carries is the NEW one.
     *
     * The third argument is the whole point. On
     * `woocommerce_order_status_changed` the order object may not have been
     * saved with the new value yet, so a listener that read it back would act
     * on the old status — and on a cancel that means acting on `processing`,
     * which writes nothing. Registering the hook with fewer accepted
     * arguments produces exactly that, silently.
     */
    public function testACancelledOrderReachesTheSyncWithTheNewStatus(): void
    {
        $spy = $this->register();

        do_action('woocommerce_order_status_changed', 12300, 'processing', 'cancelled');

        self::assertSame([[12300, 'cancelled']], $spy->calls);
    }

    /** Paid, failed, refunded — the sync decides, not the hook. */
    public function testEveryStatusIsForwardedAndNoneIsFilteredAtTheHook(): void
    {
        $spy = $this->register();

        foreach (['processing', 'completed', 'failed', 'refunded', 'on-hold'] as $to) {
            do_action('woocommerce_order_status_changed', 12299, 'pending', $to);
        }

        self::assertSame([
            [12299, 'processing'], [12299, 'completed'], [12299, 'failed'],
            [12299, 'refunded'], [12299, 'on-hold'],
        ], $spy->calls);
    }

    /**
     * WHAT THIS PROVES: trashing an order is a transition WordPress makes and
     * WooCommerce announces with no status argument at all.
     *
     * Nothing else fires for it, so without its own hook a trashed order
     * would keep its obligation — and `WcOrderLifecycle::VOID` names `trash`
     * precisely because this path exists.
     */
    public function testTrashingAnOrderReachesTheSyncAsTrash(): void
    {
        $spy = $this->register();

        do_action('woocommerce_trash_order', 12300);

        self::assertSame([[12300, 'trash']], $spy->calls);
    }

    /** An order object rather than an id, which `woocommerce_new_order` style hooks pass. */
    public function testAnOrderObjectIsResolvedToItsId(): void
    {
        $spy = $this->register();
        $order = new class () {
            public function get_id(): int
            {
                return 12301;
            }
        };

        do_action('woocommerce_order_status_changed', $order, 'pending', 'cancelled');

        self::assertSame([[12301, 'cancelled']], $spy->calls);
    }

    /**
     * WHAT THIS PROVES: a sync that throws does not break a manager's click.
     *
     * The same posture the capture has had since `alpha.7`. A status change
     * happens inside somebody's wp-admin request, and a marketplace that
     * could not record it must not take the page down with it.
     */
    public function testAThrowingSyncDoesNotEscapeTheHook(): void
    {
        $container = new Container();
        $container->bind(SyncOrderLifecycle::class, static fn () => new class () {
            public function sync(int $orderId, string $status): array
            {
                throw new \RuntimeException('the database went away');
            }
        });
        OrderHooks::register($container);

        do_action('woocommerce_order_status_changed', 12300, 'processing', 'cancelled');

        self::assertTrue(true, 'the action returned');
    }

    /** An id of nought is not an order, and the sync is not asked about it. */
    public function testAnOrderWithoutAnIdIsNotSynced(): void
    {
        $spy = $this->register();

        do_action('woocommerce_order_status_changed', 0, 'processing', 'cancelled');

        self::assertSame([], $spy->calls);
    }

    /**
     * WHAT THIS PROVES: the stock mirror still runs.
     *
     * `alpha.99` added a second listener to a hook that already had one, and
     * the one that was there refreshes the stock mirror — measured on a real
     * site rather than assumed (`alpha.48`). Two callbacks on one tag is the
     * shape that quietly loses one of them.
     */
    public function testTheStockMirrorIsStillRegisteredOnTheSameHook(): void
    {
        $this->register();

        $callbacks = 0;
        foreach (State::$hooks['woocommerce_order_status_changed'] ?? [] as $byPriority) {
            $callbacks += count($byPriority);
        }
        self::assertSame(2, $callbacks, 'the lifecycle sync AND the stock refresh');
    }

    /** @return object{calls:list<array{0:int,1:string}>} */
    private function register(): object
    {
        $spy = new class () {
            /** @var list<array{0:int,1:string}> */
            public array $calls = [];

            public function sync(int $orderId, string $status): array
            {
                $this->calls[] = [$orderId, $status];
                return [];
            }
        };
        $container = new Container();
        $container->bind(SyncOrderLifecycle::class, static fn () => $spy);
        OrderHooks::register($container);
        return $spy;
    }
}
