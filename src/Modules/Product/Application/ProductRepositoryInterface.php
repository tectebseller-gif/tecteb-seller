<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Modules\Product\Domain\ApprovedBaseline;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\ProductCreation;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductSeo;
use Tecteb\Marketplace\Modules\Product\Domain\ProductSort;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;

/**
 * Product persistence.
 *
 * Every read that a vendor can reach takes the owner as an argument rather
 * than trusting the caller to filter afterwards: the query itself is scoped,
 * so a forgotten `if` cannot leak another shop's catalogue (AC-PRIV).
 */
interface ProductRepositoryInterface
{
    public function find(int $productId): ?Product;

    /** The same row, but only if it belongs to this vendor. */
    public function findOwned(int $productId, int $vendorUserId): ?Product;
    /**
     * These products, in ONE read, keyed by id.
     *
     * For a page whose row identities came from somewhere else — from
     * `alpha.56` the unified catalogue list is a union over WooCommerce posts
     * and marketplace rows, so the marketplace half of a page arrives as a
     * list of ids. `find()` per row would be twenty statements for one
     * screen, which is the cost this list was rebuilt to put a ceiling on.
     *
     * An id with no row is simply absent from the answer: a row that is not
     * there is not an error here, it is half of a pair.
     *
     * @param list<int> $productIds
     * @return array<int,Product>
     */
    public function findMany(array $productIds): array;


    /**
     * @param string $search matched against title and SKU; empty means no filter
     * @return list<Product>
     */
    public function forVendor(
        int $vendorUserId,
        ?ProductStatus $status = null,
        int $limit = 200,
        int $offset = 0,
        string $search = ''
    ): array;

    public function countForVendor(int $vendorUserId, ?ProductStatus $status = null, string $search = ''): int;

    /** @return array<string,int> status value => count, for the vendor's filter chips */
    public function countsByStatus(int $vendorUserId): array;

    /** @return list<Product> the manager's review queue, newest request first */
    public function inStatus(ProductStatus $status, int $limit = 200, int $offset = 0): array;

    /**
     * The manager's catalogue — every shop, every status, searchable.
     *
     * `inStatus(Submitted)` was the only manager-facing read, so a product
     * that had been decided any way at all disappeared from the marketplace
     * admin while its WooCommerce post carried on existing. There is no new
     * status here and no second copy of one: the marketplace status is the
     * column it has always been, and the shop's own status is READ from
     * WordPress at render time rather than mirrored into a column that could
     * then be wrong.
     *
     * **The limit is in the query.** `$limit`/`$offset` are a real `LIMIT` and
     * a real `OFFSET`, so a catalogue of two hundred products reads twenty
     * rows; a page that fetched everything and hid the rest with CSS would
     * still cost every row, every image lookup and every store name.
     *
     * @param ?ProductSort $sort                null means the default order
     * @param bool         $onlyNeedsReview narrow the list to products that
     *                                      NEED REVIEW, by the one definition
     *                                      `ReviewQueueSql::waiting()` holds:
     *                                      submitted, or carrying an
     *                                      unanswered change proposal, or
     *                                      carrying an open brand request.
     *                                      The badge, the counter, this filter
     *                                      and its pager all read that one
     *                                      fragment, so they cannot describe
     *                                      different sets — which is exactly
     *                                      what they did until `alpha.48`.
     * @return list<Product>
     */
    public function forManager(
        ?ProductStatus $status = null,
        string $search = '',
        int $limit = 20,
        int $offset = 0,
        int $vendorUserId = 0,
        ?ProductSort $sort = null,
        bool $onlyNeedsReview = false
    ): array;

    public function countForManager(
        ?ProductStatus $status = null,
        string $search = '',
        int $vendorUserId = 0,
        bool $onlyNeedsReview = false
    ): int;

    /**
     * The same page of rows, each with the identity of the submission on it.
     *
     * **One statement, and that is the whole point.** The red count on the menu
     * is per manager and cleared by opening the list, so the page has to record
     * WHICH submission it showed. Read in a second query, that token could be
     * newer than the row above it — a vendor submitting between the two reads
     * would have their submission counted as seen by somebody who was looking at
     * the previous one. Selected as part of the row, there is no «between».
     *
     * The token names a submission out of the two APPEND-ONLY trails that
     * already record one: the newest `submitted` row of the decision trail, and
     * the id of the unanswered proposal. It is deliberately not the status and
     * not `updated_at` — an edited price must not look like a new submission,
     * and a resubmission of an unchanged product must.
     *
     * @return list<array{product:Product, submission:string}>
     */
    public function forManagerWithSubmission(
        ?ProductStatus $status = null,
        string $search = '',
        int $limit = 20,
        int $offset = 0,
        int $vendorUserId = 0,
        ?ProductSort $sort = null,
        bool $onlyNeedsReview = false
    ): array;

    /**
     * One product, its pending proposal and its submission identity — together.
     *
     * The detail page's half of the one-statement rule. Reading the product, the
     * proposal and the token separately leaves a window: a submission arriving
     * between the first read and the last lets the page show the previous
     * content while recording the newer identity as seen. `revisionId` names the
     * exact proposal the identity refers to, so the caller can load that
     * proposal BY ID rather than asking again for «the pending one».
     *
     * @return array{product:Product, submission:string, revisionId:int, waiting:bool}|null
     */
    public function findWithSubmission(int $productId): ?array;

    /**
     * Which of these products are waiting, and under which submission.
     *
     * The badge's second read: the input is what one manager has already looked
     * at, so the query is bounded by that rather than by the size of the queue.
     * A product that has LEFT the queue is absent from the answer, which is how
     * a decided product stops subtracting from a count it is no longer part of.
     *
     * @param list<int> $productIds
     * @return array<int,string> product id => submission token
     */
    public function submissionsOf(array $productIds): array;

    /**
     * How many products are in each status, under the same filter.
     *
     * One GROUP BY, so no two numbers on the page can come from two moments.
     *
     * @return array<string,int>
     */
    /**
     * How many products are waiting for the MANAGER, counted as products.
     *
     * A product waits when it has been submitted, or when the version live in
     * the shop has a proposed change nobody has answered. Both are one
     * decision on one product, so the count is of products and not of rows: a
     * submitted product that also carries a proposal is one thing to look at,
     * not two, and the badge that shows this number must not say otherwise.
     *
     * Drafts are not counted. A draft is the vendor's own work in progress and
     * has not been handed to anybody.
     */
    public function countAwaitingReview(): int;

    public function countsByStatusForManager(
        string $search = '',
        int $vendorUserId = 0,
        bool $onlyNeedsReview = false
    ): array;

    public function countInStatus(ProductStatus $status): int;

    /** @return int the new product id, or 0 when the insert failed */
    /**
     * @param LinkOwnership $ownership whether this row will OWN the storefront
     *        product it is later linked to. Everything the marketplace's own
     *        flow creates is `Marketplace`; only a migration writes `Observed`.
     */
    /**
     * Create one product row — whole, in one statement.
     *
     * `$wcProductId` and `$importRunId` are parameters rather than follow-up
     * writes because a row that is half made is a row a resumed import cannot
     * recognise. See the implementation for the duplicate this prevents.
     *
     * `$mirroredStatus` is a parameter for the SAME reason, and the reason is
     * sharper here: it is what stops the first projection overwriting a
     * storefront status nobody in this marketplace decided, so a row that
     * exists without it for even one request is a row whose status the next
     * projection destroys. `''` means «not observed» — the answer for
     * everything the marketplace's own flow creates — and only an intake of
     * an existing WooCommerce product passes a value.
     */
    public function create(
        int $vendorUserId,
        ProductDetails $details,
        ProductStatus $status,
        LinkOwnership $ownership = LinkOwnership::Marketplace,
        ?int $wcProductId = null,
        string $importRunId = '',
        string $createToken = '',
        string $mirroredStatus = ''
    ): int;

    /**
     * The same insert, for the form path, saying whether THIS call made the row.
     *
     * An id alone cannot carry it. Two requests holding one `create_token`
     * both get past the «already made?» read, the unique key refuses one of
     * them, and the loser resolves to the winner's row — holding the same
     * integer the winner holds, with no way to tell that it did not create
     * it. It then wrote the gallery and the specifications over that row,
     * which on a replayed POST means over whatever has been edited since.
     *
     * `ProductCreation::$created` is what the caller hangs «write nothing to a
     * row I did not create» on.
     */
    public function createForForm(
        int $vendorUserId,
        ProductDetails $details,
        ProductStatus $status,
        string $createToken
    ): ProductCreation;

    /**
     * The id one submitted create form produced, or 0.
     *
     * `$createToken` is the one-time value a rendered create form carries, and
     * the products table has a unique key over `(vendor_user_id, create_token)`
     * so one form can only ever have made one product. A replayed POST — what
     * a browser sends after a request times out — therefore asks this instead
     * of creating a second row, and a deliberately-identical second product
     * comes from a freshly rendered form with a fresh token.
     */
    public function findByCreateToken(int $vendorUserId, string $createToken): int;

    /**
     * Write the details, refusing when `$expectedVersion` no longer matches.
     *
     * **A token this cannot compare is refused, not waived.** Pass the row's
     * counter, or `ProductRowVersion::UNGUARDED` to say in words that this
     * write is deliberately unchecked. An empty string is neither and returns
     * false — an omission must not become a silent unguarded write.
     *
     * The version has to reach the WHERE clause; an implementation that
     * compares it in PHP and then writes has reintroduced the race this
     * parameter exists to close. An empty string means «do not check», which is
     * what a form from an older build sends.
     */
    public function updateDetails(int $productId, ProductDetails $details, string $expectedVersion = ''): bool;

    /**
     * Take the version, atomically, without changing anything else.
     *
     * For the paths that do not write details — proposing a revision of a
     * published product, for instance — but still must not act on a view of the
     * product somebody else has already replaced. One statement, so exactly one
     * of two concurrent callers wins. An empty expectation succeeds: there is
     * nothing to compare against.
     */
    public function bumpVersion(int $productId, string $expectedVersion): bool;

    /** The row's current optimistic-lock counter, as the form should carry it. */
    public function rowVersion(int $productId): string;

    /**
     * Mark a row as created by an import run.
     *
     * The stamp lives on the row rather than in a manifest beside it, so a
     * process killed mid-import leaves nothing that no run claims.
     */
    public function stampImportRun(int $productId, string $runId): bool;

    /**
     * Remove one OBSERVED row that a named intake run created — and only such
     * a row.
     *
     * Every condition is in the `WHERE`, so this cannot be talked into
     * deleting anything else: the id, the run that stamped it, and
     * `link_ownership = observed`. A row somebody has since accepted into
     * marketplace management is not an intake's any more and is left standing
     * (the caller reports it); a row from another run is another run's to
     * undo.
     *
     * It deletes the marketplace row and the specification and image rows
     * that belong to it, and NOTHING in WooCommerce: not the product, not an
     * attachment, not an order line. It could not — this repository's only
     * tables are the marketplace's own, which is what makes «بازگشت نباید
     * محصول ووکامرس، رسانه یا سفارش را حذف کند» a property rather than a
     * promise.
     *
     * Unlike `deleteDraft()` it does not require the row to be a draft: an
     * intake mirrors WooCommerce's publish state, so most intaken rows are
     * `published` and a draft-only delete would leave every one of them
     * behind.
     *
     * @return string `''` when the row and every row of it went; otherwise
     *                the part that would not — `product_row`, `not_this_run`,
     *                `revisions`, `decisions`, `specs` or `images`. A `bool`
     *                could not say that, and `alpha.55` proved the cost:
     *                specs and images were deleted with their answers thrown
     *                away, so a half-emptied row reported success and a
     *                rollback that had kept a product reported it removed.
     *                The caller wraps the call in a transaction, so a named
     *                part is a whole unit undone rather than a deleted
     *                product with its images still in the table.
     */
    public function deleteObservedFromRun(int $productId, string $runId): string;

    /**
     * The OBSERVED rows an intake made, bounded — so «which intakes are half
     * written» is one statement rather than a walk over every run.
     *
     * Observed only, because a row somebody has accepted is not an intake's
     * any more; and stamped only, because a row with no run behind it was
     * not made by an intake at all.
     *
     * @return list<int>
     */
    public function observedFromImportRuns(int $limit = 200): array;

    /**
     * @return list<string> every import run the catalogue remembers, newest first
     *
     * Read from the rows, so a run whose process died before it could write a
     * manifest entry still appears — which is the whole point of the stamp.
     */
    public function importRunIds(): array;

    /** @return list<int> the products this run created, read from the rows */
    public function idsFromImportRun(string $runId): array;

    /**
     * The imported rows that are still waiting for a category — bounded, and
     * counted separately so an incomplete page says so.
     *
     * This is the half of «نگاشت دسته‌ها» that is readable on OUR side with
     * nothing else installed. An imported product whose source category
     * nobody had mapped was created with `category_key = ''` (see
     * `CategoryMap`), and that empty string is a fact in this table: it
     * survives Dokan being switched off, it is what keeps the product a
     * draft, and it is the only thing that changes when somebody finally
     * applies a mapping.
     *
     * ⛔ Asking Dokan instead — which is what `MigrationCompleteness` did
     * until `alpha.80` — answers a different question. Saving a mapping
     * empties Dokan's «unmapped» list immediately, while every row here
     * still carries the empty category; the gate went green and 184 products
     * stayed uncategorised. «صرف ذخیرهٔ option و سبز شدن وضعیت کافی نیست.»
     *
     * Stamped only (`import_run_id <> ''`): a row nobody imported is the
     * vendor's own draft and its empty category is the vendor's to fill on
     * the form, not a migration decision.
     *
     * @return list<array{id:int, vendor_user_id:int, wc_product_id:int, title:string}>
     */
    public function importedWithoutCategory(int $limit = 500): array;

    /**
     * How many of them there are — the whole number, with no `LIMIT`.
     *
     * A page that hit its cap cannot say «این‌همه مانده», and a report built
     * from `count($page)` is the `alpha.76` counter all over again. Pass a
     * vendor to scope it to one shop, which is how the completeness gate asks.
     *
     * ⛔ `-1` means the read failed. Nought here would read as «nothing left
     * to decide» and close a gate on a statement that never ran.
     */
    public function countImportedWithoutCategory(int $vendorUserId = 0): int;

    /**
     * Write a category onto a product THAT HAS NONE — the condition inside the
     * statement, never around it.
     *
     * `alpha.50`'s rule, one column over: a read that answers «this row has no
     * category» and then an unconditional write is two statements with a
     * window between them, and in that window a manager can have set the
     * category by hand on the product page. So `category_key = ''` is in the
     * `WHERE`, and what this method cannot do is overwrite a category anybody
     * chose — the marketplace's own included.
     *
     * Only that one column moves. Not the status, not the ownership, not the
     * price, not the stock, not the brand — which is why this exists instead
     * of `updateDetails()`: that one rewrites every detail column from
     * whatever the caller read a moment ago.
     *
     * @return string `''` when it was written; otherwise why not —
     *                `already_set` (a category is there and is not ours to
     *                replace), `not_found` (no such row), `bad_category`
     *                (an empty key is not a category), `not_imported` (the
     *                row exists and no import made it, so no migration
     *                decision reaches it) or `storage_failed`. A `bool` would
     *                fuse the first two, and they are opposite answers: one
     *                is «somebody decided» and the other is «there is nothing
     *                here».
     */
    /**
     * Every imported row with the category key it currently carries.
     *
     * One read for BOTH populations, on purpose. `importedWithoutCategory()`
     * answers about rows whose key is empty — the 184 that came from Dokan
     * with nothing mapped. A product taken from the EXISTING WooCommerce
     * catalogue is not in that list: `ImportExistingProducts` stored
     * `category_ids[0]`, so its key is already set, and measured on
     * `alpha.80`'s bytes the whole waiting list was therefore empty
     * (`waiting_total=0`) while three real relationships sat unrecorded.
     *
     * ⛔ Two reads, one per population, would be two code paths answering one
     * question — and `alpha.79` is the round where two halves derived from the
     * same tables agreed until the first short read, with no test going red.
     * So the key comes back as DATA and one rule classifies it.
     *
     * Keyset pagination on `id`, because the caller walks the whole catalogue
     * and `OFFSET` on a moving table skips rows (`alpha.13`).
     *
     * @return list<array{id:int, vendor_user_id:int, wc_product_id:int, title:string, category_key:string}>
     */
    public function importedWithCategoryKeys(int $limit = 500, int $afterId = 0): array;

    /**
     * How many imported rows there are at all — no `LIMIT`, no filter.
     *
     * ⛔ `-1` means the read failed, for the same reason as
     * `countImportedWithoutCategory()`: nought would read as «there is
     * nothing to look at» and let a preview call itself complete
     * (`alpha.70`).
     */
    public function countImported(): int;

    public function setCategoryWhenEmpty(int $productId, string $categoryKey): string;

    /**
     * Every `product_cat` term recorded for this product, or `null`.
     *
     * THREE answers, and the third is the point: `null` means the question
     * could not be asked — the link table is not there yet (schema 34), or the
     * read failed — and a caller must then fall back to the single key rather
     * than conclude the product has no categories (`alpha.8`). An empty ARRAY
     * means the table answered and holds nothing for this product, which is
     * «not recorded», still not «has none».
     *
     * @return list<int>|null
     */
    public function categoryIdsFor(int $productId): ?array;

    /**
     * The recorded sets for a page of products, in one read.
     *
     * `null` means the question could not be asked — the table is absent or
     * the read failed — and the caller must fall back rather than conclude
     * that none of them is recorded.
     *
     * @param list<int> $productIds
     * @return array<int,list<int>>|null keyed by product id; a product with
     *         nothing recorded is absent from the map
     */
    public function categorySetsFor(array $productIds): ?array;

    /**
     * Replace the recorded set for one product, as one unit of work.
     *
     * ⛔ An EMPTY set is ALWAYS refused, because «هیچ رابطه‌ای خودکار حذف
     * نشود»: clearing what is recorded is a decision, and in this package no
     * path takes it.
     *
     * The parameter that used to say «this caller means it» is gone. It had
     * **no caller** — `alpha.80`'s rule, in a new place: a parameter nobody
     * passes is not a capability, it is a door with no room behind it, and
     * leaving it there invites the next round to flip it without finding out
     * who clears a relationship and on whose authority. What it would take to
     * clear one is written as an open decision
     * (`docs/open-decisions-alpha82.md` §2), unanswered.
     *
     * @param list<int> $termIds
     * @return string '' on success, otherwise a named refusal:
     *         `bad_product` · `category_table_unreadable` ·
     *         `category_set_unsupported` (schema 34) · `category_set_empty` ·
     *         `not_atomic` · `storage_failed`
     */
    public function saveCategorySet(int $productId, array $termIds): string;

    /**
     * Record a product's whole relationship — adding only, never replacing.
     *
     * Three answers rather than two, because three things are genuinely
     * different: an imported row with nothing recorded gets the whole set; a
     * row whose recorded set is a strict SUBSET of this one is COMPLETED (the
     * missing ids go in, nothing is removed); and a row carrying an id this
     * product does not have is somebody's decision and is refused by name.
     *
     * ⛔ The completion case is what makes the upgrade work at all: migration
     * 35 backfills one row from `category_key`, which on staging is
     * `category_ids[0]`, so the multi-category products arrive with one of
     * their ids recorded. «مجموعهٔ کامل دسته‌ها باید حفظ شود… مدیر نباید
     * مجبور به انتخاب مجدد دسته‌های موجود شود.»
     *
     * The recorded set is read inside the transaction under a locking read
     * (`alpha.64`), and the single key is written only into an empty one —
     * a legacy key is left exactly as it is, because what it means for a
     * multi-category product is the owner's open decision.
     *
     * @param list<int> $termIds
     * @return string `category_set_written` · `already_set` ·
     *         `category_set_decided` · `bad_category` · `not_found` ·
     *         `not_imported` · `not_atomic` · `storage_failed` ·
     *         `category_set_unsupported` · `category_table_unreadable`
     */
    public function recordCategorySet(int $productId, array $termIds): string;

    /**
     * WooCommerce products that more than one of our rows points at.
     *
     * ⛔ This is physically possible: `tmc_products` has NO unique index on
     * `wc_product_id` (`alpha.56`), so «no duplicates» is a property of the
     * code that writes it and not of the table — which makes it something to
     * COUNT rather than something to assume. A completion report that cannot
     * answer this question is the reassuring kind.
     *
     * @return list<int> the WooCommerce ids, bounded
     */
    public function duplicateWcMappings(int $limit = 50): array;

    /**
     * @return list<int> every vendor owning at least one product an import stamped
     *
     * The companion to `idsFromImportRun()`, asked of the whole catalogue
     * rather than of one run: not «what did this run create» but «which shops
     * did the migration touch at all». `MigrationCompleteness` needs it
     * because a shop can arrive carrying nothing but a catalogue — no balance
     * row, no withdrawal, no staff — and a shop missing from that list is not
     * judged incomplete, it is absent, which reads as nothing left to do.
     */
    public function vendorsFromImportRuns(): array;

    /**
     * A status transition — and the moment the storefront's status becomes ours.
     *
     * This is the one chokepoint every explicit status decision goes through
     * (submit, approve, reject, publish, suspend, archive, restore), so it is
     * where `mirrored_status` is cleared: before it, the status on an intaken
     * product is somebody else's and the projector holds it; after it, a
     * human inside this marketplace has decided, and the decision is written.
     *
     * Cleared in the SAME statement as the status, because a transition whose
     * second half failed would leave a status this marketplace decided and a
     * hold that stops it being written — a third value nobody asked for
     * (`alpha.61`).
     */
    public function updateStatus(int $productId, ProductStatus $status, string $reviewNote = ''): bool;

    /**
     * Which APPROVED brand this product's brand is, or null.
     *
     * Its own column and its own pair of methods, deliberately NOT a field on
     * `ProductDetails`. The brand a shopper sees is the NAME, which every
     * form, payload, CSV column, diff and projection already carries; the term
     * id is identity, and adding it to the details object would put a second
     * value into every one of those places for one question nothing else asks.
     *
     * Null has a meaning and it is not «no brand»: it says this row's brand
     * predates the registry (migration 25 adds the column with no backfill),
     * or that nobody has picked from the list since. A legacy free-text brand
     * therefore keeps working and is never silently declared approved — the
     * owner asked for exactly that restraint.
     */
    public function brandTermIdOf(int $productId): ?int;

    /**
     * Columns of `tmc_products` whose SHAPE would make a write store wrong
     * data. Empty when nothing is wrong with the columns that could be read.
     *
     * Asked at the WRITE rather than at the migration, and asked about the
     * shape rather than about the schema version — «شمارهٔ ساختار به‌تنهایی
     * کافی نیست». A column that is missing, and a column present with the
     * wrong nullability, type, length or default, all come back here, so «the
     * structure is behind» needs no second question.
     *
     * EMPTY IS NOT A CLEAN BILL OF HEALTH: an unreadable `information_schema`
     * answers empty as well, and `structureKnown()` is the question that tells
     * them apart. A caller that refuses on this list alone would let the
     * unknown case through, which is precisely what `alpha.48` did.
     *
     * @return list<string>
     */
    public function unsoundColumns(): array;

    /**
     * Whether the shape of `tmc_products` could be READ at all.
     *
     * False means there is no answer to the question above — never that the
     * answer is «sound». Measured on the `alpha.48` bytes, where this question
     * did not exist: `reads_as_sound=yes problem=product_table_unreadable
     * wrote=1`. Every write refuses while this is false, under its own name.
     */
    public function structureKnown(): bool;

    /**
     * The brand name AND the approved brand it is, written together.
     *
     * Together because they are one fact. A name without its id would leave
     * the next reader unable to tell a picked brand from free text, and an id
     * without its name would show a shopper nothing.
     *
     * Unguarded by design: this is the manager applying a decision they have
     * just made, which is the same reason `approveRevision()` writes the
     * details unguarded. The vendor's own brand change travels on
     * `updateDetails()` and keeps its version guard.
     */
    public function setBrandTerm(int $productId, string $brand, ?int $termId): bool;

    /**
     * Record the values both sides agreed on, so the NEXT vendor edit can be
     * measured against something.
     *
     * Called by an approval or an explicit field decision — never by a
     * projection, which is the marketplace writing rather than the two sides
     * agreeing. `null` clears it, which is what a rollback of a decision
     * means: back to «no record of agreement», not to a guessed one.
     */
    public function saveBaseline(int $productId, ?ApprovedBaseline $baseline): bool;

    /** Stock and the other immediate fields, which never wait for a review. */
    public function updateInventory(
        int $productId,
        int $stock,
        string $sku,
        int $minPurchase,
        ?int $maxPurchase,
        int $purchaseStep = 1
    ): bool;

    /** @param array<string,string> $values */
    public function saveSpecs(int $productId, array $values, int $schemaVersion): bool;

    /** @return array<string,string> */
    public function specs(int $productId): array;

    /** @param list<int> $mediaIds */
    public function saveImages(int $productId, array $mediaIds, int $mainImageId): bool;

    /** @return list<int> */
    public function images(int $productId): array;

    /**
     * Whether ANY product, gallery or variation names this attachment.
     *
     * Asked before an upload is discarded, and it is the whole licence for
     * that discard: «پاک‌سازی عمومی ممنوع», so the only media this plugin
     * ever deletes is media it just created AND that this query says nothing
     * points at. Scoped to no vendor on purpose — a reference belonging to
     * somebody else is still a reference, and the safe answer to «is anyone
     * using this» is never «not in your shop».
     */
    public function mediaInUse(int $mediaId): bool;

    /** Whether this vendor already used this SKU on a different product. */
    public function skuTaken(int $vendorUserId, string $sku, int $exceptProductId = 0): bool;

    /**
     * The WooCommerce link, one product each way (ADR-008).
     *
     * Passing null unlinks — used when the projected post has gone (somebody
     * deleted it in wp-admin), so the next projection creates a fresh one
     * instead of writing into a hole.
     */
    public function link(int $productId, ?int $wcProductId): bool;

    /**
     * The row that OWNS this storefront product — never one that merely maps
     * it. Every purchase decision ends here, so an `observed` row answering
     * this question is how a migration silently takes over another plugin's
     * catalogue.
     */
    public function findByWcProduct(int $wcProductId): ?Product;

    /** The row that merely POINTS at this storefront product, if any. */
    public function findObservedByWcProduct(int $wcProductId): ?Product;

    /** @return list<Product> rows a migration mapped but nobody took over */
    public function observed(int $limit = 200): array;

    public function linkOwnership(int $productId): LinkOwnership;

    /** The explicit transfer, in both directions. Never called implicitly. */
    public function setLinkOwnership(int $productId, LinkOwnership $ownership): bool;

    /**
     * How many of a shop's rows are the marketplace's to run, and how many
     * are still only notes — counted in SQL.
     *
     * The migration report needs this and needs it cheap: «is this shop
     * actually migrated» is asked about every imported shop on one page, and
     * hydrating each catalogue to look at one column would make the answer
     * cost the catalogue.
     *
     * @return array{marketplace:int, observed:int}
     */
    public function ownershipTallyForVendor(int $vendorUserId): array;

    /**
     * Mirrors the stock WooCommerce now holds onto the marketplace row.
     *
     * This is the ONLY way stock travels from WooCommerce to here, and there
     * is deliberately no method for the other direction that a sync could
     * call: a sale must never be undone by a mirror (ADR-008).
     */
    public function mirrorStock(int $productId, int $stock): bool;

    public function updateSeo(int $productId, ProductSeo $seo): bool;

    /**
     * @return list<Product> every product of this vendor, whatever its status
     *
     * Use it when you genuinely need every row. For a page use `forVendor()`,
     * for a total `countForVendor()`, for the stock figures `stockSummary()` —
     * all three answer in SQL. Hydrating a whole catalogue into objects to
     * count it costs the same on a shop with twelve products and falls over on
     * one with twelve thousand.
     */
    public function allForVendor(int $vendorUserId): array;

    /**
     * Stock figures for the published catalogue, counted in SQL.
     *
     * The report used to walk every product, hydrate each one and add up in
     * PHP — three figures at the cost of the whole catalogue, on every page
     * load. These are three `COUNT`s behind the index the table already has.
     *
     * @return array{on_sale:int, out:int, low:int}
     */
    public function stockSummary(int $vendorUserId, int $lowThreshold): array;

    /** @return list<Product> products that are live and projected */
    public function projected(int $limit = 500): array;
}
