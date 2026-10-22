<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Contracts\TransactionInterface;
use Tecteb\Marketplace\Modules\Migration\Domain\MinMaxRule;
use Tecteb\Marketplace\Modules\Product\Domain\PurchaseQuantity;
use Tecteb\Marketplace\Modules\Product\Domain\QuantitySet;
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

    /**
     * Outcomes that are NOT a decision — nothing was recorded and nothing was
     * written, so the product is still to do and the batch stops.
     *
     * Named in one place because the batch, the admin page and the evidence
     * script all have to agree on «did this product get an answer», and three
     * lists of strings would be three answers.
     *
     * @var list<string>
     */
    public const UNFINISHED = [
        'not_written',
        'no_status',
        'history_not_written',
        'not_committed',
        'no_transaction',
        'caller_transaction',
        'not_atomic',
        'claim_refused',
        'not_locked',
        'destination_unreadable',
    ];

    /**
     * Up to which quantity an EXAMPLE ROW lists the two sides' answers.
     *
     * An illustration for a manager to read, and nothing decides anything by
     * it. Until `alpha.71` this same number was the comparison itself — a
     * loop over 1…60 whose sixty matching answers were taken for «the same
     * rule» — and `QuantitySet` records what that cost. Raising it would not
     * have been a fix, so it was not raised: it was taken out of the verdict
     * and left here, under a name that says what it is.
     */
    private const ILLUSTRATE_TO = 60;

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
     * ⛔ AND A PRODUCT WITH A LEDGER ROW IS NOT SKIPPED ANY MORE. Until this
     * round `already_decided` was counted and the product dropped, which made
     * four very different states indistinguishable from «dealt with»: a live
     * refusal, a held destination, a rule the manager changed after the
     * migration, and a product that stopped being the marketplace's. Each is
     * classified now (`classifyDecided()`), and `already_decided` stays as the
     * TOTAL so the arithmetic on the page still closes.
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
            // THE DECIDED PRODUCTS, TAKEN APART. `already_decided` is a count
            // of ledger rows and says nothing about the rule being equal
            // TODAY: `alpha.69` skipped every one of them, so a recorded
            // refusal, a held destination, a rule the manager changed
            // afterwards and a product that stopped being ours all read as
            // «dealt with». Four answers, because they have four remedies.
            'decided_in_force' => 0,
            'decided_drifted' => 0,
            'decided_unenforced' => 0,
            'decided_unreadable' => 0,
            // Read fine, and still not comparable: a rule holding a quantity
            // outside what either store can express. `QuantitySet::known()`.
            'decided_unprovable' => 0,
            // A SUBSET of the ledger's own live counts, reported for the page
            // and never added to them: a refusal whose source rule has since
            // been deleted is live in the ledger and invisible to this walk,
            // so the ledger is the authority and this is the part we could
            // also see here.
            'decided_refused' => 0,
            'decided_held' => 0,
            'problems' => [],
            'notes' => [],
            'examples' => [],
            'scanned' => 0,
            'truncated' => false,
            // WHETHER THE WALK REACHED THE END, which `truncated` does not
            // say: the loop also stops when a page reports no progress, and
            // «I stopped early» and «I saw everything» must not share a flag
            // (`alpha.68` §1, the same shape one module over).
            'walk_finished' => false,
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
                $decision = $this->ledger->find((int) $row['product_id']);
                if ($decision !== null) {
                    $out['already_decided']++;
                    // ⛔ NOT a `continue` any more. A ledger row is a record
                    // of a decision taken once; the exit question is about
                    // what would happen if the plugin were switched off NOW.
                    $this->classifyDecided(
                        $out,
                        (int) $row['product_id'],
                        $rule,
                        (string) ($decision['status'] ?? '')
                    );
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
                $out['walk_finished'] = true;
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
     * WHAT A DECIDED PRODUCT WOULD DO IF THE PLUGIN WERE SWITCHED OFF NOW.
     *
     * `alpha.69` asked `find()` and moved on, and that is the whole of the
     * owner's second finding: a `migrated` or `rule_enforced` row is a record
     * that the rule was taken ONCE, and «was taken» is not «is the same
     * today». Three things move underneath it afterwards, each on its own
     * screen and each legitimately:
     *
     *  - the SOURCE rule — the manager edits it in Min Max's own panel;
     *  - the DESTINATION rows — the manager edits the purchase limits in the
     *    product editor, which `QuantityPanel` is for;
     *  - OWNERSHIP and the GRANT — a product can stop being the
     *    marketplace's, and a grant is a fingerprint of three values that
     *    stops describing them the moment one changes (`alpha.49`).
     *
     * So the comparison is made HERE, on the live state, and it compares the
     * two SETS OF ALLOWED QUANTITIES rather than the numbers — `alpha.53`'s
     * rule, because for a step rule «the numbers are the same» and «the rule
     * is the same» are different questions, and the two plugins count
     * multiples from different bases.
     *
     * The rule that WOULD be enforced after deactivation is:
     *
     *  - the destination rows, when the marketplace owns the product or the
     *    manager has granted enforcement — those are the two states in which
     *    `QuantityGuard` reads them;
     *  - NOTHING AT ALL otherwise. Not «the same rule»: no limit, every
     *    quantity allowed, which is the worst of the three and the one
     *    `alpha.69` scored as «already decided».
     *
     * ⛔ IT WRITES NOTHING. A manager's change is never overwritten and never
     * re-migrated: this is the question, and the answer is a count and a
     * named blocker. «تغییر مدیر خودکار بازنویسی نشود.»
     *
     * And a read that FAILED is its own answer. `readRows()` says whether the
     * statement worked, and taking a failure for «absent» would compare
     * against three rows that are there (`alpha.49`, `alpha.68` §1).
     *
     * @param array<string,mixed> $out
     */
    private function classifyDecided(array &$out, int $postId, MinMaxRule $rule, string $status): void
    {
        if (
            $status === MinMaxHandoverRepositoryInterface::REFUSED
            || $status === MinMaxHandoverRepositoryInterface::HELD
        ) {
            // NOTHING WAS WRITTEN for either, so there is no destination to
            // compare and asking would invent an equality question about a
            // rule nobody moved. What blocks these is the ledger's own live
            // count; what this does is name the problems so the report can
            // print them, because the walk can still see the source rule.
            if ($status === MinMaxHandoverRepositoryInterface::REFUSED) {
                $out['decided_refused']++;
                foreach ($rule->problems() as $problem) {
                    $out['problems'][$problem] = ($out['problems'][$problem] ?? 0) + 1;
                }
            } else {
                $out['decided_held']++;
            }
            return;
        }

        $rows = $this->shop->readRows($postId);
        if (!($rows['read'] ?? false)) {
            $out['decided_unreadable']++;
            if (count($out['examples']) < 20) {
                $out['examples'][] = $this->example($rows + ['product_id' => $postId, 'type' => 'unknown'], $rule, 'decided_unreadable', []);
            }
            return;
        }
        if (!$this->shop->marketplaceOwns($postId) && !$this->shop->enforcementGranted($postId)) {
            $out['decided_unenforced']++;
            if (count($out['examples']) < 20) {
                $out['examples'][] = $this->example(['product_id' => $postId, 'type' => 'decided'], $rule, 'decided_unenforced', []);
            }
            return;
        }
        // The three rows read the way the guard reads them: absent or empty
        // is the documented default, and `PurchaseQuantity::of()` then applies
        // the same coercion `ProjectedQuantity::ruleFrom()` applies — which is
        // the object `QuantityGuard` enforces. The reading is repeated here
        // rather than called because this layer may not reach into
        // Infrastructure, and `quantityFrom()` carries that note.
        $stored = PurchaseQuantity::of(
            self::quantityFrom($rows['min'] ?? null, 1),
            ($rows['max'] ?? null) === null || (string) $rows['max'] === '' ? null : (int) $rows['max'],
            self::quantityFrom($rows['step'] ?? null, 1)
        );

        // ⛔ EQUALITY IS DECIDED, NOT SAMPLED.
        //
        // `alpha.70` asked both sides about the quantities 1…60 and called
        // sixty matching answers `decided_in_force`. Measured on its own
        // installed bytes: Min Max `1/100/1` against stored rows `1/200/1`
        // agreed on all sixty and first disagreed at **101**, verdict
        // `permitted`; and Min Max `min=70` against stored `min=80` agreed on
        // all sixty — both refuse every quantity below 70 — first disagreed
        // at **70**, verdict `permitted`. One would have widened a ceiling by
        // a hundred units at deactivation and the other would have taken ten
        // quantities away, with the exit report saying nothing of either.
        //
        // Both sides allow an arithmetic progression and nothing else, so each
        // set is fixed by the minimum, the maximum, the step and the base the
        // step counts from, and equality is a comparison of four numbers.
        // `QuantitySet` holds that arithmetic and the reasons it is exact.
        $minmax = $rule->allowedSet();
        $here = QuantitySet::fromPurchaseQuantity($stored);
        if (!$minmax->known() || !$here->known()) {
            // A quantity outside what the columns can hold. «Not provable» is
            // its own answer: it is not a difference, and reading it as an
            // equality is how a guard comes to permit things.
            $out['decided_unprovable']++;
            if (count($out['examples']) < 20) {
                $out['examples'][] = $this->example(
                    ['product_id' => $postId, 'type' => 'decided'],
                    $rule,
                    'decided_unprovable',
                    ['quantity_out_of_range']
                );
            }
            return;
        }
        // A rule that is no longer expressible HERE has no equality to prove
        // either, whatever quantities it allows: a `default_quantity` added to
        // the source since the migration pre-fills a box this marketplace has
        // no field for, and a step whose base has moved is the `alpha.53`
        // finding itself. `exitVerdict()` counts `incompatible` only for rules
        // nobody has decided yet, so without this a decided product whose
        // source rule turned incompatible was invisible to the verdict.
        $problems = $rule->problems();
        if ($problems !== [] || !$minmax->equals($here)) {
            $out['decided_drifted']++;
            $named = $problems === [] ? ['rule_changed_since_migration'] : $problems;
            foreach ($named as $problem) {
                $out['problems'][$problem] = ($out['problems'][$problem] ?? 0) + 1;
            }
            if (count($out['examples']) < 20) {
                $out['examples'][] = $this->example(['product_id' => $postId, 'type' => 'decided'], $rule, 'decided_drifted', $named);
            }
            return;
        }
        $out['decided_in_force']++;
    }

    /** An integer from a destination row, where absent and empty both mean «unset». */
    private static function quantityFrom(?string $value, int $whenUnset): int
    {
        return $value === null || trim($value) === '' ? $whenUnset : (int) $value;
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
     * **It STOPS at the first product that would not write, and the cursor it
     * returns does not pass that product.** `alpha.53` advanced the cursor to
     * the end of the page whatever happened, so a product whose write failed
     * in the middle of a page was never looked at again by any later
     * continuation — measured on its own bytes: `middle_id=374
     * cursor_after=375 cursor_past_failure=yes ledger_middle=__none__`, with
     * no key naming the failure. That is `alpha.35`'s rule in a new place: a
     * cursor that steps over an unwritten row makes a promise no later pass
     * can contradict.
     *
     * So the cursor is the last product this batch actually FINISHED, which
     * means a continuation re-reads the failed one and nothing before it, and
     * `stopped_at`/`failed` name it. Continuing is the manager's click, so a
     * product that keeps failing stops the walk in the same place and says so
     * rather than looping.
     *
     * @return array<string,mixed>
     */
    public function migrateBatch(int $runId, int $cursor, bool $allowOverwrite = false, int $limit = self::BATCH): array
    {
        if (!$this->reader->isAvailable()) {
            return [
                'ok' => false, 'code' => 'minmax_absent', 'cursor' => $cursor, 'done' => true,
                'counts' => [], 'stopped_at' => 0, 'failed' => [],
            ];
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
        // Where the walk has got to, as a FINISHED position. It starts where
        // the caller was, not where the page ends.
        $reached = $cursor;
        $stoppedAt = 0;
        $failed = [];
        foreach ($page['rows'] as $row) {
            $postId = (int) $row['product_id'];
            /** @var MinMaxRule $rule */
            $rule = $row['rule'];
            if ($this->ledger->find($postId) !== null) {
                $counts['already_decided']++;
                $reached = max($reached, $postId);
                continue;               // repeatable: one LIVE decision each
            }
            if (!$this->shop->exists($postId)) {
                $counts['absent_product']++;
                $reached = max($reached, $postId);
                continue;
            }
            $outcome = $this->decide($runId, $postId, (string) $row['type'], $rule, $allowOverwrite);
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
            if (in_array($outcome, self::UNFINISHED, true)) {
                // Stop here. Everything before this product is finished and
                // the cursor says so; this product and everything after it is
                // still to do.
                $stoppedAt = $postId;
                $failed[] = ['post_id' => $postId, 'outcome' => $outcome];
                return [
                    'ok' => false,
                    'code' => 'batch_stopped',
                    'cursor' => $reached,
                    'done' => false,
                    'counts' => $counts,
                    'stopped_at' => $stoppedAt,
                    'failed' => $failed,
                ];
            }
            $reached = max($reached, $postId);
        }
        return [
            'ok' => true,
            'code' => 'batch_done',
            // The reader's own cursor, which is where the PAGE ends and can be
            // past the last row it returned (a product whose every row is
            // blank is read and dropped). Safe only because nothing in this
            // page is unfinished.
            'cursor' => max($reached, (int) $page['cursor']),
            'done' => $page['done'],
            'counts' => $counts,
            'stopped_at' => 0,
            'failed' => [],
        ];
    }

    /**
     * One product: claim it, write, record what the destination held, and
     * commit — or write nothing at all.
     *
     * **The claim comes first, and that is the round's central change.** The
     * ledger row carries the destination's pre-migration values, so it is the
     * only record of what a rollback would put back; `alpha.53` wrote it after
     * the meta rows, which bought two measured defects at once:
     *
     *  - a refused ledger write left the destination migrated with NO record
     *    of what was there before (`rows_after=4/10/2 ledger_after=__none__
     *    migrated_count=1`) — rollback impossible, reported as success;
     *  - the unique key could only arbitrate the race AFTER both runs had
     *    already written the three rows.
     *
     * Now the claim is an `INSERT` on a unique key taken BEFORE the first
     * write, inside the transaction, so the loser of a race blocks on the key
     * and then finds the decision taken — without ever reaching the shop.
     *
     * **No transaction is a refusal, not a reason to do the first half.**
     * `alpha.49`'s rule, and `alpha.53` did the opposite: a refused `begin()`
     * left `$opened` false and the write ran anyway. Measured:
     * `begin_called=1 rows_after=4/10/2 refused_for_atomicity=0`.
     *
     * And the caller's own transaction is refused rather than joined: this
     * method cannot promise a unit of work it does not own, and committing
     * somebody else's transaction to keep that promise is exactly what the
     * instruction forbids — «تراکنش متعلق به caller را بی‌اجازه commit نکن».
     */
    private function decide(
        int $runId,
        int $postId,
        string $postType,
        MinMaxRule $rule,
        bool $allowOverwrite
    ): string {
        if ($this->transaction === null) {
            return 'no_transaction';
        }
        if ($this->transaction->inTransaction()) {
            return 'caller_transaction';
        }
        if (!$this->transaction->begin()) {
            return 'not_atomic';
        }

        $problems = $rule->problems();

        // **THE LOCK COMES BEFORE THE READ, and that is this round's change
        // to `decide()`.** `alpha.54` read the destination first and locked
        // nothing, so three things were decided on a value another writer was
        // free to replace a statement later: what the ledger records as «what
        // was there before», whether the rows are the manager's (`held`), and
        // whether the write is about to overwrite a brand-new value. The
        // ledger claim arbitrates two MIGRATIONS racing — that is what the
        // unique key is for — and says nothing at all about a manager editing
        // the purchase limits in wp-admin at the same moment.
        //
        // Same lock, same order as `rollback()`: postmeta rows first, the
        // ledger row second. Two paths that take the same locks in the same
        // order cannot deadlock against each other, and the order is this way
        // round because the ledger claim needs the pre-write values to carry.
        if (!$this->shop->lockQuantityRows($postId)) {
            $this->transaction->rollback();
            return 'not_locked';
        }
        $before = $this->shop->readRows($postId);
        if (!($before['read'] ?? false)) {
            // A read that failed is not «there is nothing there». Taking it
            // for absence would record `null/null/null` as the destination's
            // earlier state and make a later rollback delete three rows that
            // were never ours to delete.
            $this->transaction->rollback();
            return 'destination_unreadable';
        }
        if (($before['duplicates'] ?? []) !== []) {
            // Two rows for one meta key, which `wp_postmeta` permits: it has
            // no unique index on `(post_id, meta_key)` — measured, see
            // `WpShopQuantityPort::lockQuantityRows()`. WordPress reads the
            // lowest `meta_id`, but a restore's `update_post_meta()` rewrites
            // EVERY row with that key, so rolling this product back would
            // overwrite the duplicate's own value with the one WordPress
            // happened to be reading. That is «silently erasing a newer
            // change» by another route, so the product is REFUSED by name and
            // the manager can clean the rows and `release()` it.
            $problems = array_values(array_unique(array_merge($problems, ['destination_duplicated'])));
        }
        $claim = $this->ledger->claim([
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
        ]);
        if (!$claim['claimed']) {
            $this->transaction->rollback();
            // Either another run holds it or a decision already stands. Both
            // are «somebody else answered this», and the status says which.
            return $claim['taken_by'] === '' ? 'claim_refused' : 'already_decided';
        }

        // Not nullable: every branch below assigns it or returns, and leaving
        // it nullable would let a later edit reach `finish()` with `null` —
        // which `declare(strict_types=1)` turns into a loud TypeError rather
        // than a silent empty status, but loud in production is still in
        // production.
        $status = '';
        $written = ['written_min' => null, 'written_max' => null, 'written_step' => null];

        if ($problems !== []) {
            // Nothing is written, and the names of the problems are what the
            // exit report prints. «اگر قاعده‌ای جایگزین ندارد … دقیقاً همان
            // قاعده نام برده شود».
            $status = MinMaxHandoverRepositoryInterface::REFUSED;
        } elseif (!$allowOverwrite && (bool) ($before['held'] ?? false)) {
            $status = MinMaxHandoverRepositoryInterface::HELD;
        } else {
            $target = $rule->converted();
            $owns = $this->shop->marketplaceOwns($postId);
            $landed = $this->shop->write(
                $postId,
                (string) $target->min,
                $target->max === null ? '' : (string) $target->max,
                (string) $target->step
            );
            if (!($landed['stored'] ?? false)) {
                $this->transaction->rollback();
                // The claim goes with the transaction, so the product is
                // claimable again and a retry asks the same question. The
                // object cache does not roll back, though, so the half-written
                // values would be read for the rest of this request.
                $this->shop->forgetCachedQuantities($postId);
                return 'not_written';
            }
            $status = $owns
                ? MinMaxHandoverRepositoryInterface::MIGRATED
                : MinMaxHandoverRepositoryInterface::UNENFORCED;
            // The fingerprint is what the shop holds AFTER the write, read
            // back rather than what was sent (`alpha.26`): a rollback guard
            // built on what we sent would refuse every product WooCommerce
            // filtered on the way in.
            $written = [
                'written_min' => $landed['min'],
                'written_max' => $landed['max'],
                'written_step' => $landed['step'],
            ];
        }

        if ($status === '') {
            // Unreachable today — the three branches above are exhaustive —
            // and it is here because the alternative is a `null` reaching the
            // ledger. A refusal that writes nothing is the safe answer to «I
            // do not know what this is».
            $this->transaction->rollback();
            return 'no_status';
        }
        if (!$this->ledger->finish($claim['id'], $status, implode(',', $problems), $written)) {
            $this->transaction->rollback();
            $this->shop->forgetCachedQuantities($postId);
            // `alpha.53` discarded this result and committed. The product then
            // carried the migrated rule with no record of its earlier values.
            return 'history_not_written';
        }
        if (!$this->transaction->commit()) {
            $this->shop->forgetCachedQuantities($postId);
            // Nothing persisted — which is the right data outcome — but
            // `alpha.53` still answered «migrated» here, so the page counted a
            // success and stepped past a product whose rule never moved.
            return 'not_committed';
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
     * Put one product's destination back to what it held before the migration
     * — the three values, the ownership record and the ledger status, as ONE
     * unit of work.
     *
     * **Every step is verified and a failure at any of them leaves nothing
     * half-done.** `alpha.53` did four separate writes, checked three of them
     * and never looked at the record; measured on its own bytes, refusing the
     * second value write left the destination carrying `4/__absent__/2` — a
     * rule nobody had ever written — with the ledger still saying `migrated`,
     * and refusing the record write reported success.
     *
     * **The compare and the write are locked together.** The guard against «a
     * manager edited this after the migration» is a comparison, and a
     * comparison followed by a separate write has a window: measured with a
     * second writer on its own statement inside it, `alpha.53` answered
     * `minmax_rolled_back` and deleted the manager's brand-new value. So the
     * three meta rows are taken `FOR UPDATE` first, inside the transaction,
     * and a second writer serialises behind this request instead of being
     * overwritten — the `alpha.50` shape, one object down.
     *
     * Named answers rather than a boolean, because «there is nothing to roll
     * back», «somebody changed it» and «the restore would not store» want
     * different sentences in front of a manager.
     */
    public function rollback(int $postId): OperationResult
    {
        if ($this->transaction === null) {
            return OperationResult::failure('minmax_rollback_no_transaction', ['post_id' => $postId]);
        }
        if ($this->transaction->inTransaction()) {
            // The same refusal `decide()` makes, for the same reason: this
            // cannot promise a unit of work it does not own, and committing
            // the caller's transaction to keep the promise is forbidden.
            return OperationResult::failure('minmax_rollback_caller_transaction', ['post_id' => $postId]);
        }
        if (!$this->transaction->begin()) {
            return OperationResult::failure('minmax_rollback_not_atomic', ['post_id' => $postId]);
        }

        $row = $this->ledger->find($postId);
        if ($row === null) {
            $this->transaction->rollback();
            return OperationResult::failure('minmax_rollback_nothing', ['post_id' => $postId]);
        }
        $status = (string) ($row['status'] ?? '');
        if ($status === MinMaxHandoverRepositoryInterface::ROLLED_BACK) {
            $this->transaction->rollback();
            return OperationResult::failure('minmax_rollback_already', ['post_id' => $postId]);
        }
        if (!in_array($status, [
            MinMaxHandoverRepositoryInterface::MIGRATED,
            MinMaxHandoverRepositoryInterface::UNENFORCED,
            // A granted product too: the grant was given about the rule this
            // migration wrote, so putting the rule back takes the grant with
            // it. Leaving the grant behind would enforce whatever the
            // destination held BEFORE the migration — a rule the manager
            // granted nothing about.
            MinMaxHandoverRepositoryInterface::ENFORCED,
        ], true)) {
            // A refused or held product was never written to, so there is
            // nothing to put back — and saying «rolled back» about it would
            // be a sentence nobody can act on. The way to try it again is
            // `release()`, which is a different button.
            $this->transaction->rollback();
            return OperationResult::failure('minmax_rollback_not_written', [
                'post_id' => $postId,
                'status' => $status,
            ]);
        }

        if (!$this->shop->lockQuantityRows($postId)) {
            $this->transaction->rollback();
            return OperationResult::failure('minmax_rollback_not_locked', ['post_id' => $postId]);
        }

        // Read AFTER the lock — AND FROM THE ROWS, not from `get_post_meta()`.
        //
        // `alpha.54` did the first half and not the second, and the second is
        // where the guard actually lived. Read off core: `get_metadata_raw()`
        // answers from `wp_cache_get($id, 'post_meta')` and only fills that
        // cache when it is cold, so a copy warmed earlier in this request —
        // by `wc_get_product()`, by an admin screen rendering the product, by
        // this plugin's own preview — is what the comparison got, however
        // carefully the rows had just been locked. With a persistent object
        // cache the stale copy can predate another process's committed write
        // altogether.
        //
        // `readRows()` is one statement on the same connection as the lock,
        // inside the same transaction: what is compared is what is locked.
        $now = $this->shop->readRows($postId);
        if (!($now['read'] ?? false)) {
            $this->transaction->rollback();
            // «The read failed» and «the rows are gone» are opposite
            // sentences and `getResults()` gives them the same shape. Taken
            // for «gone», this would restore over a destination nobody had
            // looked at.
            return OperationResult::failure('minmax_rollback_unreadable', ['post_id' => $postId]);
        }
        foreach (['min', 'max', 'step'] as $part) {
            $wrote = $row['written_' . $part];
            if ($wrote === null) {
                continue;
            }
            if ((string) ($now[$part] ?? '') !== (string) $wrote) {
                $this->transaction->rollback();
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
        $problem = $this->shop->restore(
            $postId,
            $row['dest_min_before'] === null ? null : (string) $row['dest_min_before'],
            $row['dest_max_before'] === null ? null : (string) $row['dest_max_before'],
            $row['dest_step_before'] === null ? null : (string) $row['dest_step_before'],
            $record
        );
        if ($problem !== '') {
            $this->transaction->rollback();
            // The database is back where it was; the object cache is not,
            // because it never took part in the transaction.
            $this->shop->forgetCachedQuantities($postId);
            return OperationResult::failure('minmax_rollback_not_stored', [
                'post_id' => $postId,
                'part' => $problem,
            ]);
        }

        if ($status === MinMaxHandoverRepositoryInterface::ENFORCED
            && !$this->shop->revokeEnforcement($postId)) {
            $this->transaction->rollback();
            $this->shop->forgetCachedQuantities($postId);
            return OperationResult::failure('minmax_rollback_not_stored', [
                'post_id' => $postId,
                'part' => '_tmc_quantity_enforced',
            ]);
        }

        if (!$this->ledger->markRolledBack($postId)) {
            $this->transaction->rollback();
            $this->shop->forgetCachedQuantities($postId);
            // A ledger that still said «migrated» would offer the same
            // rollback again, and the second one would restore over a
            // destination that is already restored. `alpha.53` named this and
            // left the values restored anyway; now the whole unit goes back.
            return OperationResult::failure('minmax_rollback_unrecorded', ['post_id' => $postId]);
        }
        if (!$this->transaction->commit()) {
            $this->shop->forgetCachedQuantities($postId);
            return OperationResult::failure('minmax_rollback_not_committed', ['post_id' => $postId]);
        }
        return OperationResult::success('minmax_rolled_back', ['post_id' => $postId]);
    }

    /**
     * The manager's grant: this marketplace enforces THIS PRODUCT'S
     * purchase-quantity rule from now on — and nothing else changes.
     *
     * ---------------------------------------------------------------------
     * WHAT THIS IS FOR
     * ---------------------------------------------------------------------
     *
     * `alpha.53` measured the gap and `alpha.54` reported it unchanged: a
     * converted rule written onto a product the marketplace does not own is
     * three rows nothing reads, because `QuantityGuard::ruleFor()` returns
     * before reading them when `PurchaseGuard::ask()` says the product is
     * somebody else's. Measured with Min Max on and then off: a marketplace
     * product's `4|6|8|10` stayed `4|6|8|10`; a NON-marketplace product's
     * `4|6|8|10` became «any quantity allowed». That is the
     * `written_unenforced` state, and the owner's twelve products are all in
     * it.
     *
     * The grant closes it for exactly one thing. «این مجوز نباید خودبه‌خود
     * فروشنده، مالکیت محصول یا چرخهٔ تأیید آن را تغییر دهد» — so it is a meta
     * key of its own (`ProjectedQuantity::ENFORCE_META`) and NOT a change of
     * `link_ownership`: `PurchasePolicy` still answers `NOT_OURS`, so the
     * purchase guard, the storefront stop, the projector, the vendor's
     * catalogue and the approval cycle go on ignoring the product exactly as
     * they did. The one difference is that the quantity rule already written
     * on it is applied.
     *
     * ---------------------------------------------------------------------
     * AND IT IS REFUSED UNLESS THE RULE IS STILL THE ONE THAT WAS MIGRATED
     * ---------------------------------------------------------------------
     *
     * Same lock and same comparison as `rollback()`, for the same reason: a
     * grant is a decision about a specific rule, and a rule somebody has
     * changed since the migration is a different rule. Granting over it would
     * enforce numbers no manager has read — which is the shape of every
     * finding in the last three rounds.
     *
     * One unit of work: the meta key and the ledger status, or neither.
     */
    public function enforce(int $postId): OperationResult
    {
        return $this->changeGrant($postId, true);
    }

    /**
     * Withdraw the grant, leaving the three rows exactly where they are.
     *
     * The product goes back to `written_unenforced` — the rule is still
     * written and nothing reads it again — which is where it was before the
     * grant, and is NOT a rollback: the destination is untouched.
     */
    public function stopEnforcing(int $postId): OperationResult
    {
        return $this->changeGrant($postId, false);
    }

    private function changeGrant(int $postId, bool $grant): OperationResult
    {
        $verb = $grant ? 'enforce' : 'unenforce';
        if ($this->transaction === null) {
            return OperationResult::failure('minmax_' . $verb . '_no_transaction', ['post_id' => $postId]);
        }
        if ($this->transaction->inTransaction()) {
            return OperationResult::failure('minmax_' . $verb . '_caller_transaction', ['post_id' => $postId]);
        }
        if (!$this->transaction->begin()) {
            return OperationResult::failure('minmax_' . $verb . '_not_atomic', ['post_id' => $postId]);
        }

        $row = $this->ledger->find($postId);
        if ($row === null) {
            $this->transaction->rollback();
            return OperationResult::failure('minmax_' . $verb . '_nothing', ['post_id' => $postId]);
        }
        $status = (string) ($row['status'] ?? '');
        $wanted = $grant
            ? MinMaxHandoverRepositoryInterface::UNENFORCED
            : MinMaxHandoverRepositoryInterface::ENFORCED;
        if ($status !== $wanted) {
            // A `migrated` product needs no grant: the marketplace owns it,
            // so its own guard already reads the rule, and offering a grant
            // would suggest the rule was not being applied. Everything else
            // wrote nothing, so there is no rule to enforce.
            $this->transaction->rollback();
            return OperationResult::failure('minmax_' . $verb . '_not_allowed', [
                'post_id' => $postId,
                'status' => $status,
            ]);
        }

        if (!$this->shop->lockQuantityRows($postId)) {
            $this->transaction->rollback();
            return OperationResult::failure('minmax_' . $verb . '_not_locked', ['post_id' => $postId]);
        }
        $now = $this->shop->readRows($postId);
        if (!($now['read'] ?? false)) {
            $this->transaction->rollback();
            return OperationResult::failure('minmax_' . $verb . '_unreadable', ['post_id' => $postId]);
        }
        // ONLY WHEN GRANTING. A permission must always be withdrawable: if
        // the drift check ran on the way out too, a rule that changed after
        // the grant would leave the grant STUCK ON — the manager would be
        // told «the rule changed» and the marketplace would go on enforcing
        // it. Refusing to grant on drift is conservative; refusing to revoke
        // on drift is a lock on the wrong door.
        if ($grant) {
            foreach (['min', 'max', 'step'] as $part) {
                $wrote = $row['written_' . $part];
                if ($wrote === null) {
                    continue;
                }
                if ((string) ($now[$part] ?? '') !== (string) $wrote) {
                    $this->transaction->rollback();
                    // The rule on the product is not the rule that was
                    // migrated, so there is nothing here the manager has read.
                    return OperationResult::failure('minmax_' . $verb . '_changed', [
                        'post_id' => $postId,
                        'field' => $part,
                        'wrote' => (string) $wrote,
                        'now' => $now[$part] === null ? '__absent__' : (string) $now[$part],
                    ]);
                }
            }
        }

        // The grant is stored AS THE RULE IT WAS GRANTED FOR, from the rows
        // just read under the lock — not as a bare `'1'`. `alpha.49`, one
        // object down: an override is about the values that were shown, and a
        // flag would say «enforce whatever these rows hold next week».
        $stored = $grant
            ? $this->shop->grantEnforcement($postId, [
                'min' => $now['min'],
                'max' => $now['max'],
                'step' => $now['step'],
            ])
            : $this->shop->revokeEnforcement($postId);
        if (!$stored) {
            $this->transaction->rollback();
            $this->shop->forgetCachedQuantities($postId);
            return OperationResult::failure('minmax_' . $verb . '_not_stored', ['post_id' => $postId]);
        }
        $recorded = $grant
            ? $this->ledger->markEnforced($postId)
            : $this->ledger->markUnenforced($postId);
        if (!$recorded) {
            $this->transaction->rollback();
            $this->shop->forgetCachedQuantities($postId);
            // Zero rows is a lost race, not «already in that state»
            // (`alpha.49`): some other request moved this row, and writing
            // the meta key without the ledger would leave a rule enforced
            // with no record that anybody granted it.
            return OperationResult::failure('minmax_' . $verb . '_unrecorded', ['post_id' => $postId]);
        }
        if (!$this->transaction->commit()) {
            $this->shop->forgetCachedQuantities($postId);
            return OperationResult::failure('minmax_' . $verb . '_not_committed', ['post_id' => $postId]);
        }
        return OperationResult::success(
            $grant ? 'minmax_rule_enforced' : 'minmax_rule_unenforced',
            ['post_id' => $postId]
        );
    }

    /**
     * Send a refused or held product back for another decision, keeping the
     * record of why it was refused.
     *
     * The explicit retry path: a `STEP_BASE_DIFFERS` rule has no equivalent
     * here until somebody changes the rule in Min Max, and a `held_by_manager`
     * product stays held until the manager clears their own value — neither of
     * which this code can detect, so neither can be retried automatically.
     * What it CAN do is make the retry one click that leaves the history
     * intact: «سوابق را کورکورانه حذف نکن».
     *
     * Nothing was written in either state, so there is nothing to put back and
     * no transaction is needed — the single `UPDATE` carries its own guard,
     * and `migrated` is deliberately not releasable: that transition is a
     * rollback.
     */
    public function release(int $postId): OperationResult
    {
        $row = $this->ledger->find($postId);
        if ($row === null) {
            return OperationResult::failure('minmax_release_nothing', ['post_id' => $postId]);
        }
        $status = (string) ($row['status'] ?? '');
        if (!in_array($status, [
            MinMaxHandoverRepositoryInterface::REFUSED,
            MinMaxHandoverRepositoryInterface::HELD,
        ], true)) {
            return OperationResult::failure('minmax_release_not_allowed', [
                'post_id' => $postId,
                'status' => $status,
            ]);
        }
        if (!$this->ledger->release($postId)) {
            // Zero rows is a lost race, not «already released» (`alpha.49`).
            return OperationResult::failure('minmax_release_unrecorded', ['post_id' => $postId]);
        }
        return OperationResult::success('minmax_released', [
            'post_id' => $postId,
            'was' => $status,
        ]);
    }

    /**
     * Every decision ever made about one product, newest first.
     *
     * A rolled-back or released row stays, which is what makes «this product
     * was migrated, put back, and migrated again» a readable sentence instead
     * of an absence. «سوابق را کورکورانه حذف نکن» is only meaningful if
     * something reads them.
     *
     * @return list<array<string,mixed>>
     */
    /**
     * The products whose GRANT no longer describes the rule on them — a
     * purchase limit that has silently stopped applying.
     *
     * **Why this is a list and not a cell in a table.** The grant is stored
     * as the fingerprint of the three values it was given for (`alpha.55`),
     * so a rule edited afterwards leaves the grant alive but dead: the guard
     * asks `hash_equals` and answers «not granted», and from that moment the
     * product allows ANY quantity. That is correct — a permission is about
     * the values it was given for, and re-using it for whatever is there now
     * would be the mistake `alpha.49` named — but it is also a limit that has
     * gone away, and «هیچ حد خریدی بی هشدار از بین نرود» is this round's
     * instruction about exactly that.
     *
     * So the state has a count of its own, the exit page says it above the
     * table rather than only inside it, and the health screen says it to
     * somebody who has not opened the exit page at all.
     *
     * ⛔ AND IT IS NO LONGER BOUNDED AT FIFTY. The sentence this docblock used
     * to carry — «a warning that reads the whole ledger to say more than fifty
     * would cost more than the sentence is worth» — was wrong twice: the exit
     * VERDICT reads this list, so the bound decided whether a site was
     * permitted to switch the plugin off; and the cost argument was about a
     * warning, which this stopped being the moment `exitVerdict()` called it.
     * Measured on `alpha.69`'s own bytes: fifty-five live grants, the
     * fifty-fifth stale, this list empty and the verdict `permitted`. It now
     * walks every page (`staleGrantScan()`), and an unfinished walk blocks.
     *
     * @return list<int> post ids, in ledger order
     */
    /**
     * MAY MIN MAX BE DEACTIVATED? — one answer, with every blocker named.
     *
     * The owner's §5 asks for an EXPLICIT output that permits deactivating
     * the plugin on staging, and for four counts that are kept apart:
     * convertible, incompatible, migrated-but-unenforceable, and unknown.
     * Until this round those numbers existed but the judgement did not, so
     * the only way to reach it was to read `preview()` and decide — which is
     * exactly the hand-inspection `alpha.68` §4 removed from the rollback
     * question, one plugin over.
     *
     * THREE ANSWERS, not two. `not_installed` is not `permitted`: a reader
     * that cannot see the plugin cannot say its rules are all dealt with, and
     * answering «yes, go ahead» from a failed read is the shape this
     * repository has paid for twice (`alpha.49`, `alpha.68` §1).
     *
     * ⛔ NOTHING HERE LOOSENS A RULE TO REACH A YES. An incompatible rule —
     * a step counted from the minimum, a window with no reachable multiple —
     * is a BLOCKER, and the only things that clear it are a human changing
     * the rule at the source or deciding to lose it. «هیچ قاعده‌ای برای
     * تمام‌کردن انتقال خودکار سست یا حذف نشود.»
     *
     * And every blocker is about a rule that would STOP BEING ENFORCED the
     * moment the plugin is switched off:
     *
     *   convertible_rules_not_migrated  a live Min Max rule we have not taken
     *   incompatible_rules_present      …that we cannot take without changing it
     *   rules_held_by_manager           …whose destination the manager owns
     *   migrated_but_not_enforced       taken, written, and read by nobody
     *   rule_would_not_be_enforced      not ours, so taking it would change nothing
     *   stale_enforcement_grants        a grant whose rule has since changed
     *   rolled_back_rules_present       given BACK to Min Max on purpose
     *   source_rows_unclassified        rows this scan did not reach
     *   scan_did_not_finish             the walk stopped before the list ended
     *   migrated_rule_differs_now       taken once, and not the same rule today
     *   migrated_rule_not_enforced_now  taken, and nothing would read it now
     *   decided_destination_unreadable  a decided product's rows did not read
     *   migrated_rule_equality_unprovable  a number no column here can hold
     *   global_rule_has_no_destination  a site-wide limit with nowhere to go
     *   term_rules_have_no_destination  category limits with nowhere to go
     *   grant_scan_did_not_finish       the grants were not all examined
     *   ledger_tally_unreadable         the live counts could not be read
     *
     * FIVE OF THOSE ARE THIS ROUND'S, and each of the three findings behind
     * them was a `permitted` on `alpha.69`'s own bytes:
     *
     *  - `incompatible_rules_present` and `rules_held_by_manager` now count
     *    the LIVE LEDGER STATES as well as the undecided ones. `preview()`
     *    scored every product with a ledger row as `already_decided` and
     *    skipped it, so ATTEMPTING the migration of the site's only
     *    incompatible rule — which writes nothing and changes nothing Min Max
     *    enforces — turned `blocked` into `permitted`. A RELEASED row is
     *    history and still does not block, which `tally()` keeps apart.
     *  - `migrated_rule_differs_now` / `migrated_rule_not_enforced_now`: a
     *    `migrated` or `rule_enforced` row is a record, not a present
     *    equality. See `classifyDecided()`.
     *  - `migrated_rule_equality_unprovable` is `alpha.71`'s, and it exists
     *    because that equality is now DECIDED rather than sampled: a rule
     *    carrying a quantity outside signed-32-bit has no comparable set, and
     *    saying so is not the same as saying the two sides agree.
     *  - `grant_scan_did_not_finish`: see `staleGrantScan()`.
     *  - `global_rule_has_no_destination` / `term_rules_have_no_destination`:
     *    the migration is per product, so these two levels cannot be taken at
     *    all and deactivation drops them. `alpha.69` described them in the
     *    preview and blocked on neither.
     *
     * `written_unenforced` is the owner's third count. It is a legitimate end
     * state — the rule is ours and our guard does not enforce it, because the
     * product is not the marketplace's — and it still blocks, because
     * switching the plugin off drops a purchase limit that is live today.
     * Clearing it is a decision (grant enforcement, or accept the loss), not
     * a calculation.
     *
     * Writes nothing: this is a question, and a question with a side effect
     * is a decision taken early.
     *
     * @return array<string,mixed>
     */
    public function exitVerdict(int $maxProducts = 20000): array
    {
        $preview = $this->preview($maxProducts);
        $tally = $this->ledger->tally();
        $out = [
            'verdict' => 'blocked',
            'permitted' => false,
            'blockers' => [],
            'counts' => [
                'convertible' => 0,
                'incompatible' => 0,
                'migrated_unenforceable' => 0,
                'would_be_unenforceable' => 0,
                'unknown' => 0,
                'held_by_manager' => 0,
                'already_decided' => 0,
                'rolled_back' => 0,
                'source_rows' => 0,
                'scanned' => 0,
                'decided_in_force' => 0,
                'decided_drifted' => 0,
                'decided_unenforced' => 0,
                'decided_unreadable' => 0,
                'decided_unprovable' => 0,
                'refused_recorded' => 0,
                'held_recorded' => 0,
                'grants_live' => 0,
                'grants_scanned' => 0,
                'global_rules' => 0,
                'term_rules' => 0,
            ],
            'levels' => ['product' => 0, 'variation' => 0],
            'problems' => [],
            'stale_grants' => [],
            'grant_scan' => [],
            'tally' => $tally,
        ];
        if (($preview['available'] ?? false) !== true) {
            $out['verdict'] = 'not_installed';
            $out['blockers'][] = [
                'code' => 'source_unreadable',
                'count' => 0,
                'detail' => 'min max control is not active here, so its rules cannot be counted',
            ];
            return $out;
        }

        $counts = (array) ($preview['counts'] ?? []);
        $sourceRows = (int) ($counts['product'] ?? 0) + (int) ($counts['variation'] ?? 0);
        $scanned = (int) ($preview['scanned'] ?? 0);
        // EVERY PRODUCT, including the ones outside this marketplace and
        // every variation: the scan is bounded, so what it did not reach is
        // `unknown` and is said rather than left out of the arithmetic.
        $unknown = max(0, $sourceRows - $scanned);
        $unenforceable = (int) ($tally[MinMaxHandoverRepositoryInterface::UNENFORCED] ?? 0);
        $rolledBack = (int) ($tally[MinMaxHandoverRepositoryInterface::ROLLED_BACK] ?? 0);
        // THE LIVE DECISIONS, FROM THE LEDGER'S OWN LIVE COUNTS. `tally()`
        // keys a released row as `<status>_released`, so these two are live
        // and a row the manager has already sent back for a retry is not
        // mixed in with them — «سوابق آزادشده با تصمیم زنده قاطی نشوند».
        //
        // The ledger is the authority rather than the walk, because a live
        // refusal whose SOURCE RULE has since been deleted is invisible to
        // the walk and still holds a product out of the migration until a
        // human releases it.
        $refusedRecorded = (int) ($tally[MinMaxHandoverRepositoryInterface::REFUSED] ?? 0);
        $heldRecorded = (int) ($tally[MinMaxHandoverRepositoryInterface::HELD] ?? 0);
        $scan = $this->staleGrantScan();
        $stale = (array) $scan['stale'];

        $out['counts'] = [
            'convertible' => (int) ($preview['convertible'] ?? 0),
            // Undecided plus RECORDED-AND-LIVE, under one name because the
            // remedy is one: change the rule at the source, or decide to lose
            // it. `alpha.69` counted only the first half, so ATTEMPTING the
            // migration of the site's only incompatible rule turned `blocked`
            // into `permitted` while Min Max went on enforcing it.
            'incompatible' => (int) ($preview['refused'] ?? 0) + $refusedRecorded,
            'migrated_unenforceable' => $unenforceable,
            'would_be_unenforceable' => (int) ($preview['unenforced'] ?? 0),
            // «نمی‌دانم» in all three of its shapes: rows the scan never
            // reached, a decided product whose destination would not read, and
            // one whose numbers put equality out of reach.
            'unknown' => $unknown
                + (int) ($preview['decided_unreadable'] ?? 0)
                + (int) ($preview['decided_unprovable'] ?? 0),
            'held_by_manager' => (int) ($preview['held_by_manager'] ?? 0) + $heldRecorded,
            'already_decided' => (int) ($preview['already_decided'] ?? 0),
            'rolled_back' => $rolledBack,
            'source_rows' => $sourceRows,
            'scanned' => $scanned,
            // The decided products taken apart, each with its own remedy.
            'decided_in_force' => (int) ($preview['decided_in_force'] ?? 0),
            'decided_drifted' => (int) ($preview['decided_drifted'] ?? 0),
            'decided_unenforced' => (int) ($preview['decided_unenforced'] ?? 0),
            'decided_unreadable' => (int) ($preview['decided_unreadable'] ?? 0),
            'decided_unprovable' => (int) ($preview['decided_unprovable'] ?? 0),
            'refused_recorded' => $refusedRecorded,
            'held_recorded' => $heldRecorded,
            'grants_live' => (int) $scan['live'],
            'grants_scanned' => (int) $scan['scanned'],
            'global_rules' => 0,
            'term_rules' => 0,
        ];
        $out['levels'] = (array) ($preview['levels'] ?? []);
        $out['problems'] = (array) ($preview['problems'] ?? []);
        $out['stale_grants'] = $stale;
        $out['grant_scan'] = $scan;

        // THE LEVELS THIS MIGRATION CANNOT TAKE AT ALL. The migration is per
        // product; a global or term rule has no destination here, so switching
        // the plugin off drops it from every product that has no rule of its
        // own. `alpha.69` described them in the preview and blocked on
        // neither — measured: a live global rule of `3/9/3` with the verdict
        // reading `permitted` and no blocker named.
        $global = (array) ($preview['global'] ?? []);
        if ($global !== [] && ($global['answers'] ?? true) && ($global['unrestricted'] ?? false) !== true) {
            $out['counts']['global_rules'] = 1;
        }
        foreach ((array) ($preview['terms'] ?? []) as $term) {
            $term = (array) $term;
            if (($term['answers'] ?? true) && ($term['unrestricted'] ?? false) !== true) {
                $out['counts']['term_rules']++;
            }
        }

        $blocker = static function (string $code, int $count, string $detail): array {
            return ['code' => $code, 'count' => $count, 'detail' => $detail];
        };
        if ($unknown > 0) {
            $out['blockers'][] = $blocker(
                'source_rows_unclassified',
                $unknown,
                'the scan stopped before every source row was classified; raise the bound and ask again'
            );
        }
        if (($preview['walk_finished'] ?? false) !== true) {
            // SEPARATE FROM THE COUNT ABOVE. The walk can stop without the
            // two numbers disagreeing — a page that reports no progress, or
            // the bound reached on a page boundary — and «I did not reach
            // the end» is a fact about the scan, not about the rules.
            $out['blockers'][] = $blocker(
                'scan_did_not_finish',
                $scanned,
                'the walk stopped before the source list ended, so nothing here is a complete answer'
            );
        }
        if ($out['counts']['convertible'] > 0) {
            $out['blockers'][] = $blocker(
                'convertible_rules_not_migrated',
                $out['counts']['convertible'],
                'these rules convert exactly and have not been taken yet'
            );
        }
        if ($out['counts']['incompatible'] > 0) {
            $out['blockers'][] = $blocker(
                'incompatible_rules_present',
                $out['counts']['incompatible'],
                'these rules cannot be taken without changing what they allow ('
                    . $refusedRecorded . ' of them already recorded as refused, and a recorded '
                    . 'refusal changes nothing about what min max still enforces): '
                    . implode(', ', array_keys($out['problems']))
            );
        }
        if ($out['counts']['held_by_manager'] > 0) {
            $out['blockers'][] = $blocker(
                'rules_held_by_manager',
                $out['counts']['held_by_manager'],
                'the destination rows belong to a manager edit; taking them needs an explicit '
                    . 'permission (' . $heldRecorded . ' of them already recorded as held)'
            );
        }
        if ($out['counts']['migrated_unenforceable'] > 0) {
            $out['blockers'][] = $blocker(
                'migrated_but_not_enforced',
                $out['counts']['migrated_unenforceable'],
                'the rule is written here and nothing reads it, so deactivating would drop a live limit'
            );
        }
        if ($out['counts']['would_be_unenforceable'] > 0) {
            $out['blockers'][] = $blocker(
                'rule_would_not_be_enforced',
                $out['counts']['would_be_unenforceable'],
                'this product is not the marketplace\'s, so taking its rule would write where nothing reads'
            );
        }
        if ($out['counts']['decided_drifted'] > 0) {
            // ⛔ THE SECOND FINDING. The rule was taken, and what min max
            // enforces today is not what this marketplace would enforce
            // tomorrow. Nothing is rewritten: the manager decides whether to
            // roll the product back, migrate it again, or accept the change.
            $out['blockers'][] = $blocker(
                'migrated_rule_differs_now',
                $out['counts']['decided_drifted'],
                'the rule was migrated and the two sides no longer allow the same quantities, '
                    . 'so switching the plugin off would change what shoppers may buy'
            );
        }
        if ($out['counts']['decided_unenforced'] > 0) {
            $out['blockers'][] = $blocker(
                'migrated_rule_not_enforced_now',
                $out['counts']['decided_unenforced'],
                'the rule is written here and this product is no longer the marketplace\'s and '
                    . 'carries no enforcement grant, so after deactivation nothing would limit it'
            );
        }
        if ($out['counts']['decided_unreadable'] > 0) {
            $out['blockers'][] = $blocker(
                'decided_destination_unreadable',
                $out['counts']['decided_unreadable'],
                'the destination rows of a decided product could not be read, and a read that '
                    . 'failed is not an equality'
            );
        }
        if ($out['counts']['decided_unprovable'] > 0) {
            $out['blockers'][] = $blocker(
                'migrated_rule_equality_unprovable',
                $out['counts']['decided_unprovable'],
                'a decided product\'s rule holds a quantity outside what these columns can store, '
                    . 'so whether the two sides allow the same quantities cannot be decided'
            );
        }
        if ($out['counts']['global_rules'] > 0) {
            $out['blockers'][] = $blocker(
                'global_rule_has_no_destination',
                $out['counts']['global_rules'],
                'min max carries a global rule and this marketplace has no global purchase '
                    . 'limit, so deactivation drops it from every product with no rule of its own'
            );
        }
        if ($out['counts']['term_rules'] > 0) {
            $out['blockers'][] = $blocker(
                'term_rules_have_no_destination',
                $out['counts']['term_rules'],
                'min max carries category rules and this marketplace holds limits per product '
                    . 'only, so deactivation drops them'
            );
        }
        if (($scan['finished'] ?? false) !== true) {
            // ⛔ THE THIRD FINDING, and it is a blocker rather than a warning:
            // a scan that did not reach the end cannot say there is no stale
            // grant, and `getResults()` answers the same thing for «no rows»
            // and «the read failed».
            $out['blockers'][] = $blocker(
                'grant_scan_did_not_finish',
                (int) $scan['scanned'],
                'the enforcement grants were not all examined (' . (int) $scan['scanned']
                    . ' of ' . (int) $scan['expected'] . ' rows), so «no stale grant» is not an answer'
            );
        }
        if ($tally === [] && $out['counts']['already_decided'] > 0) {
            // The ledger's own counts are what the live decisions above are
            // read from, and `tally()` cannot tell «no rows» from «the read
            // failed» either. This is the cross-check that can: the walk
            // reached ledger rows through a different statement, so an empty
            // tally beside a non-zero `already_decided` is a failed read.
            $out['blockers'][] = $blocker(
                'ledger_tally_unreadable',
                $out['counts']['already_decided'],
                'the ledger counted no rows while the walk found decided products, so the '
                    . 'tally read failed and the live decisions cannot be counted'
            );
        }
        if ($stale !== []) {
            $out['blockers'][] = $blocker(
                'stale_enforcement_grants',
                count($stale),
                'an enforcement grant describes values the rule no longer has'
            );
        }
        if ($rolledBack > 0) {
            $out['blockers'][] = $blocker(
                'rolled_back_rules_present',
                $rolledBack,
                'these rules were given back to min max on purpose and are enforced by it today'
            );
        }
        if ($out['blockers'] === []) {
            $out['verdict'] = 'permitted';
            $out['permitted'] = true;
        }
        return $out;
    }

    public function staleGrants(int $limit = 200): array
    {
        return $this->staleGrantScan($limit)['stale'];
    }

    /**
     * EVERY granted product, in pages, and whether the walk actually finished.
     *
     * `alpha.69` read ONE page of fifty and the exit verdict called it with
     * that default, so the fifty-first grant onwards was never examined —
     * measured on its own bytes: fifty-five live grants, the fifty-fifth one
     * stale, `staleGrants()` returning an empty list and the verdict saying
     * `permitted`. The same function with a wider bound found it, which is
     * what makes the bound the defect rather than the comparison.
     *
     * Keyset, on the `afterId` cursor the repository already had, for the
     * reason the rest of this module uses keyset: a walk over a set somebody
     * else is changing skips rows silently with OFFSET.
     *
     * ⛔ AND «I REACHED THE END» IS NOT TAKEN FROM AN EMPTY PAGE.
     * `withStatus()` goes through `getResults()`, which answers `[]` both for
     * «no more rows» and «the statement failed» (`alpha.8`), and here the two
     * are opposites: a failed first page would look like «no grants at all»
     * and permit the exit. So the rows SEEN are counted against the ledger's
     * own total for that status, and `finished` is false unless the walk saw
     * at least as many as the ledger says exist. An unfinished scan is a
     * BLOCKER, never a permission.
     *
     * @return array{stale:list<int>, scanned:int, live:int, expected:int, finished:bool, pages:int}
     */
    public function staleGrantScan(int $page = 200): array
    {
        $page = max(1, min(1000, $page));
        $tally = $this->ledger->tally();
        $expected = (int) ($tally[MinMaxHandoverRepositoryInterface::ENFORCED] ?? 0)
            + (int) ($tally[MinMaxHandoverRepositoryInterface::ENFORCED . '_released'] ?? 0);
        $stale = [];
        $scanned = 0;
        $live = 0;
        $pages = 0;
        $after = 0;
        $finished = false;
        // A bound on the WALK, not on the answer: ten thousand pages is more
        // grants than a catalogue has, and a loop with no bound is the other
        // way to never finish.
        while ($pages < 10000) {
            $rows = $this->ledger->withStatus([MinMaxHandoverRepositoryInterface::ENFORCED], $page, $after);
            $pages++;
            foreach ($rows as $row) {
                $scanned++;
                $after = max($after, (int) $row['id']);
                // Only LIVE rows: a released or rolled-back one has no grant
                // left to be stale, and warning about it would be a warning
                // about a decision somebody already took back.
                if (($row['live_key'] ?? null) === null) {
                    continue;
                }
                $live++;
                $postId = (int) $row['post_id'];
                if (!$this->shop->enforcementGranted($postId)) {
                    $stale[] = $postId;
                }
            }
            if (count($rows) < $page) {
                $finished = true;
                break;
            }
        }
        return [
            'stale' => $stale,
            'scanned' => $scanned,
            'live' => $live,
            'expected' => $expected,
            // Both halves, and the second one is what makes a failed read
            // visible: the walk said it was done AND it saw every row the
            // ledger counts.
            'finished' => $finished && $scanned >= $expected,
            'pages' => $pages,
        ];
    }

    public function historyFor(int $postId, int $limit = 20): array
    {
        return $this->ledger->history($postId, $limit);
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
            // Whether this level ANSWERS at all, by the plugin's own test. A
            // global or term level that answers and restricts something is a
            // live purchase limit with NO destination here, and the exit
            // verdict has to name it rather than leave it out of the sum.
            'answers' => $rule->answers(),
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
        $allowed = $rule->allowedUpTo(self::ILLUSTRATE_TO);
        return [
            'post_id' => (int) $row['product_id'],
            'type' => (string) $row['type'],
            'status' => $status,
            'problems' => $problems,
            'rule' => $this->describe($rule),
            'allowed_by_minmax' => array_slice($allowed['source'], 0, 12),
            'allowed_after_conversion' => array_slice($allowed['converted'], 0, 12),
            // DECIDED, not read off the two lists above: those stop at
            // `ILLUSTRATE_TO` and two rules that differ only past it would
            // read as one. `null` when the question cannot be decided at all.
            'same_set' => $rule->allowedSet()->known() && $rule->convertedSet()->known()
                ? $rule->allowedSet()->equals($rule->convertedSet())
                : null,
            'minmax_set' => $rule->allowedSet()->describe(),
            'converted_set' => $rule->convertedSet()->describe(),
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
            'destination_duplicated' =>
                'مقصد برای یک کلید بیش از یک ردیف دارد (postmeta ایندکس یکتا ندارد) — '
                . 'پیش از انتقال باید ردیف تکراری پاک شود',
        ];
    }

    /** The converted rule for one source rule, for tools and tests. */
    public static function convert(MinMaxRule $rule): ?PurchaseQuantity
    {
        return $rule->convertible() ? $rule->converted() : null;
    }
}
