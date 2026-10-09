<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ApprovedBaseline;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\PersianCollation;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductSeo;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\ProductRevision;
use Tecteb\Marketplace\Modules\Product\Domain\ProductSort;
use Tecteb\Marketplace\Modules\Product\Domain\ProductRowVersion;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0010LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0019BaselineAndDecisions;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0023ProductDescriptionAndCreateToken;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables as T;

/**
 * Products on the plugin's own tables.
 *
 * `findOwned()` exists next to `find()` so the caller cannot forget the owner:
 * the vendor area calls the first, the manager's queue calls the second, and
 * a query that should have been scoped is visible as such when reading.
 */
final class DbProductRepository implements ProductRepositoryInterface
{
    /**
     * The scope every "is this ours?" query carries.
     *
     * Written as constants rather than repeated strings so that adding a
     * query which forgets the scope is a visible omission rather than an
     * invisible one.
     */
    private const OWNED = M0010LinkOwnership::COLUMN . " = '" . 'marketplace' . "'";
    private const OBSERVED = M0010LinkOwnership::COLUMN . " = '" . 'observed' . "'";

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock
    ) {
    }

    public function find(int $productId): ?Product
    {
        $row = $this->db->getRow('SELECT * FROM `' . $this->products() . '` WHERE id = %d', [$productId]);
        return $row === null ? null : $this->hydrate($row);
    }

    public function findOwned(int $productId, int $vendorUserId): ?Product
    {
        $row = $this->db->getRow(
            'SELECT * FROM `' . $this->products() . '` WHERE id = %d AND vendor_user_id = %d',
            [$productId, $vendorUserId]
        );
        return $row === null ? null : $this->hydrate($row);
    }

    public function forVendor(
        int $vendorUserId,
        ?ProductStatus $status = null,
        int $limit = 200,
        int $offset = 0,
        string $search = ''
    ): array {
        [$where, $params] = $this->scope($vendorUserId, $status, $search);
        $params[] = max(1, $limit);
        $params[] = max(0, $offset);
        return array_map([$this, 'hydrate'], $this->db->getResults(
            'SELECT * FROM `' . $this->products() . '`' . $where . ' ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d',
            $params
        ));
    }

    public function countForVendor(int $vendorUserId, ?ProductStatus $status = null, string $search = ''): int
    {
        [$where, $params] = $this->scope($vendorUserId, $status, $search);
        return (int) $this->db->getVar('SELECT COUNT(*) FROM `' . $this->products() . '`' . $where, $params);
    }

    /**
     * The manager's catalogue: every product of every shop, filterable.
     *
     * Until now the only manager view was `inStatus(Submitted)`, so a product
     * that had been decided ANY way vanished from the marketplace side of the
     * admin while its WooCommerce post carried on existing. «محصول پس از
     * خروج از صف بررسی، از صفحهٔ مدیریت بازارگاه ناپدید می‌شود.»
     *
     * @return list<Product>
     */
    public function forManager(
        ?ProductStatus $status = null,
        string $search = '',
        int $limit = 20,
        int $offset = 0,
        int $vendorUserId = 0,
        ?ProductSort $sort = null,
        bool $onlyPendingRevision = false
    ): array {
        return array_column(
            $this->forManagerWithSubmission($status, $search, $limit, $offset, $vendorUserId, $sort, $onlyPendingRevision),
            'product'
        );
    }

    /**
     * The same page of rows, each with the identity of the submission on it.
     *
     * **One statement, and that is the whole point.** The red count is now per
     * manager and cleared by opening the list, so the page has to record WHICH
     * submission it showed. Read in a second query, that token could be newer
     * than the row above it — a vendor submitting between the two reads would
     * have their submission marked as seen by somebody who was looking at the
     * previous one. Selected here as part of the row, there is no «between».
     *
     * The token is the pair of append-only ids that ALREADY name a submission:
     * the newest `submitted` row in the decision trail, and the id of the
     * unanswered proposal. Neither is a timestamp and neither is the status, so
     * an ordinary edit — a price, a stock number, a title — does not move it,
     * and a resubmission always does.
     *
     * Both subqueries are correlated and indexed, and they run for the rows of
     * ONE page (`LIMIT` is in the statement), not for the catalogue.
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
        bool $onlyPendingRevision = false
    ): array {
        [$where, $params] = $this->managerScope($status, $search, $vendorUserId, $onlyPendingRevision);
        [$columns, $columnParams] = ReviewQueueSql::submissionColumns($this->db);
        $params = array_merge($columnParams, $params);
        $params[] = max(1, $limit);
        $params[] = max(0, $offset);
        $rows = $this->db->getResults(
            'SELECT p.*, ' . $columns . ' FROM `' . $this->products() . '` p'
                . str_replace('`' . $this->products() . '`.', 'p.', $where)
                . ' ORDER BY ' . self::order($sort) . ' LIMIT %d OFFSET %d',
            $params
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'product' => $this->hydrate($row),
                'submission' => ReviewQueueSql::token($row),
            ];
        }
        return $out;
    }

    /**
     * One product, its pending proposal and its submission identity — together.
     *
     * **The detail page's half of the one-statement rule.** `alpha.36` read the
     * product with `find()`, the proposal with `pendingFor()` and the token with
     * `submissionsOf()`: three statements, and a submission landing between the
     * first and the third let the page show the previous content while recording
     * the newer identity as seen. Moving the token read a few lines up would not
     * have proved anything — the window is between two reads, wherever they sit.
     *
     * So the product row and the two append-only ids come back from ONE SELECT,
     * and `revisionId` names the exact proposal that identity refers to. The
     * caller loads that proposal BY ID: a revision row is append-only, so
     * fetching it by its own id returns the payload this token is about even if
     * a newer proposal has superseded it in the meantime — and that newer one
     * then carries a different token and is correctly still unseen.
     *
     * `waiting` comes out of the SAME statement, and it is what the caller
     * records on rather than the token. `s0.r0` is a REAL token — a product
     * submitted before the decision trail existed (`alpha.29`) has one — so
     * «is there a submission to have seen» cannot be answered by looking at the
     * token. It is answered by the queue predicate, read here, beside the row.
     * The detail page shows published products too, and those have nothing to
     * mark.
     *
     * @return array{product:Product, submission:string, revisionId:int, waiting:bool}|null
     */
    public function findWithSubmission(int $productId): ?array
    {
        if ($productId <= 0) {
            return null;
        }
        [$columns, $columnParams] = ReviewQueueSql::submissionColumns($this->db);
        [$waiting, $waitingParams] = ReviewQueueSql::waiting($this->db);
        $row = $this->db->getRow(
            'SELECT p.*, ' . $columns . ', ' . $waiting . ' AS tmc_waiting'
                . ' FROM `' . $this->products() . '` p WHERE p.id = %d',
            array_merge($columnParams, $waitingParams, [$productId])
        );
        if ($row === null) {
            return null;
        }
        return [
            'product' => $this->hydrate($row),
            'submission' => ReviewQueueSql::token($row),
            'revisionId' => (int) ($row['tmc_revision_id'] ?? 0),
            'waiting' => (int) ($row['tmc_waiting'] ?? 0) === 1,
        ];
    }

    /**
     * Which of these products are waiting, and under which submission.
     *
     * Used for the badge: the manager's recorded marks are the input, so the
     * query is bounded by how many things they have looked at rather than by
     * the size of the queue. A product that has LEFT the queue is simply absent
     * from the answer, which is what stops a decided product subtracting from a
     * count it is no longer part of.
     *
     * @param list<int> $productIds
     * @return array<int,string> product id => submission token
     */
    public function submissionsOf(array $productIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            // Asking the database about nothing is how an `IN ()` syntax error
            // reaches a page that had nothing to ask about (`alpha.32`).
            return [];
        }
        [$columns, $columnParams] = ReviewQueueSql::submissionColumns($this->db);
        [$waiting, $waitingParams] = ReviewQueueSql::waiting($this->db);
        $rows = $this->db->getResults(
            'SELECT p.id, ' . $columns . ' FROM `' . $this->products() . '` p'
                . ' WHERE p.id IN (' . implode(',', array_map('intval', $ids)) . ')'
                . ' AND ' . $waiting,
            array_merge($columnParams, $waitingParams)
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = ReviewQueueSql::token($row);
        }
        return $out;
    }

    public function countForManager(
        ?ProductStatus $status = null,
        string $search = '',
        int $vendorUserId = 0,
        bool $onlyPendingRevision = false
    ): int {
        [$where, $params] = $this->managerScope($status, $search, $vendorUserId, $onlyPendingRevision);
        return (int) $this->db->getVar('SELECT COUNT(*) FROM `' . $this->products() . '`' . $where, $params);
    }

    public function countAwaitingReview(): int
    {
        // One query, and one row per product: `OR EXISTS` rather than a union
        // of two counts, because a product that is both submitted and carries
        // a proposal would otherwise be counted twice and the badge would ask
        // the manager to find a decision that does not exist.
        [$waiting, $params] = ReviewQueueSql::waiting($this->db);
        return (int) $this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->products() . '` p WHERE ' . $waiting,
            $params
        );
    }

    /**
     * The ORDER BY for one sort case — a whitelist, and the only place in this
     * read where a column name comes from anything the request influenced.
     *
     * The fragment is built from the enum, never from the string that
     * arrived: `ProductSort::fromKey()` has already turned anything unknown
     * into the default, so no request value reaches SQL. Every case ends in
     * `id` because `updated_at` has second precision, and a page boundary
     * inside one second is one row shown twice and another never shown.
     */
    private static function order(?ProductSort $sort): string
    {
        return match ($sort ?? ProductSort::LastChanged) {
            ProductSort::OldestChanged => 'updated_at ASC, id ASC',
            // The stored key, not the title: ordering by the column itself is
            // the ARABIC alphabet, which puts پ چ ژ گ after ی (see
            // `PersianCollation`). The key is ASCII and the index is on
            // (title_sort, id), so this is one index scan over the whole
            // result — not a sort of the page, and not every row in PHP.
            ProductSort::Title => 'title_sort ASC, id ASC',
            ProductSort::LastChanged => 'updated_at DESC, id DESC',
        };
    }

    /**
     * How many products are in each status, across every shop.
     *
     * The same GROUP BY the vendor's own counters use, minus the owner
     * filter — one query, so the chips cannot disagree with each other.
     *
     * @return array<string,int>
     */
    public function countsByStatusForManager(
        string $search = '',
        int $vendorUserId = 0,
        bool $onlyPendingRevision = false
    ): array {
        [$where, $params] = $this->managerScope(null, $search, $vendorUserId, $onlyPendingRevision);
        $rows = $this->db->getResults(
            'SELECT status, COUNT(*) AS n FROM `' . $this->products() . '`' . $where . ' GROUP BY status',
            $params
        );
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }
        return $counts;
    }

    /** @return array{0:string, 1:list<mixed>} */
    private function managerScope(
        ?ProductStatus $status,
        string $search,
        int $vendorUserId = 0,
        bool $onlyPendingRevision = false
    ): array {
        // `1 = 1` so every branch below can append with AND and the clause is
        // never empty — a `WHERE` with nothing after it is a syntax error and
        // an `if` on the first condition is how that gets forgotten.
        $where = ' WHERE 1 = 1';
        $params = [];
        if ($vendorUserId > 0) {
            $where .= ' AND vendor_user_id = %d';
            $params[] = $vendorUserId;
        }
        if ($status !== null) {
            $where .= ' AND status = %s';
            $params[] = $status->value;
        }
        $search = trim($search);
        if ($search !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $where .= ' AND (title LIKE %s OR sku LIKE %s OR brand LIKE %s)';
            array_push($params, $like, $like, $like);
        }
        if ($onlyPendingRevision) {
            // The same WHERE as the list, so the chips, the total and the
            // pager are answers about one set of rows. A second query that
            // collected ids and handed them to an `IN (…)` would be a second
            // moment, and the number under the pager would disagree with the
            // rows above it the first time a vendor proposed a change between
            // the two.
            $where .= ' AND EXISTS (SELECT 1 FROM `' . T::table($this->db, T::REVISIONS)
                . '` r WHERE r.product_id = `' . $this->products() . '`.id AND r.status = %s)';
            $params[] = ProductRevision::PENDING;
        }
        return [$where, $params];
    }

    /**
     * One WHERE clause for the list and its count, so the number under the
     * pager can never disagree with the rows above it.
     *
     * The search term is escaped for LIKE before it becomes a parameter:
     * a `%` a vendor types is a percent sign they are looking for, not a
     * wildcard that quietly matches everything.
     *
     * @return array{0:string, 1:list<mixed>}
     */
    private function scope(int $vendorUserId, ?ProductStatus $status, string $search): array
    {
        $where = ' WHERE vendor_user_id = %d';
        $params = [$vendorUserId];
        if ($status !== null) {
            $where .= ' AND status = %s';
            $params[] = $status->value;
        }
        $search = trim($search);
        if ($search !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $where .= ' AND (title LIKE %s OR sku LIKE %s OR brand LIKE %s)';
            array_push($params, $like, $like, $like);
        }
        return [$where, $params];
    }

    public function countsByStatus(int $vendorUserId): array
    {
        $rows = $this->db->getResults(
            'SELECT status, COUNT(*) AS n FROM `' . $this->products() . '` WHERE vendor_user_id = %d GROUP BY status',
            [$vendorUserId]
        );
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }
        return $counts;
    }

    public function inStatus(ProductStatus $status, int $limit = 200, int $offset = 0): array
    {
        return array_map([$this, 'hydrate'], $this->db->getResults(
            'SELECT * FROM `' . $this->products() . '` WHERE status = %s ORDER BY updated_at ASC, id ASC LIMIT %d OFFSET %d',
            [$status->value, max(1, $limit), max(0, $offset)]
        ));
    }

    public function countInStatus(ProductStatus $status): int
    {
        return (int) $this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->products() . '` WHERE status = %s',
            [$status->value]
        );
    }

    public function create(
        int $vendorUserId,
        ProductDetails $details,
        ProductStatus $status,
        LinkOwnership $ownership = LinkOwnership::Marketplace,
        ?int $wcProductId = null,
        string $importRunId = '',
        string $createToken = ''
    ): int {
        $now = $this->now();
        [$columns, $placeholders, $params] = $this->detailColumns($details);

        // The WooCommerce link and the import run go in THIS insert, not in an
        // UPDATE after it.
        //
        // The migration used to do three writes per product: create, stamp,
        // link. A process killed between the first and the third left a row
        // with `wc_product_id = NULL` — and NULL is invisible to both
        // `findByWcProduct()` and `findObservedByWcProduct()`, so the resumed
        // run judged that Dokan product «not imported yet» and made a SECOND
        // row. The unique key on `wc_product_id` could not stop it, because the
        // orphan's value was NULL and MySQL lets NULL repeat.
        //
        // Written together, the row either exists complete or does not exist:
        // there is no state in which it is half made. The resume then finds it
        // by its wc id and skips, and the unique key is a real second line of
        // defence rather than one the orphan slipped past.
        $extraColumns = [];
        $extraPlaceholders = [];
        $extraParams = [];
        if ($wcProductId !== null && $wcProductId > 0) {
            $extraColumns[] = 'wc_product_id';
            $extraPlaceholders[] = '%d';
            $extraParams[] = $wcProductId;
            $extraColumns[] = 'synced_at';
            $extraPlaceholders[] = '%s';
            $extraParams[] = $now;
        }
        if ($importRunId !== '') {
            $extraColumns[] = 'import_run_id';
            $extraPlaceholders[] = '%s';
            $extraParams[] = mb_substr($importRunId, 0, 64);
        }
        // The one-create-per-form token. Written in THIS insert and nowhere
        // else, so the unique key on `(vendor_user_id, create_token)` is what
        // decides — a replayed POST loses at the index rather than at a check
        // between a read and a write, which is a check two simultaneous
        // requests both pass. Left out when there is no token, so the column
        // stays NULL and cannot collide with any other row (the property
        // `wc_product_id` above relies on for exactly the same reason).
        if ($createToken !== '') {
            $extraColumns[] = M0023ProductDescriptionAndCreateToken::CREATE_TOKEN;
            $extraPlaceholders[] = '%s';
            $extraParams[] = mb_substr($createToken, 0, 64);
        }

        $sql = 'INSERT INTO `' . $this->products() . '` (vendor_user_id, ' . implode(', ', $columns)
            . ', status, ' . M0010LinkOwnership::COLUMN
            . ($extraColumns === [] ? '' : ', ' . implode(', ', $extraColumns))
            . ', created_at, updated_at) VALUES (%d, '
            . implode(', ', $placeholders) . ', %s, %s'
            . ($extraPlaceholders === [] ? '' : ', ' . implode(', ', $extraPlaceholders))
            . ', %s, %s)';
        $ok = $this->db->execute(
            $sql,
            array_merge(
                [$vendorUserId],
                $params,
                [$status->value, $ownership->value],
                $extraParams,
                [$now, $now]
            )
        );
        if ($ok === null) {
            // A REFUSED INSERT IS NOT THE SAME AS NO ROW — the `alpha.28`
            // rule, and `alpha.39` paid for the other half of it. When a
            // token was given, the one thing that refuses this insert is its
            // unique key, which means THIS form has already created its
            // product: the replay's answer is that product, not a failure
            // about a product that exists. Asked of the data rather than
            // inferred from the error string.
            return $createToken === '' ? 0 : $this->findByCreateToken($vendorUserId, $createToken);
        }
        // Connection-scoped, so two vendors inserting at the same moment
        // cannot be handed each other's id — which "ORDER BY id DESC" would.
        return (int) $this->db->getVar('SELECT LAST_INSERT_ID()');
    }

    /**
     * The product one submitted create form made, if it made one.
     *
     * Scoped to the vendor as well as the token: a token is a random string
     * from one vendor's form and has no business reaching another shop's row,
     * and the unique key is over both columns for the same reason.
     */
    public function findByCreateToken(int $vendorUserId, string $createToken): int
    {
        if (trim($createToken) === '') {
            return 0;
        }
        return (int) $this->db->getVar(
            'SELECT id FROM `' . $this->products() . '` WHERE vendor_user_id = %d AND `'
            . M0023ProductDescriptionAndCreateToken::CREATE_TOKEN . '` = %s',
            [$vendorUserId, mb_substr($createToken, 0, 64)]
        );
    }

    /**
     * Write the details, and — when a version is given — only if nobody else
     * has written since the caller read.
     *
     * The version belongs in the WHERE clause and nowhere else. `alpha.13`
     * compared it in PHP between a read and a write, which is a check two
     * concurrent editors both pass: both read version 4, both find it equal,
     * both write, and the second silently replaces the first. Here the database
     * decides, `row_version` moves by one in the same statement, and the loser
     * gets zero rows.
     *
     * An empty `$expectedVersion` skips the check. A form rendered by an older
     * build carries no version, and refusing those would break saving for
     * anybody mid-edit across an upgrade.
     */
    public function updateDetails(int $productId, ProductDetails $details, string $expectedVersion = ''): bool
    {
        [$columns, $placeholders, $params] = $this->detailColumns($details);
        $assignments = [];
        foreach ($columns as $i => $column) {
            $assignments[] = $column . ' = ' . $placeholders[$i];
        }
        $params[] = $this->now();
        $params[] = $productId;

        // A token this layer cannot compare is a REFUSAL, never a waiver.
        // Until alpha.15 an empty or unrecognised token simply dropped the
        // guard and the write went through unchecked — which is the race the
        // counter exists to stop, reachable by any form that happened not to
        // carry a stamp.
        if (!ProductRowVersion::permitsWrite($expectedVersion)) {
            return false;
        }
        $guard = '';
        if (ProductRowVersion::isUsable($expectedVersion)) {
            $guard = ' AND row_version = %d';
            $params[] = (int) $expectedVersion;
        }

        $written = $this->db->execute(
            'UPDATE `' . $this->products() . '`
             SET ' . implode(', ', $assignments) . ', updated_at = %s, row_version = row_version + 1
             WHERE id = %d' . $guard,
            $params
        );
        if ($written === null) {
            return false;                       // a real storage failure
        }
        if ($guard === '') {
            return true;                        // unguarded: nothing to lose to
        }
        // `row_version = row_version + 1` changes a column on every guarded
        // write, so zero rows here really does mean «the WHERE did not match»
        // rather than the «nothing changed» ambiguity the job queue hit.
        return $written > 0;
    }

    public function bumpVersion(int $productId, string $expectedVersion): bool
    {
        // Same rule as `updateDetails()`. This used to answer «true — nothing
        // to check against», which let the revision path write a proposal on
        // top of somebody else's without ever looking.
        if (!ProductRowVersion::permitsWrite($expectedVersion)) {
            return false;
        }
        if (ProductRowVersion::isDeliberatelyUnguarded($expectedVersion)) {
            return true;
        }
        $written = $this->db->execute(
            'UPDATE `' . $this->products() . '`
             SET row_version = row_version + 1, updated_at = %s
             WHERE id = %d AND row_version = %d',
            [$this->now(), $productId, (int) $expectedVersion]
        );
        return $written !== null && $written > 0;
    }

    public function rowVersion(int $productId): string
    {
        $value = $this->db->getVar(
            'SELECT row_version FROM `' . $this->products() . '` WHERE id = %d',
            [$productId]
        );
        return $value === null ? '' : (string) (int) $value;
    }

    public function stampImportRun(int $productId, string $runId): bool
    {
        return $this->db->execute(
            'UPDATE `' . $this->products() . '` SET import_run_id = %s WHERE id = %d',
            [mb_substr($runId, 0, 64), $productId]
        ) !== null;
    }

    public function importRunIds(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['import_run_id'],
            $this->db->getResults(
                'SELECT DISTINCT import_run_id FROM `' . $this->products() . '`
                 WHERE import_run_id <> %s ORDER BY import_run_id DESC',
                ['']
            )
        );
    }

    public function idsFromImportRun(string $runId): array
    {
        if ($runId === '') {
            return [];
        }
        return array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->db->getResults(
                'SELECT id FROM `' . $this->products() . '` WHERE import_run_id = %s ORDER BY id ASC',
                [$runId]
            )
        );
    }

    public function vendorsFromImportRuns(): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['vendor_user_id'],
            $this->db->getResults(
                'SELECT DISTINCT vendor_user_id FROM `' . $this->products() . '`
                 WHERE import_run_id <> %s ORDER BY vendor_user_id ASC',
                ['']
            )
        );
    }

    public function updateStatus(int $productId, ProductStatus $status, string $reviewNote = ''): bool
    {
        $published = $status === ProductStatus::Published ? '%s' : 'published_at';
        $params = [$status->value, $reviewNote];
        if ($status === ProductStatus::Published) {
            $params[] = $this->now();
        }
        $params[] = $this->now();
        $params[] = $productId;
        return $this->db->execute(
            'UPDATE `' . $this->products() . '` SET status = %s, review_note = %s, published_at = ' . $published
            . ', updated_at = %s WHERE id = %d',
            $params
        ) !== null;
    }

    public function updateInventory(int $productId, int $stock, string $sku, int $minPurchase, ?int $maxPurchase): bool
    {
        $max = $maxPurchase === null ? 'NULL' : '%d';
        $params = [$stock, $sku, $minPurchase];
        if ($maxPurchase !== null) {
            $params[] = $maxPurchase;
        }
        $params[] = $this->now();
        $params[] = $productId;
        return $this->db->execute(
            'UPDATE `' . $this->products() . '` SET stock = %d, sku = %s, min_purchase = %d, max_purchase = ' . $max
            . ', updated_at = %s WHERE id = %d',
            $params
        ) !== null;
    }

    /**
     * The medical answers, together or not at all.
     *
     * The same rule as the gallery, one step down: this one always reported a
     * failure honestly, but it could still leave three fields new and two old
     * and the schema version bumped over the mixture. A caller putting the
     * previous values back has enough to do without having to guess which
     * half of them are already there.
     */
    public function saveSpecs(int $productId, array $values, int $schemaVersion): bool
    {
        $now = $this->now();
        if (!$this->db->begin()) {
            return false;
        }
        foreach ($values as $key => $value) {
            $written = $this->db->execute(
                'INSERT INTO `' . $this->specsTable() . '` (product_id, field_key, value, schema_version, updated_at)
                 VALUES (%d, %s, %s, %d, %s)
                 ON DUPLICATE KEY UPDATE value = VALUES(value), schema_version = VALUES(schema_version), updated_at = VALUES(updated_at)',
                [$productId, (string) $key, (string) $value, $schemaVersion, $now]
            );
            if ($written === null) {
                $this->db->rollback();
                return false;
            }
        }
        $bumped = $this->db->execute(
            'UPDATE `' . $this->products() . '` SET spec_schema_version = %d, updated_at = %s WHERE id = %d',
            [$schemaVersion, $now, $productId]
        );
        if ($bumped === null) {
            $this->db->rollback();
            return false;
        }
        return $this->db->commit();
    }

    public function specs(int $productId): array
    {
        $rows = $this->db->getResults(
            'SELECT field_key, value FROM `' . $this->specsTable() . '` WHERE product_id = %d',
            [$productId]
        );
        $values = [];
        foreach ($rows as $row) {
            $values[(string) $row['field_key']] = (string) ($row['value'] ?? '');
        }
        return $values;
    }

    /**
     * Images are replaced as a set: the gallery the vendor just arranged IS
     * the gallery, so a removed picture disappears instead of lingering
     * because no DELETE matched it. The media items themselves are untouched.
     */
    /**
     * Replace the gallery — all of it, or none of it.
     *
     * This is a REPLACEMENT built out of a `DELETE` and a row per picture, and
     * until `alpha.31` the `DELETE`'s answer was thrown away. Two ways that
     * went wrong, and the first one is the worse:
     *
     *   * the delete fails, the inserts go ahead, and the product ends up with
     *     the old pictures AND the new ones — in a sort order they now share,
     *     so even «which is first» is a coin toss. And the method returned
     *     `true`, so nothing anywhere knew;
     *   * an insert fails halfway, and the gallery is left holding a piece of
     *     the new list with the old one already gone.
     *
     * A transaction is the answer to both, and this is what
     * `DatabaseInterface` grew one for in `alpha.20`. Every step is checked
     * against `null` — `0` rows is a SUCCESS (`alpha.8`'s rule) and a product
     * whose gallery was already empty deletes nothing.
     */
    public function saveImages(int $productId, array $mediaIds, int $mainImageId): bool
    {
        if (!$this->db->begin()) {
            return false;
        }
        if ($this->db->execute('DELETE FROM `' . $this->imagesTable() . '` WHERE product_id = %d', [$productId]) === null) {
            $this->db->rollback();
            return false;
        }
        foreach (array_values($mediaIds) as $sort => $mediaId) {
            $written = $this->db->execute(
                'INSERT INTO `' . $this->imagesTable() . '` (product_id, media_id, sort_order) VALUES (%d, %d, %d)
                 ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)',
                [$productId, (int) $mediaId, (int) $sort]
            );
            if ($written === null) {
                $this->db->rollback();
                return false;
            }
        }
        $main = $this->db->execute(
            'UPDATE `' . $this->products() . '` SET main_image_id = %d, updated_at = %s WHERE id = %d',
            [$mainImageId, $this->now(), $productId]
        );
        if ($main === null) {
            $this->db->rollback();
            return false;
        }
        return $this->db->commit();
    }

    public function images(int $productId): array
    {
        $rows = $this->db->getResults(
            'SELECT media_id FROM `' . $this->imagesTable() . '` WHERE product_id = %d ORDER BY sort_order ASC, id ASC',
            [$productId]
        );
        return array_map(static fn (array $row): int => (int) $row['media_id'], $rows);
    }

    public function skuTaken(int $vendorUserId, string $sku, int $exceptProductId = 0): bool
    {
        if (trim($sku) === '') {
            return false;       // an empty SKU is "not set", and many may be unset
        }
        return (int) $this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->products() . '` WHERE vendor_user_id = %d AND sku = %s AND id <> %d',
            [$vendorUserId, $sku, $exceptProductId]
        ) > 0;
    }

    public function link(int $productId, ?int $wcProductId): bool
    {
        $value = $wcProductId === null || $wcProductId <= 0 ? 'NULL' : '%d';
        $params = [];
        if ($value === '%d') {
            $params[] = $wcProductId;
        }
        array_push($params, $this->now(), $productId);
        return $this->db->execute(
            'UPDATE `' . $this->products() . '` SET wc_product_id = ' . $value . ', synced_at = %s WHERE id = %d',
            $params
        ) !== null;
    }

    /**
     * The marketplace row that OWNS this storefront product, if any.
     *
     * Scoped to owned links on purpose. This is the question the purchase
     * guard, the projector and the storefront stop all end at, and an
     * `observed` row — a Dokan product a migration merely mapped — must answer
     * "not ours" to every one of them. Before this scope existed, a dry-run
     * import made another plugin's product unpurchasable.
     */
    public function findByWcProduct(int $wcProductId): ?Product
    {
        if ($wcProductId <= 0) {
            return null;
        }
        $row = $this->db->getRow(
            'SELECT * FROM `' . $this->products() . '` WHERE wc_product_id = %d AND ' . self::OWNED,
            [$wcProductId]
        );
        return $row === null ? null : $this->hydrate($row);
    }

    /** The row that merely POINTS at this storefront product, if any. */
    public function findObservedByWcProduct(int $wcProductId): ?Product
    {
        if ($wcProductId <= 0) {
            return null;
        }
        $row = $this->db->getRow(
            'SELECT * FROM `' . $this->products() . '` WHERE wc_product_id = %d AND ' . self::OBSERVED,
            [$wcProductId]
        );
        return $row === null ? null : $this->hydrate($row);
    }

    /** @return list<Product> rows a migration mapped but nobody took over */
    public function observed(int $limit = 200): array
    {
        return array_map([$this, 'hydrate'], $this->db->getResults(
            'SELECT * FROM `' . $this->products() . '` WHERE ' . self::OBSERVED . ' ORDER BY id ASC LIMIT %d',
            [max(1, $limit)]
        ));
    }

    public function linkOwnership(int $productId): LinkOwnership
    {
        $value = (string) $this->db->getVar(
            'SELECT ' . M0010LinkOwnership::COLUMN . ' FROM `' . $this->products() . '` WHERE id = %d',
            [$productId]
        );
        return LinkOwnership::tryFrom($value) ?? LinkOwnership::Marketplace;
    }

    public function setLinkOwnership(int $productId, LinkOwnership $ownership): bool
    {
        return $this->db->execute(
            'UPDATE `' . $this->products() . '` SET ' . M0010LinkOwnership::COLUMN . ' = %s, updated_at = %s WHERE id = %d',
            [$ownership->value, $this->now(), $productId]
        ) !== null;
    }

    public function ownershipTallyForVendor(int $vendorUserId): array
    {
        $rows = $this->db->getResults(
            'SELECT ' . M0010LinkOwnership::COLUMN . ' AS ownership, COUNT(*) AS rows_seen
               FROM `' . $this->products() . '` WHERE vendor_user_id = %d
              GROUP BY ' . M0010LinkOwnership::COLUMN,
            [$vendorUserId]
        );
        $tally = ['marketplace' => 0, 'observed' => 0];
        foreach ($rows as $row) {
            // An unrecognised value counts as marketplace, matching
            // `linkOwnership()`: the column was added by migration 10 and a
            // row written before it has no value at all, which has always
            // meant «ours».
            $key = (LinkOwnership::tryFrom((string) $row['ownership']) ?? LinkOwnership::Marketplace)->value;
            $tally[$key] += (int) $row['rows_seen'];
        }
        return $tally;
    }

    public function mirrorStock(int $productId, int $stock): bool
    {
        return $this->db->execute(
            'UPDATE `' . $this->products() . '` SET stock = %d, synced_at = %s WHERE id = %d',
            [$stock, $this->now(), $productId]
        ) !== null;
    }

    public function updateSeo(int $productId, ProductSeo $seo): bool
    {
        return $this->db->execute(
            'UPDATE `' . $this->products() . '` SET seo_slug = %s, seo_title = %s, seo_description = %s, updated_at = %s WHERE id = %d',
            [$seo->slug, $seo->title, $seo->description, $this->now(), $productId]
        ) !== null;
    }

    public function allForVendor(int $vendorUserId): array
    {
        return array_map([$this, 'hydrate'], $this->db->getResults(
            'SELECT * FROM `' . $this->products() . '` WHERE vendor_user_id = %d AND ' . self::OWNED . ' ORDER BY id ASC',
            [$vendorUserId]
        ));
    }

    public function stockSummary(int $vendorUserId, int $lowThreshold): array
    {
        // One pass, three answers. `SUM(condition)` rather than three queries,
        // because three queries over the same rows can disagree with each other
        // when somebody sells something between them — and a report whose
        // «موجود» and «رو به اتمام» do not add up is worse than a slow one.
        $row = $this->db->getRow(
            'SELECT COUNT(*) AS on_sale,
                    SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END) AS out_of_stock,
                    SUM(CASE WHEN stock > 0 AND stock < %d THEN 1 ELSE 0 END) AS low
             FROM `' . $this->products() . '`
             WHERE vendor_user_id = %d AND status = %s AND ' . self::OWNED,
            [max(1, $lowThreshold), $vendorUserId, ProductStatus::Published->value]
        );
        return [
            'on_sale' => (int) ($row['on_sale'] ?? 0),
            'out' => (int) ($row['out_of_stock'] ?? 0),
            'low' => (int) ($row['low'] ?? 0),
        ];
    }

    /**
     * Every product this marketplace has on the storefront — and only those.
     *
     * The storefront stop walks this list and drafts what it finds, so an
     * `observed` row here would mean a stop taking another plugin's product
     * off sale. That is the single most damaging thing the ownership column
     * prevents, so the scope is not optional.
     */
    public function projected(int $limit = 500): array
    {
        return array_map([$this, 'hydrate'], $this->db->getResults(
            'SELECT * FROM `' . $this->products() . '` WHERE wc_product_id IS NOT NULL AND ' . self::OWNED
            . ' ORDER BY id ASC LIMIT %d',
            [max(1, $limit)]
        ));
    }

    /**
     * The detail columns, their placeholders and their values, in one place.
     *
     * The three nullable columns choose a literal NULL instead of a
     * placeholder for the same reason the store table does: a placeholder
     * cannot carry NULL through wpdb::prepare(), and an empty string reaching
     * a DATE column is rejected by strict mode.
     *
     * @return array{0:list<string>,1:list<string>,2:list<mixed>}
     */
    private function detailColumns(ProductDetails $d): array
    {
        // `title_sort` is written HERE and nowhere else, because this is the
        // one place both `create()` and `updateDetails()` build their column
        // list from — so the form, the CSV import, a manager's correction and
        // an approved revision all maintain it without any of them knowing it
        // exists. A key written in some paths and not others is worse than no
        // key: the list would order most rows correctly and quietly misplace
        // the ones last touched by the path that forgot.
        $columns = ['title', 'title_sort', 'type', 'category_key', 'brand', 'short_description', 'price_minor'];
        $placeholders = ['%s', '%s', '%s', '%s', '%s', '%s', '%d'];
        $params = [
            $d->title,
            PersianCollation::sortKey($d->title),
            $d->type,
            $d->categoryKey,
            $d->brand,
            $d->shortDescription,
            $d->priceMinor,
        ];

        foreach ([
            ['sale_price_minor', '%d', $d->salePriceMinor],
            ['sale_from', '%s', $d->saleFrom],
            ['sale_to', '%s', $d->saleTo],
        ] as [$column, $placeholder, $value]) {
            $columns[] = $column;
            if ($value === null) {
                $placeholders[] = 'NULL';
                continue;
            }
            $placeholders[] = $placeholder;
            $params[] = $value;
        }

        $columns = array_merge($columns, ['sku', 'stock', 'min_purchase', 'weight_grams', 'dimensions', 'tax_class']);
        $placeholders = array_merge($placeholders, ['%s', '%d', '%d', '%d', '%s', '%s']);
        array_push($params, $d->sku, $d->stock, $d->minPurchase, $d->weightGrams, $d->dimensions, $d->taxClass);

        $columns[] = 'max_purchase';
        if ($d->maxPurchase === null) {
            $placeholders[] = 'NULL';
        } else {
            $placeholders[] = '%d';
            $params[] = $d->maxPurchase;
        }

        return [$columns, $placeholders, $params];
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): Product
    {
        $details = new ProductDetails(
            (string) $row['title'],
            (string) $row['type'],
            (string) $row['category_key'],
            (string) $row['brand'],
            (string) ($row['short_description'] ?? ''),
            (int) $row['price_minor'],
            $row['sale_price_minor'] === null ? null : (int) $row['sale_price_minor'],
            $row['sale_from'] === null ? null : (string) $row['sale_from'],
            $row['sale_to'] === null ? null : (string) $row['sale_to'],
            (string) $row['sku'],
            (int) $row['stock'],
            (int) $row['min_purchase'],
            $row['max_purchase'] === null ? null : (int) $row['max_purchase'],
            (int) $row['weight_grams'],
            (string) $row['dimensions'],
            (string) $row['tax_class']
        );
        $id = (int) $row['id'];
        return new Product(
            $id,
            (int) $row['vendor_user_id'],
            $details,
            ProductStatus::from((string) $row['status']),
            $this->specs($id),
            $this->images($id),
            (int) $row['main_image_id'],
            (string) ($row['review_note'] ?? ''),
            (int) $row['spec_schema_version'],
            $row['published_at'] === null ? null : (string) $row['published_at'],
            (string) $row['updated_at'],
            ($row['wc_product_id'] ?? null) === null ? null : (int) $row['wc_product_id'],
            new ProductSeo(
                (string) ($row['seo_slug'] ?? ''),
                (string) ($row['seo_title'] ?? ''),
                (string) ($row['seo_description'] ?? '')
            ),
            // Absent on a row read before migration 14 ran; an empty version
            // means the form carries nothing to compare and the write is
            // unguarded, which is what a mid-upgrade save needs.
            isset($row['row_version']) ? (string) (int) $row['row_version'] : '',
            // Absent before migration 19, and NULL for every product that has
            // not been approved since. `decode()` answers null for both,
            // which is the honest answer: «no record of agreement».
            ApprovedBaseline::decode(
                isset($row[M0019BaselineAndDecisions::BASELINE_COLUMN])
                    ? (string) $row[M0019BaselineAndDecisions::BASELINE_COLUMN]
                    : null
            )
        );
    }

    /**
     * Record what both sides agreed on, for the next edit to be measured from.
     *
     * Only an approval or an explicit decision calls this. A projection does
     * not: projecting is the marketplace writing, not the two sides agreeing,
     * and a baseline written on every sync would make «the vendor changed
     * this» permanently false.
     */
    public function saveBaseline(int $productId, ?ApprovedBaseline $baseline): bool
    {
        return $this->db->execute(
            'UPDATE `' . $this->products() . '` SET `' . M0019BaselineAndDecisions::BASELINE_COLUMN . '` = %s WHERE id = %d',
            [$baseline?->encode() ?? '', $productId]
        ) !== null;
    }

    public function deleteDraft(int $productId): bool
    {
        $product = $this->find($productId);
        if ($product === null || $product->status !== ProductStatus::Draft) {
            return false;
        }
        // The specs and images go with it — they are rows OF this draft, not
        // records about it. The WooCommerce post the link points at is not
        // touched: it was never ours to remove.
        $this->db->execute('DELETE FROM `' . $this->specsTable() . '` WHERE product_id = %d', [$productId]);
        $this->db->execute('DELETE FROM `' . $this->imagesTable() . '` WHERE product_id = %d', [$productId]);
        $removed = $this->db->execute('DELETE FROM `' . $this->products() . '` WHERE id = %d AND status = %s', [
            $productId,
            ProductStatus::Draft->value,
        ]);
        return $removed !== null && $removed > 0;
    }

    private function products(): string
    {
        return T::table($this->db, T::PRODUCTS);
    }

    private function specsTable(): string
    {
        return T::table($this->db, T::PRODUCT_SPECS);
    }

    private function imagesTable(): string
    {
        return T::table($this->db, T::PRODUCT_IMAGES);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
