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
        foreach (['min', 'max', 'step'] as $part) {
            $wrote = $row['written_' . $part];
            if ($wrote === null) {
                continue;
            }
            if ((string) ($now[$part] ?? '') !== (string) $wrote) {
                $this->transaction->rollback();
                // The rule on the product is not the rule that was migrated,
                // so there is nothing here the manager has read.
                return OperationResult::failure('minmax_' . $verb . '_changed', [
                    'post_id' => $postId,
                    'field' => $part,
                    'wrote' => (string) $wrote,
                    'now' => $now[$part] === null ? '__absent__' : (string) $now[$part],
                ]);
            }
        }

        $stored = $grant
            ? $this->shop->grantEnforcement($postId)
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
