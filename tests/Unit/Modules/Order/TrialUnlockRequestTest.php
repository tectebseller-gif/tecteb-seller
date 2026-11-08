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
    private const HERE = 'staging.tecteb.com';

    /**
     * ⛔ The host is supplied now, and it was not before — the fixture, not
     * the rule, is what changed.
     *
     * Three tests in this file failed the moment a grant started carrying the
     * site it was granted on, because they built a site with no host at all.
     * A real WordPress always has one (`home_url()`), so the old fixture was
     * describing a site that cannot exist; passing one restores exactly the
     * behaviour they were written to pin. The NEW rule gets its own tests
     * below rather than being folded into theirs.
     */
    private function unlock(InMemoryOptionStore $options, string $platform, string $host = self::HERE): TrialUnlock
    {
        return new TrialUnlock(
            $options,
            new EnvironmentResolver(new FakeEnvironmentProbe(null, null, $platform, $host))
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
            new EnvironmentResolver(new FakeEnvironmentProbe(null, null, null, self::HERE))
        );
        $trial->request(true);

        self::assertFalse($trial->isActive());
        self::assertSame('environment_unknown', $trial->refusal());
    }

    /**
     * ⛔ THE GUARD ADDED THIS ROUND: a grant does not travel with a database.
     *
     * `PRODUCTION-CUTOVER-FA.md` is a plan to clone a tested staging site onto
     * production, and both of the older conditions survive that clone — the
     * option is a row in `wp_options` and the constant is a line in
     * `wp-config.php`. So a staging site with the trial on, copied onto
     * production and still declaring itself staging, would have sold on
     * sample rules. The host is the one thing the copy cannot bring.
     */
    public function testAGrantMadeOnAnotherSiteIsRefusedHere(): void
    {
        $options = new InMemoryOptionStore();
        $this->unlock($options, 'staging', 'staging.tecteb.com')->request(true);

        // The same database, now being read on a different site that still
        // calls itself staging.
        $elsewhere = $this->unlock($options, 'staging', 'tecteb.com');

        self::assertTrue($elsewhere->isRequested(), 'the ask is still in the database');
        self::assertTrue($elsewhere->isPermitted(), 'and this site still says it may honour it');
        self::assertFalse($elsewhere->isForThisSite());
        self::assertFalse($elsewhere->isActive(), 'and it is still refused');
        self::assertSame('host_changed', $elsewhere->refusal());
    }

    /**
     * A grant written by `alpha.97` or earlier carries no host, and is
     * refused rather than trusted — fail-closed, at the cost of one press.
     */
    public function testAGrantWithNoHostRecordedIsRefused(): void
    {
        $options = new InMemoryOptionStore();
        // Exactly what the older code wrote.
        $options->set(TrialUnlock::OPTION, true);
        $trial = $this->unlock($options, 'staging');

        self::assertTrue($trial->isRequested(), 'somebody did ask');
        self::assertFalse($trial->isActive());
        self::assertSame('host_unrecorded', $trial->refusal());

        // And one press fixes it, on the site where it is being pressed.
        self::assertTrue($trial->request(true));
        self::assertTrue($trial->isActive());
        self::assertSame('', $trial->refusal());
    }

    /**
     * A site whose own host cannot be read grants nothing. «I do not know
     * which site this is» is not a reason to record money on sample rules.
     */
    public function testASiteWithNoReadableHostGrantsNothing(): void
    {
        $options = new InMemoryOptionStore();
        $trial = new TrialUnlock(
            $options,
            new EnvironmentResolver(new FakeEnvironmentProbe(null, null, 'staging', null))
        );

        self::assertFalse($trial->request(true), 'nothing to bind the grant to');
        self::assertFalse($trial->isRequested(), 'and nothing was stored');
        self::assertFalse($trial->isActive());
    }

    /**
     * ⛔ A STORED GRANT WHOSE HOST IS EMPTY — and why this test exists.
     *
     * The empty-host check inside `isForThisSite()` survived a mutation that
     * made it trust such a grant, and that was not a defect: it is a DOUBLE
     * GUARD whose other half is `!is_array($stored)`, and the fixtures only
     * ever reached the first half. `request()` never writes an array with an
     * empty host, so nothing in the plugin produces this row.
     *
     * But a person can: an option row is editable, by hand or by a migration
     * somebody writes later. So the row is built here directly, which is the
     * only way to make the guard reachable — and the only way to measure it
     * (`alpha.91`).
     */
    public function testAStoredGrantWhoseHostIsEmptyIsRefused(): void
    {
        $options = new InMemoryOptionStore();
        $options->set(TrialUnlock::OPTION, ['granted' => true, 'host' => '']);
        $trial = $this->unlock($options, 'staging');

        self::assertTrue($trial->isRequested(), 'the row says granted');
        self::assertFalse($trial->isForThisSite(), 'but it names no site');
        self::assertFalse($trial->isActive());
        self::assertSame('host_unrecorded', $trial->refusal());
    }

    /** And a grant whose host is only whitespace is the same thing. */
    public function testAStoredGrantWhoseHostIsBlankIsRefused(): void
    {
        $options = new InMemoryOptionStore();
        $options->set(TrialUnlock::OPTION, ['granted' => true, 'host' => '   ']);

        self::assertFalse($this->unlock($options, 'staging')->isActive());
    }

    /** The host is compared case-insensitively: a host is not case-sensitive. */
    public function testTheHostComparisonIgnoresCase(): void
    {
        $options = new InMemoryOptionStore();
        $this->unlock($options, 'staging', 'Staging.Tecteb.COM')->request(true);

        self::assertTrue($this->unlock($options, 'staging', 'staging.tecteb.com')->isActive());
    }
}
