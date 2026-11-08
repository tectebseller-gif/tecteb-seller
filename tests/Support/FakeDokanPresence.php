<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Modules\Order\Application\DokanPresenceInterface;

/**
 * Dokan's presence, without Dokan — which is the only way to express it here.
 *
 * ⛔ Dokan is UNRUNNABLE in this environment and that is measured, not
 * assumed: its first line `require`s `abzarwp/`, every file of which is
 * SourceGuardian-encrypted with no loader available (`alpha.53`). So the
 * guard that refuses to record an order while Dokan runs cannot be exercised
 * against the real thing anywhere in this repository, and a double that
 * models the two answers is what the tests have.
 *
 * `signal()` matters as much as `isRunning()`: the gate puts it in the
 * refusal detail so a manager reading the screen is told WHICH signal fired,
 * and a double that returned an empty string while claiming to run would let
 * a test pass on a screen that says nothing.
 */
final class FakeDokanPresence implements DokanPresenceInterface
{
    /** Writable, so one scenario can switch Dokan off mid-test. */
    public bool $running;

    public function __construct(bool $running, private readonly string $signal = 'class_WeDevs_Dokan')
    {
        $this->running = $running;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function signal(): string
    {
        return $this->running ? $this->signal : '';
    }
}
