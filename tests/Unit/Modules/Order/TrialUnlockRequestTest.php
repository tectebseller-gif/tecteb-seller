<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Order;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Core\Environment\EnvironmentResolver;
use Tecteb\Marketplace\Modules\Order\Application\TrialUnlock;
use Tecteb\Marketplace\Tests\Support\FakeEnvironmentProbe;
use Tecteb\Marketplace\Tests\Support\InMemoryOptionStore;

/**
 * WHAT THIS PROVES: the switch can now be asked for from inside the plugin,
 * and asking for it is still not the same as being granted it.
 *
 * Until `alpha.23` `TrialUnlock::OPTION` had no writer anywhere in the
 * plugin. It could only be set from a terminal, by a tool that refuses to run
 * anywhere but the disposable install — so the owner's own staging site could
 * satisfy every other condition and never open the order module, and the
 * install guide had to end in «ask us to run a command».
 *
 * Adding a writer is only safe because the dangerous half was never in the
 * option: `isPermitted()` reads the environment and decides, and the option
 * only records that somebody asked. These tests pin that separation, because
 * a future refactor that folded the two together would look like a tidy-up
 * and would quietly arm production.
 */
final class TrialUnlockRequestTest extends TestCase
{
    private function unlock(InMemoryOptionStore $options, string $platform): TrialUnlock
    {
        return new TrialUnlock(
            $options,
            new EnvironmentResolver(new FakeEnvironmentProbe(null, null, $platform))
        );
    }

    public function testRequestingOnAStagingSiteActivatesIt(): void
    {
        $options = new InMemoryOptionStore();
        $trial = $this->unlock($options, 'staging');

        self::assertFalse($trial->isRequested(), 'off by default: somebody has to ask');
        self::assertTrue($trial->request(true));
        self::assertTrue($trial->isRequested());
        self::assertTrue($trial->isActive());
        self::assertSame('', $trial->refusal());
    }

    public function testTurningItOffRemovesTheRequest(): void
    {
        $options = new InMemoryOptionStore();
        $trial = $this->unlock($options, 'local');
        $trial->request(true);

        self::assertTrue($trial->request(false));
        self::assertFalse($trial->isRequested());
        self::assertFalse($trial->isActive());
    }

    /**
     * The one that matters. A writer that could arm production would have
     * turned a documented refusal into a button.
     */
    public function testRequestingOnProductionWritesTheAskAndGrantsNothing(): void
    {
        $options = new InMemoryOptionStore();
        $trial = $this->unlock($options, 'production');

        self::assertTrue($trial->request(true), 'the ask is recorded');
        self::assertTrue($trial->isRequested());
        self::assertFalse($trial->isPermitted());
        self::assertFalse($trial->isActive(), 'production never honours it, however it was set');
        self::assertSame('environment_production', $trial->refusal());
    }

    /**
     * An environment nobody declared is production, so the switch is refused
     * there too — the same rule that makes «unknown» unsafe by default.
     */
    public function testAnUndeclaredEnvironmentIsRefusedLikeProduction(): void
    {
        $options = new InMemoryOptionStore();
        $trial = new TrialUnlock(
            $options,
            new EnvironmentResolver(new FakeEnvironmentProbe(null, null, null))
        );
        $trial->request(true);

        self::assertFalse($trial->isActive());
        self::assertSame('environment_unknown', $trial->refusal());
    }
}
