<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Contracts\TransactionInterface;
use Tecteb\Marketplace\Modules\Migration\Domain\MinMaxRule;
use Tecteb\Marketplace\Modules\Product\Domain\PurchaseQuantity;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;

/**
 * Moving «Min Max Control»'s purchase rules onto this marketplace's own — as a
 * preview first, then as an explicit, batched, resumable write, then as a
 * rollback that knows what it is putting back.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS DOES NOT DO, AND WHY EACH ONE IS DELIBERATE
 * ---------------------------------------------------------------------------
 *
 *  - **it never writes to Min Max.** `MinMaxReaderInterface` has no write
 *    method. The standing rule is that no existing plugin's data is edited,
 *    and the exit path is the owner deactivating the plugin, not this code
 *    emptying it.
 *  - **it never converts a rule it cannot express.** A `STEP_BASE_DIFFERS`
 *    rule is RECORDED AND REFUSED by name, because the two plugins' step
 *    arithmetic counts from different bases (`MinMaxRule`) and copying the
 *    three numbers would remove permission and add permission at the same
 *    time. Picking a resolution would be this plugin deciding what a vendor
 *    meant by their own prices.
 *  - **it never overwrites a rule the manager already owns here.**
 *    `ProjectedQuantity`'s ownership record answers that, and a destination
 *    whose rows are somebody else's is recorded as `held_by_manager` with
 *    nothing written. «قواعدی که مدیر قبلاً در بازارگاه تعیین کرده بی‌اجازه
 *    بازنویسی نشوند» — and the permission is a separate, explicit call.
 *  - **it never claims a write it cannot enforce.** Writing the three rows
 *    onto a product the marketplace does not own produces rows nothing reads,
 *    because `QuantityGuard::ruleFor()` returns before reading them. That is
 *    its own recorded state, `written_unenforced`, so the exit report can say
 *    how many rules would stop being applied the moment Min Max is switched
 *    off. It is the single most important number in this whole migration and
 *    it is not allowed to hide inside a success count.
 *
 * ---------------------------------------------------------------------------
 * RESUMABLE, REPEATABLE, REVERSIBLE
 * ---------------------------------------------------------------------------
 *
 * One batch reads one page of source rows and returns WHERE IT STOPPED, which
 * is the reader's cursor and not the last row it wrote (`alpha.35`: a batch
 * that reports only what it did cannot be resumed past a row it skipped). A
 * second run over the same page finds a handover row per product and leaves it
 * alone, so running the whole migration twice writes nothing the second time —
 * and that is checked by a UNIQUE KEY rather than by a read followed by a
 * write.
 *
 * Each written product's row carries the destination's values from BEFORE the
 * write, with `null` for «there was no row», and the fingerprint of what the
 * migration itself wrote. `rollback()` compares the fingerprint against what
 * the shop holds NOW and refuses when they differ: a manager who edited the
 * rule after the migration has made a newer decision, and «تغییرات جدیدتر را
 * کورکورانه پاک نکند» means that decision wins over a rollback.
 */
final class MigrateMinMax
{
    /** How many source products one batch looks at. */
    public const BATCH = 100;

    /** Up to which quantity the preview compares the two rules' answers. */
    private const COMPARE_TO = 60;

    public function __construct(
        private readonly MinMaxReaderInterface $reader,
        private readonly MinMaxHandoverRepositoryInterface $ledger,
        private readonly ShopQuantityPortInterface $shop,
        private readonly ?TransactionInterface $transaction = null
    ) {
    }

    /**
     * The read-only preview: what is there, what would convert, what would
     * not, and what would be written where nothing reads it.
     *
     * Writes nothing at all — not a ledger row, not a meta row, not an audit
     * line. A manager has to be able to ask this question before deciding, and
     * a preview with a side effect is a decision taken early.
     *
     * @return array<string,mixed>
     */
    public function preview(int $maxProducts = 2000): array
    {
        if (!$this->reader->isAvailable()) {
            return ['available' => false, 'mode' => null, 'counts' => $this->reader->counts()];
        }
        $mode = $this->reader->mode();
        $global = $this->reader->globalRule();
        $terms = $this->reader->termRules();

        $out = [
            'available' => true,
            'mode' => $mode,
            'counts' => $this->reader->counts(),
            'presentation_settings' => $this->reader->presentationSettings(),
            'global' => $global === null ? null : $this->describe($global),
            'terms' => [],
            'levels' => ['product' => 0, 'variation' => 0],
            'convertible' => 0,
            'refused' => 0,
            'held_by_manager' => 0,
            'unenforced' => 0,
            'already_decided' => 0,
            'problems' => [],
            'notes' => [],
            'examples' => [],
            'scanned' => 0,
            'truncated' => false,
        ];
        foreach ($terms as $key => $rule) {
            $out['terms'][$key] = $this->describe($rule);
            foreach ($rule->problems() as $problem) {
                $out['problems'][$problem] = ($out['problems'][$problem] ?? 0) + 1;
            }
        }

        $cursor = 0;
        while ($out['scanned'] < $maxProducts) {
            $page = $this->reader->productRulesAfter($cursor, self::BATCH);
            foreach ($page['rows'] as $row) {
                $out['scanned']++;
                /** @var MinMaxRule $rule */
                $rule = $row['rule'];
                $level = $rule->level === MinMaxRule::AT_VARIATION ? 'variation' : 'product';
                $out['levels'][$level]++;
                foreach ($rule->notes() as $note) {
                    $out['notes'][$note] = ($out['notes'][$note] ?? 0) + 1;
                }
                if ($this->ledger->find((int) $row['product_id']) !== null) {
                    $out['already_decided']++;
                    continue;
                }
                $problems = $rule->problems();
                if ($problems !== []) {
                    $out['refused']++;
                    foreach ($problems as $problem) {
                        $out['problems'][$problem] = ($out['problems'][$problem] ?? 0) + 1;
                    }
                    if (count($out['examples']) < 20) {
                        $out['examples'][] = $this->example($row, $rule, 'refused', $problems);
                    }
                    continue;
                }
                $held = $this->destinationHeldElsewhere((int) $row['product_id']);
                if ($held) {
                    $out['held_by_manager']++;
                    if (count($out['examples']) < 20) {
                        $out['examples'][] = $this->example($row, $rule, 'held_by_manager', []);
                    }
                    continue;
                }
                if (!$this->shop->marketplaceOwns((int) $row['product_id'])) {
                    $out['unenforced']++;
                    if (count($out['examples']) < 20) {
                        $out['examples'][] = $this->example($row, $rule, 'written_unenforced', []);
                    }
                    continue;
                }
                $out['convertible']++;
            }
            if ($page['done']) {
                break;
            }
            if ($page['cursor'] <= $cursor) {
                break;                  // no progress: stop rather than loop
            }
            $cursor = $page['cursor'];
        }
        $out['truncated'] = $out['scanned'] >= $maxProducts;
        return $out;
    }

    /**
     * One batch of the explicit migration.
     *
     * `$allowOverwrite` is the manager's permission to take a destination rule
     * they own, and it defaults to refusing. It is a parameter rather than a
     * setting so that granting it is an action on one screen with one click,
     * recorded in the ledger as the state it produced — not a flag somebody
     * turned on three months ago.
     *
     * @return array<string,mixed>
     */
    public function migrateBatch(int $runId, int $cursor, bool $allowOverwrite = false, int $limit = self::BATCH): array
    {
        if (!$this->reader->isAvailable()) {
            return ['ok' => false, 'code' => 'minmax_absent', 'cursor' => $cursor, 'done' => true, 'counts' => []];
        }
        $page = $this->reader->productRulesAfter($cursor, $limit);
        $counts = [
            MinMaxHandoverRepositoryInterface::MIGRATED => 0,
            MinMaxHandoverRepositoryInterface::REFUSED => 0,
            MinMaxHandoverRepositoryInterface::HELD => 0,
            MinMaxHandoverRepositoryInterface::UNENFORCED => 0,
            'already_decided' => 0,
            'not_written' => 0,
            'absent_product' => 0,
        ];
        foreach ($page['rows'] as $row) {
            $postId = (int) $row['product_id'];
            /** @var MinMaxRule $rule */
            $rule = $row['rule'];
            if ($this->ledger->find($postId) !== null) {
                $counts['already_decided']++;
                continue;               // repeatable: one decision per product
            }
            if (!$this->shop->exists($postId)) {
                $counts['absent_product']++;
                continue;
            }
            $outcome = $this->decide($runId, $postId, (string) $row['type'], $rule, $allowOverwrite);
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
        }
        return [
            'ok' => true,
            'code' => 'batch_done',
            'cursor' => $page['cursor'],
            'done' => $page['done'],
            'counts' => $counts,
        ];
    }

    /**
     * One product: decide, write if allowed, and record what the destination
     * held before.
     *
     * The ledger row is written in the SAME unit of work as the meta rows when
     * a transaction is available, because the half that only records would
     * leave a rule applied with no rollback target and the half that only
     * writes would leave a product migrated twice. `alpha.49`'s rule: the
     * first half of a job done alone is worse than neither half.
     */
    private function decide(
        int $runId,
        int $postId,
        string $postType,
        MinMaxRule $rule,
        bool $allowOverwrite
    ): string {
        $problems = $rule->problems();
        $before = $this->shop->read($postId);
        $base = [
            'post_id' => $postId,
            'post_type' => $postType,
            'level' => $rule->level,
            'source_min' => $rule->sourceMin,
            'source_max' => $rule->sourceMax,
            'source_step' => $rule->sourceStep,
            'source_default' => $rule->sourceDefault,
            'dest_min_before' => $before['min'],
            'dest_max_before' => $before['max'],
            'dest_step_before' => $before['step'],
            'dest_record_before' => $before['record'],
            'run_id' => (string) $runId,
        ];

        if ($problems !== []) {
            // Nothing is written, and the names of the problems are what the
            // exit report prints. «اگر قاعده‌ای جایگزین ندارد … دقیقاً همان
            // قاعده نام برده شود».
            $this->ledger->record($base + [
                'status' => MinMaxHandoverRepositoryInterface::REFUSED,
                'problems' => implode(',', $problems),
                'written_min' => null,
                'written_max' => null,
                'written_step' => null,
            ]);
            return MinMaxHandoverRepositoryInterface::REFUSED;
        }

        if (!$allowOverwrite && $this->destinationHeldElsewhere($postId, $before)) {
            $this->ledger->record($base + [
                'status' => MinMaxHandoverRepositoryInterface::HELD,
                'problems' => '',
                'written_min' => null,
                'written_max' => null,
                'written_step' => null,
            ]);
            return MinMaxHandoverRepositoryInterface::HELD;
        }

        $target = $rule->converted();
        $owns = $this->shop->marketplaceOwns($postId);
        $opened = $this->transaction !== null && !$this->transaction->inTransaction()
            && $this->transaction->begin();
        $written = $this->shop->write(
            $postId,
            (string) $target->min,
            $target->max === null ? '' : (string) $target->max,
            (string) $target->step
        );
        if (!$written['stored']) {
            if ($opened) {
                $this->transaction?->rollback();
            }
            // Nothing recorded as migrated, so a retry asks again. A ledger row
            // claiming a write that did not land would make that retry
            // impossible, which is the `alpha.44` rule about an unstored
            // record: delete it rather than leave it stale.
            return 'not_written';
        }
        $status = $owns
            ? MinMaxHandoverRepositoryInterface::MIGRATED
            : MinMaxHandoverRepositoryInterface::UNENFORCED;
        $this->ledger->record($base + [
            'status' => $status,
            'problems' => '',
            // The fingerprint is what the shop holds AFTER the write, read
            // back rather than what was sent (`alpha.26`): a rollback guard
            // built on what we sent would refuse every product WooCommerce
            // filtered on the way in.
            'written_min' => $written['min'],
            'written_max' => $written['max'],
            'written_step' => $written['step'],
        ]);
        if ($opened) {
            $this->transaction?->commit();
        }
        return $status;
    }

    /**
     * Whether the destination's rows belong to somebody other than this
     * migration.
     *
     * Three states, and the middle one is the reason this is not a boolean on
     * «is there a row»:
     *
     *  - no rows at all — nobody holds it;
     *  - rows this plugin's own record accounts for — ours, and a Min Max rule
     *    may replace a rule we projected ourselves;
     *  - rows with no record, or a record that does not match what is there —
     *    a manager's. Held.
     *
     * The judgement is the PORT's, because the record holds fingerprints and
     * not values. All this does is avoid asking twice.
     *
     * @param array{min:?string,max:?string,step:?string,held:bool,record:?string,exists:bool}|null $before
     */
    private function destinationHeldElsewhere(int $postId, ?array $before = null): bool
    {
        $before ??= $this->shop->read($postId);
        return (bool) ($before['held'] ?? false);
    }

    /**
     * Put one product's destination back to what it held before the migration.
     *
     * Refuses when the shop no longer holds what the migration wrote: that is
     * a decision made after this one, and a rollback is not a licence to undo
     * it. Three named answers rather than a boolean, because «there is nothing
     * to roll back» and «somebody changed it» want different sentences in
     * front of a manager.
     */
    public function rollback(int $postId): OperationResult
    {
        $row = $this->ledger->find($postId);
        if ($row === null) {
            return OperationResult::failure('minmax_rollback_nothing', ['post_id' => $postId]);
        }
        $status = (string) ($row['status'] ?? '');
        if ($status === MinMaxHandoverRepositoryInterface::ROLLED_BACK) {
            return OperationResult::failure('minmax_rollback_already', ['post_id' => $postId]);
        }
        if (!in_array($status, [
            MinMaxHandoverRepositoryInterface::MIGRATED,
            MinMaxHandoverRepositoryInterface::UNENFORCED,
        ], true)) {
            // A refused or held product was never written to, so there is
            // nothing to put back — and saying «rolled back» about it would
            // be a sentence nobody can act on.
            return OperationResult::failure('minmax_rollback_not_written', [
                'post_id' => $postId,
                'status' => $status,
            ]);
        }
        $now = $this->shop->read($postId);
        foreach (['min', 'max', 'step'] as $part) {
            $wrote = $row['written_' . $part];
            if ($wrote === null) {
                continue;
            }
            if ((string) ($now[$part] ?? '') !== (string) $wrote) {
                return OperationResult::failure('minmax_rollback_changed', [
                    'post_id' => $postId,
                    'field' => $part,
                    'wrote' => (string) $wrote,
                    'now' => $now[$part] === null ? '__absent__' : (string) $now[$part],
                ]);
            }
        }
        $stored = $row['dest_record_before'] ?? null;
        $record = is_string($stored) && $stored !== '' ? $stored : null;
        $ok = $this->shop->restore(
            $postId,
            $row['dest_min_before'] === null ? null : (string) $row['dest_min_before'],
            $row['dest_max_before'] === null ? null : (string) $row['dest_max_before'],
            $row['dest_step_before'] === null ? null : (string) $row['dest_step_before'],
            $record
        );
        if (!$ok) {
            return OperationResult::failure('minmax_rollback_not_stored', ['post_id' => $postId]);
        }
        if (!$this->ledger->markRolledBack($postId)) {
            // The rows are back and the ledger did not move. Reported rather
            // than swallowed: a ledger that still says «migrated» would offer
            // the same rollback again, and the second one would restore over
            // a destination that is already restored.
            return OperationResult::failure('minmax_rollback_unrecorded', ['post_id' => $postId]);
        }
        return OperationResult::success('minmax_rolled_back', ['post_id' => $postId]);
    }

    /** @return array<string,mixed> */
    private function describe(MinMaxRule $rule): array
    {
        $converted = $rule->convertible() ? $rule->converted() : null;
        return [
            'level' => $rule->level,
            'source' => [
                'min' => $rule->sourceMin,
                'max' => $rule->sourceMax,
                'step' => $rule->sourceStep,
                'default' => $rule->sourceDefault,
            ],
            'effective' => ['min' => $rule->min, 'max' => $rule->max, 'step' => $rule->step, 'default' => $rule->default],
            'unrestricted' => $rule->isUnrestricted(),
            'problems' => $rule->problems(),
            'notes' => $rule->notes(),
            'converted' => $converted === null ? null : [
                'min' => $converted->min,
                'max' => $converted->max,
                'step' => $converted->step,
                'satisfiable' => $converted->isSatisfiable(),
            ],
        ];
    }

    /**
     * One example row, with BOTH plugins' allowed quantities side by side.
     *
     * The numbers alone do not answer «did the rule survive» — for a step rule
     * that is a different question (`MinMaxRule`) — so the example carries the
     * two SETS up to a bound, which is the thing a manager can read and
     * disagree with.
     *
     * @param array<string,mixed> $row
     * @param list<string> $problems
     * @return array<string,mixed>
     */
    private function example(array $row, MinMaxRule $rule, string $status, array $problems): array
    {
        $allowed = $rule->allowedUpTo(self::COMPARE_TO);
        return [
            'post_id' => (int) $row['product_id'],
            'type' => (string) $row['type'],
            'status' => $status,
            'problems' => $problems,
            'rule' => $this->describe($rule),
            'allowed_by_minmax' => array_slice($allowed['source'], 0, 12),
            'allowed_after_conversion' => array_slice($allowed['converted'], 0, 12),
            'same_set' => $allowed['source'] === $allowed['converted'],
        ];
    }

    /**
     * The settings and features that have NO destination here, named once.
     *
     * Printed by the exit report so «Min Max may be deactivated» is never said
     * without this list beside it. A feature that is absent from a report reads
     * as a feature nobody needed.
     *
     * @return array<string,string>
     */
    public static function withoutReplacement(): array
    {
        return [
            MinMaxRule::DEFAULT_QUANTITY_UNSUPPORTED =>
                'تعداد پیش‌فرض جعبهٔ خرید (default_quantity): این بازارگاه چنین فیلدی ندارد '
                . 'و جعبهٔ ووکامرس از حداقل شروع می‌کند.',
            MinMaxRule::STEP_BASE_DIFFERS =>
                'گامی که از حداقل شمرده می‌شود: Min Max مضرب‌ها را از «حداقل» می‌شمارد و این '
                . 'بازارگاه از صفر (قاعدهٔ خودِ ووکامرس). روی قاعده‌ای که حداقلش مضرب گام نباشد '
                . 'دو مجموعهٔ مجاز هیچ عدد مشترکی ندارند.',
            'prefix_suffix' =>
                'پیشوند و پسوند متنِ جعبهٔ تعداد و دکمه‌های +/−: نمایشی‌اند و معادلی ندارند.',
            'quantiy_box_archive' =>
                'جعبهٔ تعداد در صفحهٔ فهرست محصولات: نمایشی است و معادلی ندارند.',
            'cart_wide_rules' =>
                'قواعد کل سبد (حداقل/حداکثر مبلغ یا تعداد سبد) و قاعدهٔ نقش کاربر: در این بسته '
                . 'از Min Max وجود ندارند (ماژول cart-page-condition در این build ثبت نشده)، '
                . 'پس چیزی برای انتقال نیست — ولی اگر نسخهٔ premium روی سایت نصب شود، هست.',
        ];
    }

    /** @return array<string,string> the refusal codes, with their sentences */
    public static function problemSentences(): array
    {
        return [
            MinMaxRule::STEP_BASE_DIFFERS => 'گام از مبنای دیگری شمرده می‌شود',
            MinMaxRule::MAX_BELOW_MIN => 'حداکثر کمتر از حداقل است',
            MinMaxRule::DEFAULT_QUANTITY_UNSUPPORTED => 'تعداد پیش‌فرض معادلی ندارد',
            MinMaxRule::MIN_ZERO_FLOORED => 'حداقل صفر به یک تبدیل می‌شود',
        ];
    }

    /** The converted rule for one source rule, for tools and tests. */
    public static function convert(MinMaxRule $rule): ?PurchaseQuantity
    {
        return $rule->convertible() ? $rule->converted() : null;
    }
}
