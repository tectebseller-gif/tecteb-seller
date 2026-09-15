<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Events;

/**
 * The smallest possible way for one module to hear about another's decision.
 *
 * WHY THIS EXISTS: suspending a vendor is the vendor module's decision, and
 * taking their products out of the storefront is the product module's job.
 * Without something in between, one of three bad things happens — the vendor
 * module learns what a product is, the product module reaches into the vendor
 * module's services, or the rule is duplicated in whichever screen happens to
 * call the suspension.
 *
 * WordPress' own `do_action` would do this, but the Application layer may not
 * speak WordPress (that boundary is enforced by a test), and a suspension
 * that only took effect inside a web request would not take effect in WP-CLI
 * or in the test suite. So: plain PHP, synchronous, no priorities, no
 * cancellation.
 *
 * A listener that throws must not undo the decision that was already taken,
 * so exceptions are swallowed per listener and the bus keeps going. The
 * decision is already persisted by the time anything here runs.
 */
final class EventBus
{
    public const VENDOR_SUSPENDED = 'vendor.suspended';
    public const VENDOR_REINSTATED = 'vendor.reinstated';

    /** @var array<string, list<callable>> */
    private array $listeners = [];

    /** @var list<array{event:string, payload:array<string,mixed>}> */
    private array $emitted = [];

    public function on(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    /** @param array<string,mixed> $payload */
    public function emit(string $event, array $payload = []): void
    {
        $this->emitted[] = ['event' => $event, 'payload' => $payload];
        foreach ($this->listeners[$event] ?? [] as $listener) {
            try {
                $listener($payload);
            } catch (\Throwable) {
                // A failing listener never rolls back the decision that
                // caused it; the caller has already committed.
            }
        }
    }

    /** Test hook: what was emitted, in order. @return list<array{event:string, payload:array<string,mixed>}> */
    public function emitted(): array
    {
        return $this->emitted;
    }
}
