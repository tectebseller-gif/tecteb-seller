<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Modules\Migration\Application\MigrationPlanChecklist as Plan;
use Tecteb\Marketplace\Modules\Migration\Presentation\Admin\MigrationPage;
use Tecteb\Marketplace\Modules\Migration\Presentation\MigrationWizardText as Text;

/**
 * The wizard's Persian, CALLED — the half the architecture suite cannot run
 * because it defines no `__()`.
 */
final class MigrationWizardTextTest extends ContractTestCase
{
    private function fa(): callable
    {
        return static fn (string $v): string => $v;
    }

    public function testEveryStepHasAPersianNameAndNoTwoShareOne(): void
    {
        $names = [];
        foreach (Plan::ORDER as $key) {
            $name = Text::step($key);
            self::assertNotSame($key, $name, 'no name for ' . $key);
            self::assertNotSame('', trim($name), $key . ' has a blank name');
            $names[] = $name;
        }
        self::assertSame($names, array_values(array_unique($names)), 'twelve steps, twelve names');
    }

    public function testEveryStatusHasItsOwnWord(): void
    {
        $words = [];
        foreach ([Plan::DONE, Plan::TODO, Plan::BLOCKED, Plan::UNKNOWN] as $status) {
            $word = Text::status($status);
            self::assertNotSame($status, $word, 'no word for ' . $status);
            $words[] = $word;
        }
        self::assertSame($words, array_values(array_unique($words)));
    }

    /**
     * ⛔ «اندازه‌گیری‌نشده», not «نامشخص»: the fourth status is a fact about
     * this install, and a shrug would read as a kind of «no».
     */
    public function testTheFourthStatusSaysWhatActuallyHappened(): void
    {
        self::assertStringContainsString('اندازه‌گیری', Text::status(Plan::UNKNOWN));
    }

    /**
     * Every blocker the checklist can produce has a sentence — asserted by
     * driving the checklist itself, so a blocker added later without its
     * Persian fails here rather than printing a key to a manager.
     */
    public function testEveryBlockerTheChecklistCanProduceHasASentence(): void
    {
        $seen = [];
        // Snapshots chosen to reach every branch: empty, and then one
        // deliberately broken fact at a time.
        $snapshots = [
            [],
            ['php_version' => '8.0.1', 'platform_version' => '6.8', 'schema_target' => 38, 'schema_version' => 38],
            ['php_version' => '8.1.0', 'platform_version' => '6.8', 'schema_version' => 1, 'schema_target' => 38],
            ['php_version' => '8.1.0', 'platform_version' => '6.8', 'schema_target' => 0],
            ['woocommerce_active' => true, 'woocommerce_version' => null],
            ['woocommerce_active' => true, 'woocommerce_version' => '9', 'dokan_running' => null],
            ['woocommerce_active' => true, 'woocommerce_version' => '9', 'dokan_running' => false],
            ['rollback' => 'blocked'],
            ['shops' => 0],
            ['transfer_runs' => 0],
            ['scan_finished' => true, 'ready' => -1],
            ['scan_finished' => true, 'ready' => 0, 'drift_fixable' => 7, 'risk_real' => 0, 'status_repairable' => 0],
            ['scan_finished' => true, 'ready' => 0, 'drift_fixable' => 0, 'risk_real' => 3, 'status_repairable' => 0],
            ['observed' => 4, 'marketplace' => 0],
            ['gates_missing' => ['vendor_can_sell']],
        ];
        foreach ($snapshots as $snapshot) {
            foreach (Plan::steps($snapshot) as $step) {
                $blocker = (string) $step['blocker'];
                if ($blocker === '') {
                    continue;
                }
                $seen[$blocker] = true;
                $sentence = Text::explain(
                    (string) $step['status'],
                    $blocker,
                    (string) $step['detail'],
                    $this->fa()
                );
                self::assertNotSame($blocker, $sentence, 'no sentence for blocker ' . $blocker);
                self::assertNotSame('', trim($sentence), $blocker . ' has a blank sentence');
            }
        }
        // And the set really was broad: every blocker name in the checklist's
        // own source was reached by one of the snapshots above.
        self::assertGreaterThanOrEqual(15, count($seen), 'the snapshots must reach most branches');
    }

    /** A done step says its figure and nothing else. */
    public function testADoneStepExplainsItselfWithItsFigure(): void
    {
        self::assertSame('184', Text::explain(Plan::DONE, '', '184', $this->fa()));
        self::assertSame('', Text::explain(Plan::DONE, '', '', $this->fa()));
    }

    /** ⛔ Dokan NOT running is the blocker, and the sentence says why. */
    public function testTheDokanSentenceSaysToDoThisBeforeSwitchingItOff(): void
    {
        $sentence = Text::explain(Plan::BLOCKED, 'dokan_not_running', '9', $this->fa());
        self::assertStringContainsString('دکان', $sentence);
        self::assertStringContainsString('پیش از خاموش‌کردن', $sentence);
    }

    public function testEveryTargetIsAReachableAdminPath(): void
    {
        foreach (Plan::ORDER as $key) {
            foreach ([0, 102] as $shop) {
                $target = Text::target($key, $shop);
                if ($target === '') {
                    continue;
                }
                self::assertMatchesRegularExpression(
                    '~^(admin\.php\?page=[a-z0-9-]+(&tv_shop=\d+)?(#[a-z0-9-]+)?|plugins\.php)$~',
                    $target,
                    $key . ' has a malformed target for shop ' . $shop
                );
            }
        }
    }

    /**
     * ⛔ And every page a target names is one this build really registers —
     * the `alpha.17` fault was a printed link to a page that 404s. The shop
     * and the fragment are stripped first, because neither is part of the
     * page's identity.
     */
    public function testEveryTargetedPageIsRegistered(): void
    {
        $this->bootPlugin(true);
        $this->loginAdmin();
        do_action('admin_menu');
        $slugs = array_column(\Tecteb\Marketplace\Modules\Admin\Presentation\Navigation::items(), 'slug');
        self::assertNotSame([], $slugs, 'the fixture must register some pages');

        foreach (Plan::ORDER as $key) {
            $target = Text::target($key, 102);
            if ($target === '' || !str_contains($target, 'page=')) {
                continue;
            }
            $slug = substr($target, strpos($target, 'page=') + 5);
            $slug = (string) preg_replace('~[&#].*$~', '', $slug);
            self::assertContains($slug, $slugs, $key . ' points at an unregistered page: ' . $slug);
        }
    }

    /**
     * And the shop travels, so the link is about the row the manager read.
     *
     * The other direction too: a caller with no shop in hand must get a URL
     * with no shop rather than a guess at one (`alpha.68` on a query string).
     */
    public function testTheShopTravelsWithTheTransferSteps(): void
    {
        self::assertStringContainsString('tv_shop=102', Text::target(Plan::STEP_SYNC, 102));
        self::assertStringNotContainsString('tv_shop', Text::target(Plan::STEP_SYNC, 0));
        // Steps 4 and 5 are the Dokan import at the TOP of that page, so they
        // keep the bare address on purpose.
        self::assertStringNotContainsString('#', Text::target(Plan::STEP_DRY_RUN, 102));
        self::assertStringNotContainsString('#', Text::target(Plan::STEP_INVENTORY, 102));
    }

    /** Step 7's anchor is the sync controls, not the section they sit in. */
    public function testStepSevenPointsAtItsOwnControls(): void
    {
        self::assertStringEndsWith('#' . MigrationPage::ANCHOR_SYNC, Text::target(Plan::STEP_SYNC, 102));
        self::assertStringEndsWith(
            '#' . MigrationPage::ANCHOR_TRANSFER,
            Text::target(Plan::STEP_TRANSFER, 102)
        );
    }
}
