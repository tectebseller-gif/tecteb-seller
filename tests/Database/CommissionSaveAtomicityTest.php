<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\WooCommerce\CommissionRuleWriter;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\WooCommerce\TectebCommissionPanel;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\WooCommerce\WpTectebCommissionSource;
use TmcWpStubs\State;

/**
 * WHAT THIS PROVES: the commission's type and its amount are written as ONE
 * thing, and the sentence the manager reads afterwards is one the code
 * measured.
 *
 * The review's §1, reproduced on the delivered `alpha.60` bytes before a line
 * was changed (`docs/evidence/alpha61-reproduction/`): previous rule
 * `percent/12.50`, a request for `fixed/20000`, the VALUE write refused ⇒
 *
 *     db_type=fixed  db_value=12.50   rule=fixed/12.50
 *     notice=«… مقدار قبلی سر جایش است»
 *
 * …and in the other direction `percent/20000`, which reads as NO commission
 * at all. Both times the message said the previous value was intact.
 *
 * Against real MariaDB and the real `postmeta` table, because the guarantee is
 * the database's: the two rows are locked, written inside one transaction and
 * verified by reading the ROWS. The failure is injected through WordPress's
 * own short-circuit filter (`alpha.47`'s method) — an injected failure of a
 * collaborator, not a spontaneous one, and it is reported as such.
 */
final class CommissionSaveAtomicityTest extends DatabaseTestCase
{
    private const PRODUCT = 4301;
    private const MANAGER = 9;

    protected function setUp(): void
    {
        parent::setUp();
        State::$isAdmin = true;
        $GLOBALS['post'] = (object) ['ID' => self::PRODUCT];
        $this->bootPlugin(true, '11.0.1');
        TectebCommissionPanel::register(Bootstrap::container());
        State::loginAs(self::MANAGER, ['read', 'edit_post', Capabilities::REVIEW_WITHDRAWALS]);
        $this->save('percent', '12.50');
        self::assertSame(['percent', '12.50'], $this->rows(), 'the baseline this round starts from');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['post'], $_POST);
        parent::tearDown();
    }

    /** WHAT THIS PROVES: a failed AMOUNT write leaves no half-rule behind. */
    public function testAFailedAmountWriteLeavesThePreviousPairInPlace(): void
    {
        $this->refuse(WpTectebCommissionSource::VALUE_META);

        $this->save('fixed', '20000');

        self::assertSame(['percent', '12.50'], $this->rows(), 'the rows are the previous pair');
        $rule = (new WpTectebCommissionSource())->forItem(self::PRODUCT)['rule'];
        self::assertSame('percent', $rule->type, 'and so is the rule everything else reads');
        self::assertSame('12.50', $rule->displayValue());
        self::assertSame(CommissionRuleWriter::RESTORED, $this->notice()['outcome']);
    }

    /**
     * WHAT THIS PROVES: a failed TYPE write does not silently delete the
     * commission.
     *
     * The worse half of the same defect: `percent` beside `20000` is read by
     * `TectebCommission` as no commission at all, so on `alpha.60` a 12.5%
     * commission disappeared under a message saying it was still there.
     */
    public function testAFailedTypeWriteDoesNotTurnTheCommissionIntoNothing(): void
    {
        $this->refuse(WpTectebCommissionSource::TYPE_META);

        $this->save('fixed', '20000');

        self::assertSame(['percent', '12.50'], $this->rows());
        self::assertSame(
            'percent',
            (new WpTectebCommissionSource())->forItem(self::PRODUCT)['rule']->type,
            'the commission is not quietly gone'
        );
        self::assertSame(CommissionRuleWriter::RESTORED, $this->notice()['outcome']);
    }

    /**
     * WHAT THIS PROVES: «قبلی حفظ شد» is only said after it has been read
     * back.
     *
     * The message is the thing §1 asks for most directly — «جملهٔ «قبلی حفظ
     * شد» بدون اثبات چاپ نشود» — so the assertion is on the rendered Persian,
     * and on the fact that it names the pair that is really there.
     */
    public function testTheNoticeNamesThePairItVerified(): void
    {
        $this->refuse(WpTectebCommissionSource::VALUE_META);
        $this->save('fixed', '20000');

        $text = $this->renderedNotice();
        self::assertStringContainsString('ذخیره نشد', $text);
        self::assertStringContainsString('12.50', $text, 'it says WHICH rule is in force');
        self::assertStringNotContainsString('20000', $text, 'and not the one that failed');
    }

    /**
     * WHAT THIS PROVES: a rollback is not reported as a restoration until the
     * rows say so.
     *
     * With the ROLLBACK itself refused, the half-write is still on disk — so
     * the honest answer is `UNRESTORED` with what is actually stored, never
     * «قبلی سر جایش است». This is the one case where the previous build's
     * sentence would have been not merely wrong but reassuring.
     */
    public function testAFailedRollbackIsReportedAsWhatItIs(): void
    {
        $this->refuse(WpTectebCommissionSource::VALUE_META);
        // The gateway's ROLLBACK made to fail, on the same connection the
        // writer uses: `WpDatabase` answers `false` and the writer must not
        // turn that into a claim about the rows.
        $failing = new \Tecteb\Marketplace\Tests\Support\FailingDatabase(
            new \Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase($this->wpdb)
        );
        $failing->failRollback();
        $written = (new CommissionRuleWriter($failing))->write(
            self::PRODUCT,
            \Tecteb\Marketplace\Modules\Finance\Domain\TectebCommission::fromInput('fixed', '20000')['rule']
        );

        self::assertSame(CommissionRuleWriter::UNRESTORED, $written['outcome']);
        self::assertFalse($written['rolled_back'], 'the rollback is reported as having failed');
        self::assertSame('fixed', $written['stored_type'], 'and what is really stored is named');
        self::assertSame('12.50', $written['stored_value']);
        self::assertSame('percent', $written['previous_type']);
    }

    /**
     * WHAT THIS PROVES: a key with two rows is refused rather than compared.
     *
     * `wp_postmeta` has no unique index on `(post_id, meta_key)` — measured on
     * WordPress 7.1 in `alpha.55`, where this repository's own comment said
     * otherwise — and `update_post_meta()` overwrites every row of a key, so a
     * duplicated pair cannot be restored to anything in particular.
     */
    public function testADuplicatedRowIsNamedAndNothingIsWritten(): void
    {
        $this->wpdb->pdo()->exec(
            'INSERT INTO `' . $this->wpdb->postmeta . '` (post_id, meta_key, meta_value) VALUES ('
            . self::PRODUCT . ", '" . WpTectebCommissionSource::VALUE_META . "', '99.99')"
        );

        $this->save('fixed', '20000');

        self::assertSame(CommissionRuleWriter::ROWS_DUPLICATED, $this->notice()['outcome']);
        $stmt = $this->wpdb->pdo()->prepare(
            'SELECT meta_value FROM `' . $this->wpdb->postmeta . '` WHERE post_id = ? AND meta_key = ? ORDER BY meta_id'
        );
        $stmt->execute([self::PRODUCT, WpTectebCommissionSource::TYPE_META]);
        self::assertSame(['percent'], $stmt->fetchAll(\PDO::FETCH_COLUMN), 'the type was not touched');
    }

    /**
     * WHAT THIS PROVES: the ordinary saves still work, unchanged.
     *
     * The regression §1 asks for by name: an unchanged save, «بدون کمیسیون»,
     * a percentage, a fixed amount, and a product saved with no commission
     * field in the POST at all.
     */
    public function testTheOrdinarySavesAreUnaffected(): void
    {
        // Typed in, and STORED — `7` is kept as `7.00`, because
        // `TectebCommission` normalises a percentage to two places and the
        // rows hold what it decided, not what was typed.
        foreach ([
            ['percent', '12.50', '12.50'],
            ['percent', '12.50', '12.50'],          // the unchanged save
            ['fixed', '20000', '20000'],
            ['none', '', ''],
            ['percent', '7', '7.00'],
        ] as [$type, $typed, $stored]) {
            $this->save($type, $typed);
            self::assertSame(CommissionRuleWriter::SAVED, $this->notice()['outcome'], $type . '/' . $typed);
            self::assertSame([$type, $stored], $this->rows(), $type . '/' . $typed);
        }

        // A title-only save: no nonce, so nothing of ours is in the request.
        $_POST = ['post_title' => 'یک عنوان تازه'];
        TectebCommissionPanel::save(self::PRODUCT, Bootstrap::container());
        self::assertSame(['percent', '7.00'], $this->rows(), 'a save this panel did not come from changes nothing');
    }

    /**
     * WHAT THIS PROVES: the `alpha.60` guard is still in front of the write.
     *
     * The whole unit of work goes through `update_post_meta()` precisely so
     * that `TectebCommissionMetaGuard`'s five filters still speak — a writer
     * that wrote the rows itself would be the one door the guard is not on,
     * which was the finding of `alpha.58`.
     */
    public function testAnActorWithoutTheFinancialPermissionStillCannotMoveTheRows(): void
    {
        State::loginAs(11, ['read', 'edit_post']);
        $_POST = [
            TectebCommissionPanel::NONCE => wp_create_nonce(TectebCommissionPanel::NONCE),
            'tmc_commission_type' => 'fixed',
            'tmc_commission_value' => '999999',
        ];
        TectebCommissionPanel::save(self::PRODUCT, Bootstrap::container());

        self::assertSame(['percent', '12.50'], $this->rows());
        $refusals = array_filter(
            $this->audit->records,
            static fn (\Tecteb\Marketplace\Contracts\AuditRecord $r): bool
                => $r->eventType === AuditEventCatalog::COMMISSION_RULE_SET
                && ($r->payload['outcome'] ?? '') === 'refused'
        );
        self::assertNotSame([], $refusals, 'a refused financial change is audited');

        // …and the same actor cannot reach the rows through the meta filters
        // either, which is the guard `alpha.58`–`alpha.60` built.
        self::assertFalse(update_post_meta(self::PRODUCT, WpTectebCommissionSource::VALUE_META, '999999'));
        self::assertSame(['percent', '12.50'], $this->rows());
    }

    // ------------------------------------------------------------- helpers

    /** The two rows, read with SQL — never `get_post_meta()` (`alpha.55`). */
    private function rows(): array
    {
        $out = [];
        foreach ([WpTectebCommissionSource::TYPE_META, WpTectebCommissionSource::VALUE_META] as $key) {
            $stmt = $this->wpdb->pdo()->prepare(
                'SELECT meta_value FROM `' . $this->wpdb->postmeta . '` WHERE post_id = ? AND meta_key = ?'
            );
            $stmt->execute([self::PRODUCT, $key]);
            $value = $stmt->fetchColumn();
            $out[] = $value === false ? '__absent__' : (string) $value;
        }
        return $out;
    }

    private function save(string $type, string $value): void
    {
        $_POST = [
            TectebCommissionPanel::NONCE => wp_create_nonce(TectebCommissionPanel::NONCE),
            'tmc_commission_type' => $type,
            'tmc_commission_value' => $value,
        ];
        delete_transient('tmc_commission_notice_' . (int) get_current_user_id());
        TectebCommissionPanel::save(self::PRODUCT, Bootstrap::container());
        $_POST = [];
    }

    /** WordPress's own short-circuit, which is how a meta write fails. */
    private function refuse(string $metaKey): void
    {
        foreach (['update_post_metadata', 'add_post_metadata'] as $filter) {
            add_filter($filter, static function (mixed $check, mixed $id, mixed $key) use ($metaKey): mixed {
                return (string) $key === $metaKey ? false : $check;
            }, 9, 3);
        }
    }

    /** @return array<string,mixed> */
    private function notice(): array
    {
        $data = get_transient('tmc_commission_notice_' . (int) get_current_user_id());
        return is_array($data) ? $data : ['outcome' => '__none__'];
    }

    private function renderedNotice(): string
    {
        $method = new \ReflectionMethod(TectebCommissionPanel::class, 'notice');
        $method->setAccessible(true);
        return trim(strip_tags((string) $method->invoke(null, $this->notice())));
    }
}
