<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Migration\Application\MigrationPlanChecklist as Plan;

/**
 * Opening the wizard starts nothing — measured from the bytes.
 *
 * The owner's §3 asks for an automatic migration and then draws the line:
 * «خودکارسازی به معنی اجرای بدون اجازه نیست.» The line is held here by the
 * wizard having no writer at all, so a later refactor that reached for one
 * fails before it could reach a product.
 */
final class MigrationWizardIsReadOnlyTest extends TestCase
{
    private const CHECKLIST = 'src/Modules/Migration/Application/MigrationPlanChecklist.php';
    private const SNAPSHOT = 'src/Modules/Migration/Application/MigrationPlanSnapshot.php';
    private const TEXT = 'src/Modules/Migration/Presentation/MigrationWizardText.php';

    /**
     * Every verb that would change something. The imports, the ownership, the
     * permission, the catalogue and the plugin's own tables are all here,
     * because the wizard touches none of them.
     */
    private const WRITES = [
        '->import(',
        '->importBatch(',
        '->enqueue(',
        '->take(',
        '->move(',
        '->giveBack(',
        '->apply(',
        '->rollback(',
        'setLinkOwnership',
        'updateStatus',
        'updateDetails',
        'updatePrice',
        'updateInventory',
        'reconcileStatusToStorefront',
        'upsertProfile',
        'grant(',
        'withdraw(',
        '->set(',
        '->delete(',
        'update_post_meta',
        'update_option',
        'wp_update_post',
        'wp_insert_post',
        '->save(',
        'INSERT',
        'UPDATE ',
        'DELETE',
    ];

    private function code(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        self::assertFileExists($path);
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    public function testNeitherHalfOfTheWizardCanWriteAnything(): void
    {
        foreach ([self::CHECKLIST, self::SNAPSHOT, self::TEXT] as $file) {
            $code = $this->code($file);
            foreach (self::WRITES as $verb) {
                self::assertStringNotContainsString(
                    $verb,
                    $code,
                    $file . ' must not ' . $verb
                );
            }
        }
    }

    /**
     * The checklist is pure: no WordPress at all, including the translator.
     * Application has no right to `__()` (`alpha.9`), which is why the
     * Persian lives in `MigrationWizardText`.
     */
    /**
     * ⛔ A short scan's figures must arrive as `null`, and that is asserted
     * from the source because no fixture in this repository can make the scan
     * stop short: `ShopTransferTally` walks forty pages of fifty, so a test
     * would need two thousand products to reach the ceiling. This mutation
     * survived the falsification loop for exactly that reason — it changes
     * nothing a three-product fixture can see — and the guard it removes is
     * the one that stops a partial count being presented as a whole
     * (`alpha.75`).
     */
    public function testAShortScanReportsNoFiguresAtAll(): void
    {
        $code = $this->code(self::SNAPSHOT);
        $tight = (string) preg_replace('/\s+/', '', $code);
        // BOTH halves: the walk reached the end AND the risks were checked.
        self::assertStringContainsString(
            "\$finished=\$tally['finished']===true&&\$tally['checked']===true;",
            $tight,
            'finishing means the walk ended and the risks were asked'
        );
        // And every figure the checklist judges on is withheld when it did not.
        foreach (['ready', 'drift_fixable', 'risk_real'] as $figure) {
            self::assertMatchesRegularExpression(
                "~'" . $figure . "'=>\\\$finished\\?~",
                $tight,
                $figure . ' must be null when the scan did not finish'
            );
        }
        self::assertStringContainsString(
            ":null,", $tight,
            'and the withheld answer is null rather than nought'
        );
    }

    public function testTheChecklistNeverCallsWordPress(): void
    {
        $code = $this->code(self::CHECKLIST);
        foreach (['__(', 'esc_html', 'admin_url', 'get_option', '$wpdb', 'current_user_can'] as $verb) {
            self::assertStringNotContainsString($verb, $code, 'the checklist must not call ' . $verb);
        }
    }

    /** And the snapshot does not choose Persian either. */
    public function testTheSnapshotNeverChoosesWording(): void
    {
        self::assertStringNotContainsString('__(', $this->code(self::SNAPSHOT));
    }

    /**
     * ⛔ Every step has a name, a status word and a target — in BOTH
     * directions, so a step added to the checklist without its Persian fails
     * here rather than printing its key to a manager.
     */
    public function testEveryStepHasItsWordingAndEveryWordingHasItsStep(): void
    {
        // From the SOURCE, because this suite defines no WordPress symbols
        // and `MigrationWizardText` calls `__()` — which is the whole reason
        // the wording lives in Presentation. The calls themselves are
        // exercised in the contract suite, where the stubs exist.
        $code = $this->code(self::TEXT);
        foreach (['STEP_ENVIRONMENT', 'STEP_PLUGINS', 'STEP_BACKUP', 'STEP_INVENTORY',
            'STEP_DRY_RUN', 'STEP_DIFFERENCES', 'STEP_SYNC', 'STEP_PLAN', 'STEP_CONSENT',
            'STEP_TRANSFER', 'STEP_VALIDATE', 'STEP_EXIT_READY'] as $constant) {
            self::assertStringContainsString('Plan::' . $constant, $code, $constant . ' has no wording');
        }
        self::assertSame(12, count(Plan::ORDER), 'twelve steps, as the owner listed them');
    }

    public function testEveryStatusHasItsOwnWord(): void
    {
        $code = $this->code(self::TEXT);
        foreach (['DONE', 'TODO', 'BLOCKED', 'UNKNOWN'] as $status) {
            self::assertStringContainsString(
                'Plan::' . $status . '=>__(',
                (string) preg_replace('/\s+/', '', $code),
                $status . ' has no word of its own'
            );
        }
    }

    /**
     * ⛔ A target is an admin path built from a page's own `SLUG`, never a
     * typed slug — a dead «رفتن» link on the one screen a manager follows
     * during a migration is worse than no link at all.
     */
    public function testEveryTargetIsBuiltFromASlugConstant(): void
    {
        $code = $this->code(self::TEXT);
        self::assertStringNotContainsString('page=tmc-', $code, 'no slug may be typed into a target');
        // Every path is either a core screen or `…?page=' . Something::SLUG`.
        self::assertSame(
            substr_count($code, "'admin.php?page='"),
            substr_count($code, "'admin.php?page=' . "),
            'every admin target must concatenate a SLUG constant'
        );
    }

    /**
     * The one step that must never have a target: readiness is reported on
     * the wizard's own page, and the action it unlocks is the owner's on the
     * server rather than a button here.
     */
    public function testTheExitStepPointsAtNoButton(): void
    {
        $tight = (string) preg_replace('/\s+/', '', $this->code(self::TEXT));
        self::assertStringContainsString(
            "Plan::STEP_VALIDATE,Plan::STEP_EXIT_READY=>''",
            $tight,
            'readiness is reported here and acted on by the owner, not by a button'
        );
    }
}
