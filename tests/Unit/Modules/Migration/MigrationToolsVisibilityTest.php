<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Migration;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Migration\Application\MigrationToolsVisibility;
use Tecteb\Marketplace\Tests\Support\InMemoryOptionStore;

/**
 * The one option that decides whether the temporary migration section is in
 * the admin menu — and which way it fails.
 *
 * ⛔ IT FAILS OPEN, TO VISIBLE, and that is the whole design. A store that
 * cannot read the option is a store where nobody can tell whether the
 * migration finished, and the honest answer there is to leave the tools where
 * they are: a manager who sees a section they no longer need loses a menu
 * row, and a manager whose option read failed mid-migration would lose the
 * only screens that can finish it.
 *
 * That is the opposite direction to every money question in this plugin
 * (`alpha.97`: a readiness gate that says «done» on a failed read is the one
 * way it must never be wrong) and the difference is what each answer costs.
 * Hiding a menu row writes nothing, deletes nothing and is undone by one
 * button; «the migration is complete» moves money.
 */
final class MigrationToolsVisibilityTest extends TestCase
{
    public function testWithNothingRecordedTheToolsAreVisible(): void
    {
        $visibility = new MigrationToolsVisibility(new InMemoryOptionStore());

        self::assertTrue($visibility->toolsAreVisible(), 'the default is the migration state');
        self::assertSame('', $visibility->concludedAt());
    }

    public function testOnceConcludedTheToolsAreHiddenAndTheMomentIsReadable(): void
    {
        $visibility = new MigrationToolsVisibility(new InMemoryOptionStore());

        self::assertTrue($visibility->recordConclusion('2026-11-09 10:00:00'));

        self::assertFalse($visibility->toolsAreVisible());
        self::assertSame('2026-11-09 10:00:00', $visibility->concludedAt());
    }

    public function testReopeningPutsThemBackAndSaysNothingWasConcluded(): void
    {
        $visibility = new MigrationToolsVisibility(new InMemoryOptionStore());
        $visibility->recordConclusion('2026-11-09 10:00:00');

        self::assertTrue($visibility->forgetConclusion());

        self::assertTrue($visibility->toolsAreVisible(), 'one button puts them back');
        self::assertSame('', $visibility->concludedAt());
    }

    /**
     * ⛔ A STORED VALUE THAT IS NOT A STRING IS «NOT CONCLUDED», not «hidden
     * at an unknown moment».
     *
     * The row can hold anything a hand-written `wp_options` edit or another
     * plugin put there. A truthy non-string would otherwise hide the tools
     * while `concludedAt()` had nothing to print, so the screen would say the
     * migration had finished and be unable to say when — and the «reopen»
     * button is on that same screen.
     */
    public function testAStoredValueThatIsNotADateLeavesTheToolsVisible(): void
    {
        foreach ([1, true, ['2026-11-09'], 0.5] as $junk) {
            $options = new InMemoryOptionStore();
            $options->set(MigrationToolsVisibility::OPTION, $junk);
            $visibility = new MigrationToolsVisibility($options);

            self::assertTrue(
                $visibility->toolsAreVisible(),
                'a value that is not a date is not a conclusion: ' . get_debug_type($junk)
            );
            self::assertSame('', $visibility->concludedAt());
        }
    }

    /**
     * And a blank string is «not concluded» too — which is what
     * `forgetConclusion()` leaves behind on a store that keeps the row.
     */
    public function testABlankStringIsNotAConclusion(): void
    {
        $options = new InMemoryOptionStore();
        $options->set(MigrationToolsVisibility::OPTION, '   ');

        self::assertTrue((new MigrationToolsVisibility($options))->toolsAreVisible());
    }
}
