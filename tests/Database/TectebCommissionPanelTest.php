<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Finance\Application\TectebCommissionSourceInterface;
use Tecteb\Marketplace\Modules\Finance\Domain\TectebCommission;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\WooCommerce\TectebCommissionPanel;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\WooCommerce\WpTectebCommissionSource;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use TmcWpStubs\State;

/**
 * WHAT THIS PROVES: «کمیسیون تک‌طب» can be set, is refused for the right
 * reasons, and cannot be moved by anybody without the financial permission.
 *
 * Against the stubs and a real MariaDB, which is stated rather than implied:
 * this is the panel's CONTRACT with WordPress — which hooks it registers,
 * which capability it asks, what it writes — and not evidence that it renders
 * correctly in a real editor. That is `Not Run` until the browser run.
 *
 * It needs the database because booting the plugin registers the Product
 * module's own product-editor panel beside this one, and that one reads the
 * marketplace row to decide whether the product is ours. A suite without a
 * database would fail here for a reason that has nothing to do with
 * commission.
 *
 * The POST is built from the panel's OWN rendered HTML wherever the value
 * matters, because `alpha.46` learned that a test which invents the form
 * fields tests a form nobody will submit.
 */
final class TectebCommissionPanelTest extends DatabaseTestCase
{
    private const PRODUCT = 4201;
    private const VARIATION = 4202;

    protected function setUp(): void
    {
        parent::setUp();
        State::$isAdmin = true;
        $GLOBALS['post'] = (object) ['ID' => self::PRODUCT];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['post'], $_POST);
        parent::tearDown();
    }

    /** The panel is a tab on the product editor, and only for a manager who may decide money. */
    public function testTheTabAppearsOnlyForSomebodyWithTheFinancialPermission(): void
    {
        $this->bootPlugin(true, '11.0.1');
        TectebCommissionPanel::register(Bootstrap::container());

        State::loginAs(9, ['read', 'edit_post']);
        self::assertSame(
            [],
            array_keys(apply_filters('woocommerce_product_data_tabs', [])),
            'a user without the financial permission must not even see the tab'
        );

        State::loginAs(9, ['read', 'edit_post', Capabilities::REVIEW_WITHDRAWALS]);
        self::assertSame(
            ['tmc_commission'],
            array_keys(apply_filters('woocommerce_product_data_tabs', [])),
            'the manager must'
        );
    }

    /** The two controls the owner asked for, and nothing else. */
    public function testThePanelOffersATypeAndAValueAndSaysWhatNoCommissionMeans(): void
    {
        $html = $this->render();

        self::assertStringContainsString('نوع کمیسیون تک‌طب', $html);
        foreach (['بدون کمیسیون', 'درصدی', 'مبلغ ثابت به ازای هر عدد'] as $option) {
            self::assertStringContainsString('>' . $option . '</option>', $html);
        }
        self::assertStringContainsString('name="tmc_commission_value"', $html);
        // The sentence that stops the field being read as a blocker.
        self::assertStringContainsString('مانع تأیید، انتشار یا فروش محصول نیست', $html);
        // …and the one that says a later change does not rewrite history.
        self::assertStringContainsString('سفارش‌های قبلی را عوض نمی‌کند', $html);
        // The value row is in the DOM with the script off, so a browser that
        // runs nothing can still set a percentage.
        self::assertStringContainsString('id="tmc-commission-value-row"', $html);
        self::assertStringNotContainsString('display:none', $html);
    }

    /** A percentage, saved and read back through the source the capture uses. */
    public function testAManagerSavesAPercentageAndTheCaptureReadsItBack(): void
    {
        $this->render();
        $this->post(['tmc_commission_type' => 'percent', 'tmc_commission_value' => '12.5']);

        ['rule' => $rule, 'source' => $source] = (new WpTectebCommissionSource())->forItem(self::PRODUCT);
        self::assertSame(TectebCommission::PERCENT, $rule->type);
        self::assertSame(1250, $rule->percentBasisPoints);
        self::assertSame(TectebCommissionSourceInterface::FROM_PRODUCT, $source);

        // …and the box comes back filled with the manager's own number.
        self::assertStringContainsString('value="12.50"', $this->render());
    }

    /**
     * An impossible value writes NOTHING, and the previous rule stands.
     *
     * Both halves. A panel that refused and also cleared the row would be the
     * `alpha.45` defect in a new place — the ceiling removed by a request that
     * should have changed nothing.
     */
    public function testAnImpossibleValueIsRefusedAndTheRuleAlreadyThereSurvives(): void
    {
        $this->render();
        $this->post(['tmc_commission_type' => 'fixed', 'tmc_commission_value' => '20000']);
        $this->post(['tmc_commission_type' => 'percent', 'tmc_commission_value' => '140']);

        $rule = (new WpTectebCommissionSource())->forItem(self::PRODUCT)['rule'];
        self::assertSame(TectebCommission::FIXED, $rule->type, 'the earlier rule must still be in force');
        self::assertSame('20000', $rule->fixedPerUnit);
    }

    /**
     * A save that did not come from this panel leaves the rule alone.
     *
     * The nonce is checked FIRST, so a bulk edit, a REST write or another
     * plugin saving the product cannot reset a commission to the defaults of
     * a form that was never rendered — `alpha.46`, where a nonce printed on
     * every render meant a title-only save took three rules for ever.
     */
    public function testASaveWithoutThePanelsNonceChangesNothing(): void
    {
        $this->render();
        $this->post(['tmc_commission_type' => 'percent', 'tmc_commission_value' => '10']);

        $_POST = ['tmc_commission_type' => 'none', 'tmc_commission_value' => ''];
        TectebCommissionPanel::save(self::PRODUCT, Bootstrap::container());

        self::assertSame(
            1000,
            (new WpTectebCommissionSource())->forItem(self::PRODUCT)['rule']->percentBasisPoints,
            'a nonce-less save must not touch the commission'
        );
    }

    /**
     * §5-هـ: without the financial permission nothing is written, even with a
     * valid nonce.
     *
     * The nonce proves the form, not the person. A vendor who was a manager
     * yesterday has a page in a tab that still carries one.
     */
    public function testAValidFormFromSomebodyWithoutThePermissionWritesNothing(): void
    {
        $this->render();
        $this->post(['tmc_commission_type' => 'percent', 'tmc_commission_value' => '10']);

        // The same form, now submitted by somebody who may edit the post and
        // may not decide money.
        State::loginAs(11, ['read', 'edit_post']);
        $_POST = [
            TectebCommissionPanel::NONCE => wp_create_nonce(TectebCommissionPanel::NONCE),
            'tmc_commission_type' => 'fixed',
            'tmc_commission_value' => '999999',
        ];
        TectebCommissionPanel::save(self::PRODUCT, Bootstrap::container());

        $rule = (new WpTectebCommissionSource())->forItem(self::PRODUCT)['rule'];
        self::assertSame(TectebCommission::PERCENT, $rule->type);
        self::assertSame(1000, $rule->percentBasisPoints);
        // And the attempt is in the trail, because it is the row most worth
        // having: «تغییرات مالی ممیزی شود».
        $refusals = array_filter(
            $this->audit->records,
            static fn (\Tecteb\Marketplace\Contracts\AuditRecord $r): bool
                => $r->eventType === AuditEventCatalog::COMMISSION_RULE_SET
                && ($r->payload['outcome'] ?? '') === 'refused'
        );
        self::assertNotSame([], $refusals, 'a refused financial change must be audited');
    }

    /**
     * §5-ی: a variation with no rule of its own is charged by its parent's,
     * and the answer says which.
     */
    public function testAVariationFallsBackToItsParentAndTheAnswerSaysSo(): void
    {
        $this->render();
        $this->post(['tmc_commission_type' => 'percent', 'tmc_commission_value' => '10']);

        $source = new WpTectebCommissionSource();
        $fromParent = $source->forItem(self::PRODUCT, self::VARIATION);
        self::assertSame(1000, $fromParent['rule']->percentBasisPoints);
        self::assertSame(TectebCommissionSourceInterface::FROM_PRODUCT, $fromParent['source']);

        // A variation with its own rule — including an explicit «none» — is
        // answered by itself and must NOT fall through to the parent.
        update_post_meta(self::VARIATION, WpTectebCommissionSource::TYPE_META, TectebCommission::NONE);
        $own = $source->forItem(self::PRODUCT, self::VARIATION);
        self::assertTrue($own['rule']->isNone());
        self::assertSame(TectebCommissionSourceInterface::FROM_VARIATION, $own['source']);
    }

    /** A product nobody has decided about: no commission, and named as such. */
    public function testAProductWithNoRuleReadsAsNoCommissionRatherThanAnInheritedRate(): void
    {
        $this->bootPlugin(true, '11.0.1');
        ['rule' => $rule, 'source' => $source] = (new WpTectebCommissionSource())->forItem(self::PRODUCT);
        self::assertTrue($rule->isNone());
        self::assertSame(TectebCommissionSourceInterface::FROM_NONE, $source);
    }

    /** Renders the panel as the editor does, and returns its HTML. */
    private function render(): string
    {
        $this->bootPlugin(true, '11.0.1');
        State::loginAs(9, ['read', 'edit_post', Capabilities::REVIEW_WITHDRAWALS]);
        TectebCommissionPanel::register(Bootstrap::container());
        ob_start();
        do_action('woocommerce_product_data_panels');
        return (string) ob_get_clean();
    }

    /**
     * Submits the panel's form, carrying the nonce the panel itself printed.
     *
     * @param array<string,string> $fields
     */
    private function post(array $fields): void
    {
        $_POST = $fields + [
            TectebCommissionPanel::NONCE => wp_create_nonce(TectebCommissionPanel::NONCE),
        ];
        do_action('woocommerce_process_product_meta', self::PRODUCT);
    }
}
