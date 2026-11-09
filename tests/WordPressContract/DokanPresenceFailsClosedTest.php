<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Modules\Order\Infrastructure\WordPress\WpDokanPresence;
use TmcWpStubs\State;

/**
 * ⛔ THE FIFTH SIGNAL, which had no test at all until this round — and from
 * `alpha.101` it guards the only thing left guarding the money.
 *
 * Until this release a build could not record a sale anyway while
 * `OrderOperationsGate::OPEN_DECISIONS` carried DEC-02 and DEC-04, so a probe
 * that failed open cost nothing: the second gate caught it. The owner has
 * answered both, that list is empty, and this probe is now the ONLY thing
 * between a transferred product — attributed by `post_author` to a Dokan
 * vendor and by `link_ownership` to the marketplace — and two systems
 * recording one sale.
 *
 * Which makes the direction of its error the whole question. Four of its
 * signals are positive detections; the fifth is «I could not find out», and
 * a probe that answered «running: no» to that would be failing open on the
 * one question that must never fail open (`alpha.97`).
 *
 * ⛔ AND IT IS A CONTRACT TEST, not a unit one, for a measured reason:
 * `pluginListWasReadable()` returns `true` when `get_option` does not exist —
 * deliberately, so that pure tests of the other four signals are not all
 * refusals — so «the option could not be read» is INEXPRESSIBLE without
 * WordPress present. A unit test would have asserted the opposite of what
 * production does.
 *
 * ⛔ And Dokan itself stays `Not Run`: it is unrunnable here, because its
 * first line `require`s `abzarwp/` and all 37 files of that directory are
 * SourceGuardian-encrypted with no loader available (`alpha.53`). What is
 * measured is this probe's reading of a WordPress option, which is a
 * different and honest thing.
 */
final class DokanPresenceFailsClosedTest extends ContractTestCase
{
    public function testWithNoDokanAnywhereTheProbeSaysNotRunning(): void
    {
        State::$options['active_plugins'] = ['woocommerce/woocommerce.php'];

        $probe = new WpDokanPresence();

        self::assertFalse($probe->isRunning());
        self::assertSame('', $probe->signal(), 'and it names no signal');
    }

    /**
     * An EMPTY list is a real answer and must not be read as unknown.
     *
     * A site with no active plugins exists — a fresh install, a maintenance
     * window — and refusing there would make the marketplace unusable for a
     * reason that has nothing to do with Dokan.
     */
    public function testAnEmptyPluginListIsARealAnswerAndNotAnUnknownOne(): void
    {
        State::$options['active_plugins'] = [];

        $probe = new WpDokanPresence();

        self::assertFalse($probe->isRunning(), 'no plugins active is not «I could not read»');
        self::assertSame('', $probe->signal());
    }

    /**
     * ⛔ A LIST THAT COULD NOT BE OBTAINED IS «RUNNING», and the signal says
     * which.
     *
     * `get_option()` answers the default on a missing row and `false` on some
     * failure paths, so a non-array answer to a call that passed `[]` as the
     * default is the only thing that can mean «the read did not happen».
     *
     * @dataProvider unreadableAnswers
     */
    public function testAListThatCouldNotBeReadIsTreatedAsDokanRunning(mixed $stored): void
    {
        State::$options['active_plugins'] = $stored;

        $probe = new WpDokanPresence();

        self::assertTrue(
            $probe->isRunning(),
            '«I could not find out» must not be «no Dokan»: ' . get_debug_type($stored)
        );
        self::assertSame('active_plugin_list_unreadable', $probe->signal());
    }

    /** @return array<string, array{0:mixed}> */
    public static function unreadableAnswers(): array
    {
        return [
            'false — the failure path' => [false],
            'a string' => ['woocommerce/woocommerce.php'],
            'an integer' => [0],
            'null' => [null],
        ];
    }

    /**
     * ⛔ WHAT THIS FILE DELIBERATELY DOES NOT DO: build the order gate.
     *
     * Two properties are needed and they live in different places, so they are
     * asserted in different places rather than half-asserted in both:
     *
     *  - **this probe answers correctly**, including on a read that did not
     *    happen — only expressible with WordPress present, which is here;
     *  - **the gate asks it first, and the trial cannot waive the answer** —
     *    `OrderGateRefusesWhileDokanRunsTest` (behavioural, with a double) and
     *    `OrderGateAsksAboutDokanTest` (that the container passes a REAL probe
     *    rather than null, because a guard nothing wires is not a guard).
     *
     * Rebuilding the gate here would have meant a second ledger double for a
     * ten-method interface, to re-measure something already measured — and a
     * test that restates another test's property tends to drift from it.
     */
    public function testTheSignalNameIsTheOneTheGatePutsInItsRefusal(): void
    {
        State::$options['active_plugins'] = false;

        // The exact string the gate interpolates into its detail. Pinned here
        // because the two halves are in different suites: if this name ever
        // changes, the manager's screen would say «dokan is running ()» and
        // nothing would fail.
        self::assertSame('active_plugin_list_unreadable', (new WpDokanPresence())->signal());
    }
}
