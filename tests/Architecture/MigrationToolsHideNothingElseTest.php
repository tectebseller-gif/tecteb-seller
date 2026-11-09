<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Admin\Presentation\AdminNavigation;
use Tecteb\Marketplace\Modules\Migration\Application\ConcludeMigration;
use Tecteb\Marketplace\Modules\Migration\Application\MigrationPlanChecklist;

/**
 * «Concluding» the migration HIDES a menu section. It must not be able to do
 * anything else — and that is asserted here rather than behaviourally, with
 * the reason written down.
 *
 * ⛔ WHY SOURCE AND NOT BEHAVIOUR. `ConcludeMigration` takes
 * `MigrationPlanSnapshot` and `MigrationCompleteness`, both `final`, and the
 * snapshot itself takes four more collaborators including the transfer tally
 * and the storefront reconciler. Running `conclude()` therefore means wiring
 * most of the Migration module; what that would measure is the module graph,
 * not this class. So the happy path is a MANUAL staging step
 * (`docs/STAGING-ACCEPTANCE-ALPHA101-FA.md`), stated as `Not Run` rather than
 * claimed, and what is pinned here is every way the feature could be worse
 * than a hidden menu row.
 *
 * The mission's §5 is the reason each of these is a separate assertion:
 * «از حذف فوری کد، دیتابیس، اسناد یا قابلیت بازگشت اجتناب کن» and «ابزارهای
 * پنهان‌شده نباید از طریق URL مستقیم توسط کاربران فاقد مجوز قابل اجرا
 * باشند».
 */
final class MigrationToolsHideNothingElseTest extends TestCase
{
    private const CONCLUDE = __DIR__ . '/../../src/Modules/Migration/Application/ConcludeMigration.php';
    private const VISIBILITY = __DIR__ . '/../../src/Modules/Migration/Application/MigrationToolsVisibility.php';
    private const MENU = __DIR__ . '/../../src/Modules/Admin/Presentation/MenuRegistrar.php';

    /**
     * ⛔ IT DELETES NOTHING. Not a table, not a row, not a file, not a page.
     *
     * «Hidden» and «removed» are different words and this is the difference:
     * the three migration screens stay registered, their capability keeps
     * being enforced by WordPress, and their data is untouched. A conclusion
     * that dropped a table would be unrepeatable, and the whole point of the
     * reveal button is that this one is.
     */
    public function testConcludingDeletesNothing(): void
    {
        $body = self::withoutComments(self::CONCLUDE) . self::withoutComments(self::VISIBILITY);

        foreach ([
            'DROP ', 'TRUNCATE', 'DELETE ',
            'delete_option', 'delete_post', 'wp_delete_post', 'delete_metadata',
            'delete_user', 'wp_delete_user', 'unlink', 'rmdir',
            'remove_menu_page', 'deactivate_plugins', 'uninstall',
        ] as $verb) {
            self::assertStringNotContainsString(
                $verb,
                $body,
                'concluding the migration must not be able to ' . trim($verb)
            );
        }
    }

    /**
     * The capability is asked FIRST, before anything is read or written.
     *
     * Measured by byte offset rather than by presence: a check that runs after
     * the work is not a check (`alpha.13`).
     */
    public function testTheCapabilityIsAskedBeforeAnythingElse(): void
    {
        foreach (['conclude', 'reopen'] as $method) {
            $body = self::methodBody(self::CONCLUDE, $method);
            self::assertNotSame('', $body, $method . ' was found');

            $guard = strpos($body, 'capabilities->can(');
            self::assertNotFalse($guard, $method . ' asks for a capability');

            foreach (['visibility->', 'completeness->', 'snapshot->', 'audit->', 'clock->'] as $work) {
                $at = strpos($body, $work);
                if ($at === false) {
                    continue;
                }
                self::assertGreaterThan(
                    $guard,
                    $at,
                    $method . '() must ask the capability before it touches ' . $work
                );
            }
        }
    }

    /**
     * ⛔ ANYTHING THAT IS NOT `DONE` COUNTS AS REMAINING — including
     * `UNKNOWN`.
     *
     * This is the `alpha.97` lesson applied to a readiness question: a step
     * that could not be measured must not be counted as finished. The
     * comparison is therefore `!== DONE` rather than `=== BLOCKED`, and the
     * difference is a step whose own read failed.
     */
    public function testAStepThatIsNotDoneBlocksTheConclusionEvenWhenItIsUnknown(): void
    {
        $body = self::methodBody(self::CONCLUDE, 'remainingFor');

        self::assertStringContainsString(
            '!== MigrationPlanChecklist::DONE',
            $body,
            'the test is «not done», not «blocked»'
        );
        self::assertStringNotContainsString(
            '=== MigrationPlanChecklist::BLOCKED',
            $body,
            'a blocked-only test would count an unmeasured step as finished'
        );
        // …and the default for a step with no status is UNKNOWN, which the
        // comparison above then treats as remaining.
        self::assertStringContainsString('MigrationPlanChecklist::UNKNOWN', $body);
        self::assertNotSame(
            MigrationPlanChecklist::DONE,
            MigrationPlanChecklist::UNKNOWN,
            'and the two constants really are different'
        );
    }

    /**
     * The way back is ASYMMETRIC, on purpose, and the asymmetry is pinned so
     * a later round does not "tidy" it into a matching pair.
     *
     * `conclude()` needs every shop finished; `reopen()` needs the capability
     * and nothing else. A manager who needs those screens back is by
     * definition in a situation the checklist did not predict, and making
     * them satisfy a condition first would be the gate with no way out
     * (`alpha.97`).
     */
    public function testReopeningAsksForNothingButTheCapability(): void
    {
        $body = self::methodBody(self::CONCLUDE, 'reopen');

        self::assertStringNotContainsString('completeness->', $body, 'reopening does not re-check the shops');
        self::assertStringNotContainsString('snapshot->', $body, 'nor the checklist');
        self::assertStringContainsString('capabilities->can(', $body);
        self::assertStringContainsString('forgetConclusion', $body);
    }

    /**
     * Four outcomes exist as NAMES, so a refusal is never a flat no.
     *
     * `alpha.85`'s rule about screens: every refusal a person can reach has to
     * be distinguishable, because the operational answer differs — «finish
     * these steps» and «nothing was imported» are not the same situation.
     */
    public function testEveryRefusalHasAName(): void
    {
        // Matched by CONSTANT NAME rather than by value: the messages file
        // writes `ConcludeMigration::FORBIDDEN` in its `match` arms, which is
        // the right way round — a renamed value must not silently lose its
        // sentence — and a test that searched for the literal string would
        // have been asserting a spelling nobody uses.
        $messages = self::withoutComments(
            __DIR__ . '/../../src/Modules/Migration/Presentation/MigrationToolMessages.php'
        );
        $codes = [
            'FORBIDDEN' => ConcludeMigration::FORBIDDEN,
            'STEPS_REMAIN' => ConcludeMigration::STEPS_REMAIN,
            'NOTHING_IMPORTED' => ConcludeMigration::NOTHING_IMPORTED,
            'NOT_STORED' => ConcludeMigration::NOT_STORED,
            'ALREADY' => ConcludeMigration::ALREADY,
            'NOT_CONCLUDED' => ConcludeMigration::NOT_CONCLUDED,
        ];
        foreach ($codes as $name => $value) {
            self::assertNotSame('', $value, $name . ' has a value');
            self::assertStringContainsString(
                'ConcludeMigration::' . $name . ' =>',
                $messages,
                $name . ' has a Persian sentence'
            );
        }
        // …and the six are six, so none of them share a value and quietly
        // share one sentence.
        self::assertCount(6, array_unique($codes), 'the six refusals are distinguishable');
    }

    /**
     * ⛔ THE MENU REMOVES ONLY THE MIGRATION GROUP'S SLUGS, AND FAILS OPEN.
     *
     * Two properties in one place because they are one risk. A registrar that
     * removed a slug outside that group would hide a permanent page with no
     * way back from the health screen; one that threw on a missing
     * `remove_submenu_page` would take the whole admin menu down with it.
     */
    public function testTheRegistrarHidesOnlyTheMigrationGroupAndFailsOpen(): void
    {
        $body = self::methodBody(self::MENU, 'hideConcludedMigrationTools');
        self::assertNotSame('', $body);

        // The list comes from the one place grouping is written down, rather
        // than from slugs typed here (`alpha.95`).
        self::assertStringContainsString('AdminNavigation::GROUPS', $body);
        self::assertStringContainsString('AdminNavigation::MIGRATION', $body);
        self::assertStringNotContainsString(
            'tmc-',
            $body,
            'no slug is typed into the registrar; the group names them'
        );

        // Fails open: the function may be absent and anything may throw.
        self::assertStringContainsString("function_exists('remove_submenu_page')", $body);
        self::assertStringContainsString('catch', $body, 'and nothing it does takes the menu down');

        // And the group it reads really is the three temporary screens.
        self::assertSame(
            ['tmc-import', 'tmc-handover', 'tmc-minmax-exit'],
            AdminNavigation::GROUPS[AdminNavigation::MIGRATION],
            'the temporary group is exactly these three'
        );
    }

    /** The file with every comment blanked — `alpha.22`. */
    private static function withoutComments(string $path): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= str_repeat(' ', strlen($token[1]));
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    /** One method's body, by BRACE MATCHING rather than to the next `function` (`alpha.77`). */
    private static function methodBody(string $path, string $method): string
    {
        $source = self::withoutComments($path);
        $at = strpos($source, 'function ' . $method . '(');
        if ($at === false) {
            return '';
        }
        $open = strpos($source, '{', $at);
        if ($open === false) {
            return '';
        }
        $depth = 0;
        for ($i = $open, $n = strlen($source); $i < $n; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                if (--$depth === 0) {
                    return substr($source, $open, $i - $open + 1);
                }
            }
        }
        return '';
    }
}
