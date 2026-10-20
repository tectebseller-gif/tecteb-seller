<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Migration\Application\CatalogueReaderInterface;
use Tecteb\Marketplace\Modules\Migration\Domain\CatalogueFacets;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\ProductSort;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0010LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Infrastructure\ReviewQueueSql;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\ProjectedQuantity;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\WooCommerceBrandDirectory;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\WooCommerceProjector;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;

/**
 * The existing WooCommerce catalogue, read through WordPress and WooCommerce.
 *
 * **Why the counts are SQL and the detail is `wc_get_product()`.** A count
 * over tens of thousands of products has to be `COUNT(*) … GROUP BY` or the
 * preview is a timeout; one product's values have to come from WooCommerce's
 * own data store, because WooCommerce is the authority on its own product and
 * reading `postmeta` by hand would reproduce — slightly differently — the
 * dozen filters it applies. `alpha.50` measured what that costs: a `_price`
 * row and a `WC_Product` object disagreeing in the same request, because
 * `wc_get_product()` keeps an instance cache.
 *
 * For a READ that disagreement does not matter, and where it would — the
 * compare-and-write of the Min Max handover — a different port asks the rows
 * directly (`WpShopQuantityPort::readRows()`).
 *
 * Nothing here writes. There is no method that could.
 */
final class WpCatalogueReader implements CatalogueReaderInterface
{
    /**
     * The product types this marketplace's own form can express.
     *
     * Anything else is reported BY NAME and never converted — a grouped
     * product has no price of its own and an external one is a link to
     * somebody else's shop, and silently importing either as «simple» would
     * be this plugin deciding what a product is.
     */
    public const SUPPORTED_TYPES = [ProductType::SIMPLE, ProductType::VARIABLE];

    /**
     * Meta-key prefixes worth naming when they are on a product.
     *
     * The question is «does this product depend on another plugin», so the
     * answer is a tally of PREFIXES. Values are never read: a dependency
     * report must not become an export of whatever those plugins store.
     */
    private const KNOWN_PREFIXES = [
        '_wcmmq' => 'Min Max Control',
        'wcmmq' => 'Min Max Control',
        '_dokan' => 'Dokan',
        'dokan' => 'Dokan',
        'rank_math' => 'Rank Math',
        '_yoast' => 'Yoast SEO',
        '_aioseo' => 'All in One SEO',
        '_elementor' => 'Elementor',
        '_wpml' => 'WPML',
        '_tmc' => 'این افزونه',
        'tmc_' => 'این افزونه',
    ];

    public function __construct(
        private readonly DatabaseInterface $db,
        // Optional so a build without the vendor module still constructs. Its
        // absence means the author's application status reads as unknown,
        // which pushes a product towards «ambiguous» — the safe direction.
        private readonly ?VendorRepositoryInterface $vendors = null
    ) {
    }

    public function isAvailable(): bool
    {
        return class_exists('WooCommerce') && function_exists('wc_get_product');
    }

    public function counts(): array
    {
        $out = [
            'by_status' => [],
            'by_type' => [],
            'variations' => 0,
            'unsupported' => [],
            'total' => 0,
        ];
        $posts = '`' . $this->db->prefix() . 'posts`';
        foreach ($this->db->getResults(
            'SELECT post_status, COUNT(*) AS total FROM ' . $posts . '
              WHERE post_type = %s GROUP BY post_status',
            ['product']
        ) as $row) {
            $out['by_status'][(string) $row['post_status']] = (int) $row['total'];
            $out['total'] += (int) $row['total'];
        }
        $out['variations'] = (int) ($this->db->getVar(
            'SELECT COUNT(*) FROM ' . $posts . ' WHERE post_type = %s',
            ['product_variation']
        ) ?? 0);

        // WooCommerce's own `product_type` taxonomy, which is where a
        // product's type actually lives — not a meta key and not a column.
        //
        // ------------------------------------------------------------------
        // SCOPED TO PRODUCTS, AND `alpha.55` WAS NOT
        // ------------------------------------------------------------------
        //
        // The owner read this table on staging and the numbers did not add
        // up: 189 products across four statuses, and `simple=262
        // variable=32` — 294 — in the same report. The cause is in this
        // query rather than in the site: `alpha.55` counted
        // `term_relationships` rows for the `product_type` taxonomy and
        // NEVER JOINED `posts`. So anything that has such a row was counted,
        // whether or not it is a product in this catalogue: a relationship
        // left behind by a deleted post, a variation an importer gave a type
        // term, a row pointing at another post type. The status query above
        // IS scoped (`post_type = 'product'`), so one half of the table was
        // answering a narrower question than the other half, and the two
        // halves were printed side by side as if they were the same count.
        //
        // The scope is now the same on both sides, so «the types add up to
        // the statuses» is a property rather than a coincidence — and where
        // it still does not, `without_type` and `with_two_types` say why
        // instead of leaving a difference for somebody to guess at.
        foreach ($this->db->getResults(
            'SELECT t.name AS type, COUNT(*) AS total
               FROM `' . $this->db->prefix() . 'posts` p
         INNER JOIN `' . $this->db->prefix() . 'term_relationships` r ON r.object_id = p.ID
         INNER JOIN `' . $this->db->prefix() . 'term_taxonomy` tt
                 ON tt.term_taxonomy_id = r.term_taxonomy_id AND tt.taxonomy = %s
         INNER JOIN `' . $this->db->prefix() . 'terms` t ON t.term_id = tt.term_id
              WHERE p.post_type = %s
              GROUP BY t.name',
            ['product_type', 'product']
        ) as $row) {
            $type = (string) $row['type'];
            $out['by_type'][$type] = (int) $row['total'];
            if (!in_array($type, self::SUPPORTED_TYPES, true)) {
                $out['unsupported'][$type] = (int) $row['total'];
            }
        }
        $out += $this->typeScopeNotes();
        return $out;
    }

    /**
     * The two reasons the type counts can still not add up to the statuses,
     * each counted rather than left as a difference.
     *
     * A product with NO `product_type` term is read as simple by WooCommerce
     * but appears in no row of the table; a product with TWO is counted
     * twice. Both are real states a long-lived catalogue reaches, and both
     * are the manager's to look at — this plugin does not write term
     * relationships, so neither is ours to repair.
     *
     * `strays` is the shape `alpha.55` was counting: `product_type` rows that
     * belong to no product at all. It is reported because that is where the
     * difference the owner saw came from, and because a number nobody can see
     * is a number nobody can act on.
     *
     * @return array<string,mixed>
     */
    private function typeScopeNotes(): array
    {
        $posts = '`' . $this->db->prefix() . 'posts`';
        $rel = '`' . $this->db->prefix() . 'term_relationships`';
        $tax = '`' . $this->db->prefix() . 'term_taxonomy`';
        $isType = $tax . ' tt ON tt.term_taxonomy_id = r.term_taxonomy_id AND tt.taxonomy = %s';
        return [
            'without_type' => (int) ($this->db->getVar(
                'SELECT COUNT(*) FROM ' . $posts . ' p
                  WHERE p.post_type = %s AND NOT EXISTS (
                        SELECT 1 FROM ' . $rel . ' r INNER JOIN ' . $isType . '
                         WHERE r.object_id = p.ID)',
                ['product', 'product_type']
            ) ?? 0),
            'with_two_types' => (int) ($this->db->getVar(
                'SELECT COUNT(*) FROM (
                     SELECT r.object_id FROM ' . $rel . ' r
                     INNER JOIN ' . $isType . '
                     INNER JOIN ' . $posts . ' p ON p.ID = r.object_id AND p.post_type = %s
                      GROUP BY r.object_id HAVING COUNT(*) > 1) x',
                ['product_type', 'product']
            ) ?? 0),
            'strays' => [
                'on_variations' => (int) ($this->db->getVar(
                    'SELECT COUNT(*) FROM ' . $rel . ' r INNER JOIN ' . $isType . '
                     INNER JOIN ' . $posts . ' p ON p.ID = r.object_id
                      WHERE p.post_type = %s',
                    ['product_type', 'product_variation']
                ) ?? 0),
                'on_other_post_types' => (int) ($this->db->getVar(
                    'SELECT COUNT(*) FROM ' . $rel . ' r INNER JOIN ' . $isType . '
                     INNER JOIN ' . $posts . ' p ON p.ID = r.object_id
                      WHERE p.post_type NOT IN (%s, %s)',
                    ['product_type', 'product', 'product_variation']
                ) ?? 0),
                'no_post_at_all' => (int) ($this->db->getVar(
                    'SELECT COUNT(*) FROM ' . $rel . ' r INNER JOIN ' . $isType . '
                      LEFT JOIN ' . $posts . ' p ON p.ID = r.object_id
                      WHERE p.ID IS NULL',
                    ['product_type']
                ) ?? 0),
            ],
        ];
    }

    public function productsAfter(int $afterId, int $limit): array
    {
        $limit = max(1, min(200, $limit));
        $rows = $this->db->getResults(
            'SELECT ID, post_status, post_author, post_title, post_name
               FROM `' . $this->db->prefix() . 'posts`
              WHERE post_type = %s AND ID > %d
              ORDER BY ID ASC LIMIT %d',
            ['product', max(0, $afterId), $limit]
        );
        if ($rows === []) {
            return ['rows' => [], 'cursor' => $afterId, 'done' => true];
        }
        $ids = array_map(static fn (array $r): int => (int) $r['ID'], $rows);
        // Four batched lookups for the whole page, so the cost of a page does
        // not grow with its size.
        $types = $this->typesOf($ids);
        $skus = $this->metaOf($ids, '_sku');
        $children = $this->childCounts($ids);
        $links = $this->marketplaceRowsFor($ids);

        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['ID'];
            $type = $types[$id] ?? '';
            $out[] = [
                'wc_product_id' => $id,
                'post_status' => (string) $row['post_status'],
                'post_author' => (int) $row['post_author'],
                'title' => (string) $row['post_title'],
                'slug' => (string) $row['post_name'],
                'type' => $type,
                'type_supported' => in_array($type, self::SUPPORTED_TYPES, true),
                'sku' => (string) ($skus[$id] ?? ''),
                'variations' => (int) ($children[$id] ?? 0),
                'marketplace_rows' => $links[$id] ?? [],
            ];
        }
        return [
            'rows' => $out,
            'cursor' => (int) end($ids),
            'done' => count($rows) < $limit,
        ];
    }

    /**
     * ONE page of the WHOLE catalogue: WooCommerce products and marketplace
     * rows together, each related product exactly once.
     *
     * **Why a UNION and not two lists.** Until this round a manager had two
     * screens — «بررسی محصولات», which listed marketplace rows, and «ورود
     * محصولات موجود», which listed WooCommerce posts — and a product that
     * existed on both sides appeared on both, with no way to tell from either
     * screen that it was one product. The instruction is one list in which
     * «هر محصول مرتبط فقط یک بار» appears, so the pair is joined in SQL and
     * the two halves that have no partner are unioned in: a WooCommerce
     * product with no row, and a marketplace row whose product is not there.
     *
     * **Each related product exactly once, even when two rows point at it.**
     * `wp_postmeta` has no unique index on `(post_id, meta_key)` and
     * `tmc_products` has none on `wc_product_id` — this repository measured
     * both (`alpha.55`). So the join is to `MIN(id)` per `wc_product_id`,
     * never to the table directly: a LEFT JOIN on a column with duplicates
     * multiplies the page, and a pager over a multiplied result shows one
     * product twice and hides another entirely. `rows_seen` carries the
     * duplicate so the row can SAY it is ambiguous instead of hiding it.
     *
     * **The bound is in the query.** `LIMIT`/`OFFSET` on the union, a
     * `COUNT(*)` for the total and ONE `GROUP BY` for all three facet
     * tallies: «همهٔ محصولات برای صفحه‌بندی در PHP خوانده نشوند». The cost
     * that does grow with the catalogue is the aggregate over
     * `tmc_products` — one grouped scan of our own table, which the
     * `(link_ownership, wc_product_id)` key from migration 10 covers — and
     * that is stated rather than hidden.
     *
     * **No LEFT JOIN to `postmeta`, deliberately.** The SKU would be the
     * obvious thing to join, and a product with two `_sku` rows would then
     * appear twice. It is searched with `EXISTS` and displayed from a batched
     * read per page.
     *
     * @param array{search?:string, source?:string, wc_status?:string, management?:string} $filters
     * @return array{
     *     rows: list<array<string,mixed>>,
     *     total: int,
     *     facets: array{source:array<string,int>, wc_status:array<string,int>, management:array<string,int>}
     * }
     */
    public function unifiedPage(array $filters, int $limit, int $offset, string $sort = 'updated'): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        [$union, $unionParams] = $this->unionSql($filters);
        [$where, $whereParams] = $this->unifiedWhere($filters);

        $total = (int) ($this->db->getVar(
            'SELECT COUNT(*) FROM (' . $union . ') u' . $where,
            array_merge($unionParams, $whereParams)
        ) ?? 0);

        $rows = $this->db->getResults(
            'SELECT * FROM (' . $union . ') u' . $where
            . ' ORDER BY ' . $this->unifiedOrder($sort) . ' LIMIT %d OFFSET %d',
            array_merge($unionParams, $whereParams, [$limit, $offset])
        );

        // One grouped read for all three tallies. The search narrows it — a
        // chip has to count what the current search would show — and the
        // facets themselves do not, so each chip says how many rows choosing
        // it would give.
        $facetRows = $this->db->getResults(
            'SELECT source, management, wc_status, COUNT(*) AS n FROM (' . $union . ') u
              GROUP BY source, management, wc_status',
            $unionParams
        );
        $facets = ['source' => [], 'wc_status' => [], 'management' => []];
        foreach ($facetRows as $row) {
            $n = (int) $row['n'];
            foreach (['source', 'management', 'wc_status'] as $key) {
                $value = (string) $row[$key];
                $facets[$key][$value] = ($facets[$key][$value] ?? 0) + $n;
            }
        }

        return [
            'rows' => $this->decorateUnified($rows),
            'total' => $total,
            'facets' => $facets,
        ];
    }

    /**
     * The page's rows with the per-page batched facts added.
     *
     * Four reads for the whole page — type, SKU, variation count and every
     * marketplace row — so the cost of a page does not grow with its size and
     * a row never asks a question of its own. `productsAfter()` has done it
     * this way since `alpha.55`; this is the same four, over the ids of a
     * page that may also contain rows with no WooCommerce product at all.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function decorateUnified(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) $row['wc_product_id'];
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $types = $this->typesOf($ids);
        $skus = $this->metaOf($ids, '_sku');
        $children = $this->childCounts($ids);
        $links = $this->marketplaceRowsFor($ids);
        // The post's own featured image, because that is the picture the shop
        // shows. Batched like the rest: `get_the_post_thumbnail_url()` per row
        // would be a page of twenty products asking twenty questions.
        $thumbs = $this->metaOf($ids, '_thumbnail_id');

        $out = [];
        foreach ($rows as $row) {
            $wcId = (int) $row['wc_product_id'];
            $type = $wcId > 0 ? (string) ($types[$wcId] ?? '') : (string) $row['row_type'];
            // A WooCommerce product with no `product_type` term is a simple
            // product as far as WooCommerce is concerned, and the count query
            // says so too (`without_type`). Reading it as «unsupported» here
            // would refuse an intake WooCommerce itself is happy with.
            $effective = $type === '' ? ProductType::SIMPLE : $type;
            $out[] = [
                'wc_product_id' => $wcId,
                'product_id' => (int) $row['product_id'],
                'title' => (string) $row['title'],
                // The post's SKU when there is a post, the row's own when
                // there is not. Two sources for one column, each authoritative
                // for its own half.
                'sku' => $wcId > 0 ? (string) ($skus[$wcId] ?? '') : (string) $row['row_sku'],
                'wc_status' => (string) $row['wc_status'],
                'author_id' => (int) $row['author_id'],
                'ownership' => (string) $row['ownership'],
                'marketplace_status' => (string) $row['m_status'],
                'vendor_user_id' => (int) $row['vendor_user_id'],
                'rows_seen' => (int) $row['rows_seen'],
                'changed_at' => (string) $row['changed_at'],
                'source' => (string) $row['source'],
                'management' => (string) $row['management'],
                'type' => $effective,
                'type_supported' => in_array($effective, self::SUPPORTED_TYPES, true),
                'variations' => $wcId > 0 ? (int) ($children[$wcId] ?? 0) : 0,
                'marketplace_rows' => $wcId > 0 ? ($links[$wcId] ?? []) : [],
                // The shop's picture first, the row's own second: a managed
                // product's thumbnail should be what a shopper sees.
                'thumbnail_id' => (int) ($thumbs[$wcId] ?? 0) > 0
                    ? (int) $thumbs[$wcId]
                    : (int) $row['row_main_image'],
                // A marketplace row whose `wc_product_id` points at a post
                // that is not there. Not the same as a row that never had one
                // — one is a draft and the other is a broken link, and only
                // this screen can show either.
                'orphan_link' => (int) $row['orphan_link'] === 1,
                // Read off the row this list drew, never asked again: the
                // whole reason the columns are in the union's SELECT.
                'submission' => ReviewQueueSql::token($row),
            ];
        }
        return $out;
    }

    /**
     * The union both halves of the list are read from, written once.
     *
     * Written twice — once for the rows and once for the count — it would be
     * two definitions of the list, and the number under the pager would
     * disagree with the rows above it the first time one was edited
     * (`DbProductRepository::managerScope()` learned this in `alpha.48`).
     *
     * The search is applied INSIDE each branch rather than outside the union:
     * `LIKE '%x%'` uses no index either way, but filtering before the union
     * is materialised is the difference between a temporary table of the
     * matches and one of the whole catalogue.
     *
     * @param array<string,mixed> $filters
     * @return array{0:string, 1:list<mixed>}
     */
    private function unionSql(array $filters): array
    {
        $posts = '`' . $this->db->prefix() . 'posts`';
        $products = '`' . $this->db->prefix() . M0005CreateProductTables::PRODUCTS . '`';
        $meta = '`' . $this->db->prefix() . 'postmeta`';
        $own = '`' . M0010LinkOwnership::COLUMN . '`';

        $ownership = 'CASE WHEN m.' . $own . ' = %s THEN %s WHEN m.' . $own . ' = %s THEN %s ELSE %s END';
        $ownershipParams = [
            LinkOwnership::Marketplace->value, CatalogueFacets::MANAGED_OURS,
            LinkOwnership::Observed->value, CatalogueFacets::MANAGED_OBSERVED,
            CatalogueFacets::MANAGED_NONE,
        ];
        // The identity of the submission on the row, IN THE SAME STATEMENT as
        // the row. This list marks what it SHOWS as seen, and `alpha.37`
        // measured what a second read costs: a vendor submitting between the
        // two would have their submission recorded as seen by a manager who
        // was looking at the previous one. Three correlated, indexed lookups
        // per row of an already limited result.
        [$submissionA, $submissionParams] = ReviewQueueSql::submissionColumns($this->db, 'm');
        [$submissionB] = ReviewQueueSql::submissionColumns($this->db, 'r');

        // Branch A: a WooCommerce product, with the FIRST marketplace row
        // pointing at it if there is one.
        $a = 'SELECT p.ID AS wc_product_id, COALESCE(m.id, 0) AS product_id,
                     p.post_title AS title, p.post_status AS wc_status,
                     p.post_author AS author_id,
                     COALESCE(m.' . $own . ', %s) AS ownership,
                     COALESCE(m.status, %s) AS m_status,
                     COALESCE(m.vendor_user_id, 0) AS vendor_user_id,
                     COALESCE(g.rows_seen, 0) AS rows_seen,
                     COALESCE(m.updated_at, p.post_modified) AS changed_at,
                     COALESCE(m.sku, %s) AS row_sku,
                     COALESCE(m.type, %s) AS row_type,
                     COALESCE(m.main_image_id, 0) AS row_main_image,
                     0 AS orphan_link,
                     CASE WHEN m.id IS NULL THEN %s ELSE %s END AS source,
                     ' . $ownership . ' AS management,
                     ' . $submissionA . '
                FROM ' . $posts . ' p
                LEFT JOIN (SELECT wc_product_id, MIN(id) AS first_id, COUNT(*) AS rows_seen
                             FROM ' . $products . '
                            WHERE wc_product_id IS NOT NULL
                            GROUP BY wc_product_id) g ON g.wc_product_id = p.ID
                LEFT JOIN ' . $products . ' m ON m.id = g.first_id
               WHERE p.post_type = %s';
        $aParams = array_merge(
            ['', '', '', ''],
            [CatalogueFacets::SOURCE_WOOCOMMERCE, CatalogueFacets::SOURCE_MARKETPLACE],
            $ownershipParams,
            $submissionParams,
            ['product']
        );

        // Branch B: a marketplace row with no WooCommerce product behind it.
        // `NOT EXISTS` rather than `wc_product_id IS NULL`, so a row pointing
        // at a post somebody deleted is in the list too — it is a real state
        // and the only screen that could show it is this one.
        $b = 'SELECT 0 AS wc_product_id, r.id AS product_id,
                     r.title AS title, %s AS wc_status, 0 AS author_id,
                     r.' . $own . ' AS ownership, r.status AS m_status,
                     r.vendor_user_id AS vendor_user_id, 1 AS rows_seen,
                     r.updated_at AS changed_at, r.sku AS row_sku, r.type AS row_type,
                     r.main_image_id AS row_main_image,
                     CASE WHEN r.wc_product_id IS NULL OR r.wc_product_id = 0 THEN 0 ELSE 1 END AS orphan_link,
                     %s AS source,
                     ' . str_replace('m.', 'r.', $ownership) . ' AS management,
                     ' . $submissionB . '
                FROM ' . $products . ' r
               WHERE NOT EXISTS (SELECT 1 FROM ' . $posts . ' p2
                                  WHERE p2.ID = r.wc_product_id AND p2.post_type = %s)';
        $bParams = array_merge(
            [CatalogueFacets::STATUS_ABSENT, CatalogueFacets::SOURCE_DRAFT],
            $ownershipParams,
            $submissionParams,
            ['product']
        );

        // The marketplace state machine's own status — the chips this list has
        // had since `alpha.32`. Applied per branch because the column lives
        // on a different alias in each, and a row with no marketplace row has
        // no status to match, so asking for one excludes it: correct, and the
        // reason the facets are separate questions.
        $status = trim((string) ($filters['marketplace_status'] ?? ''));
        if ($status !== '') {
            $a .= ' AND m.status = %s';
            $aParams[] = $status;
            $b .= ' AND r.status = %s';
            $bParams[] = $status;
        }

        // «نیازمند بررسی» — THE one definition, from the one fragment
        // (`ReviewQueueSql::waiting()`). Written out here instead it would be
        // a second definition of the queue, and the badge and this list would
        // stop describing the same set the day one of them learned about a
        // new waiting state («دو فهرست یعنی دو قاعده»).
        if (($filters['needs_review'] ?? false) === true) {
            [$waitingA, $waitingParams] = ReviewQueueSql::waiting($this->db, 'm');
            [$waitingB] = ReviewQueueSql::waiting($this->db, 'r');
            $a .= ' AND m.id IS NOT NULL AND ' . $waitingA;
            array_push($aParams, ...$waitingParams);
            $b .= ' AND ' . $waitingB;
            array_push($bParams, ...$waitingParams);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $exact = (int) $search;
            // The SKU is asked with `EXISTS` and not joined: a product with
            // two `_sku` rows would otherwise appear twice.
            $a .= ' AND (p.post_title LIKE %s OR p.ID = %d OR m.title LIKE %s OR m.sku LIKE %s
                         OR EXISTS (SELECT 1 FROM ' . $meta . ' sk
                                     WHERE sk.post_id = p.ID AND sk.meta_key = %s AND sk.meta_value LIKE %s))';
            array_push($aParams, $like, $exact, $like, $like, '_sku', $like);
            $b .= ' AND (r.title LIKE %s OR r.sku LIKE %s OR r.id = %d)';
            array_push($bParams, $like, $like, $exact);
        }

        return [$a . ' UNION ALL ' . $b, array_merge($aParams, $bParams)];
    }

    /**
     * The facet filters, applied to the union's own derived columns.
     *
     * Three independent conditions, because they are three independent facts
     * — «وضعیت انتشار با وضعیت ورود/مالکیت قاتی نشود». A combination that
     * cannot exist is an empty list rather than an error: it is a truthful
     * answer about a question somebody asked.
     *
     * @param array<string,mixed> $filters
     * @return array{0:string, 1:list<mixed>}
     */
    private function unifiedWhere(array $filters): array
    {
        $where = ' WHERE 1 = 1';
        $params = [];
        $source = CatalogueFacets::source((string) ($filters['source'] ?? ''));
        if ($source !== '') {
            $where .= ' AND u.source = %s';
            $params[] = $source;
        }
        $management = CatalogueFacets::management((string) ($filters['management'] ?? ''));
        if ($management !== '') {
            $where .= ' AND u.management = %s';
            $params[] = $management;
        }
        $status = CatalogueFacets::wcStatus((string) ($filters['wc_status'] ?? ''));
        if ($status !== '') {
            $where .= ' AND u.wc_status = %s';
            $params[] = $status;
        }
        return [$where, $params];
    }

    /**
     * **Every order ends in an id**, for the reason `ProductSort` states:
     * `changed_at` has second precision, so an import written in one second
     * has no order of its own and a pager that re-sorts those seconds
     * differently on page two shows one product twice.
     *
     * The tiebreaker is the WooCommerce id and then the row id, which
     * together are unique across both branches of the union — the row id
     * alone is not, because branch A's products carry whatever row id their
     * marketplace row has, and zero when they have none.
     */
    private function unifiedOrder(string $sort): string
    {
        $tail = ', u.wc_product_id DESC, u.product_id DESC';
        return match (ProductSort::fromKey($sort)) {
            ProductSort::Title => 'u.title ASC' . $tail,
            ProductSort::OldestChanged => 'u.changed_at ASC' . $tail,
            ProductSort::LastChanged => 'u.changed_at DESC' . $tail,
        };
    }

    /**
     * What the evidence says about each of these users, asked once per user.
     *
     * The bucket a row is in is decided from the AUTHOR's evidence, and a page
     * of twenty products has at most twenty distinct authors — usually two or
     * three. Asked per row it would be twenty `get_userdata()` calls and
     * twenty application lookups; asked per author it is as many as there are
     * authors, which is what makes the unified list affordable.
     *
     * The definitions are the ones `vendorSignals()` already uses, so the list
     * and a product's own page cannot disagree about who a Dokan seller is.
     *
     * @param list<int> $authorIds
     * @return array<int,array{author_id:int, author_exists:bool, author_roles:list<string>, author_is_admin:bool, dokan_seller:bool, vendor_profile:string}>
     */
    public function authorSignals(array $authorIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $authorIds)));
        // The paperwork for the whole page in two statements rather than two
        // per author. Measured on the disposable site before this: a page of
        // twenty cost twenty-four selects, almost all of them these.
        $paperwork = $this->vendors !== null && method_exists($this->vendors, 'profileStatusesFor')
            ? $this->vendors->profileStatusesFor($ids)
            : [];
        $out = [];
        foreach ($ids as $id) {
            $signals = [
                'author_id' => $id,
                'author_exists' => false,
                'author_roles' => [],
                'author_is_admin' => false,
                'dokan_seller' => false,
                'vendor_profile' => '',
            ];
            if ($id <= 0) {
                $out[$id] = $signals;
                continue;
            }
            $user = function_exists('get_userdata') ? get_userdata($id) : false;
            if ($user !== false && is_object($user)) {
                $signals['author_exists'] = true;
                $signals['author_roles'] = array_values(array_map('strval', (array) ($user->roles ?? [])));
                $signals['author_is_admin'] = in_array('administrator', $signals['author_roles'], true)
                    || in_array('shop_manager', $signals['author_roles'], true);
                $signals['dokan_seller'] = in_array('seller', $signals['author_roles'], true);
            }
            $signals['vendor_profile'] = (string) ($paperwork[$id] ?? '');
            $out[$id] = $signals;
        }
        return $out;
    }

    public function detail(int $wcProductId): array
    {
        if ($wcProductId <= 0 || !$this->isAvailable()) {
            return [];
        }
        $product = wc_get_product($wcProductId);
        if (!is_object($product) || !method_exists($product, 'get_id') || (int) $product->get_id() <= 0) {
            return [];
        }
        $brand = $this->brandOf($wcProductId);
        return [
            'wc_product_id' => (int) $product->get_id(),
            'type' => (string) $product->get_type(),
            'status' => (string) $product->get_status(),
            'catalog_visibility' => (string) $product->get_catalog_visibility(),
            'title' => (string) $product->get_name(),
            'slug' => (string) $product->get_slug(),
            'permalink' => function_exists('get_permalink') ? (string) get_permalink($wcProductId) : '',
            'short_description' => (string) $product->get_short_description(),
            'description' => (string) $product->get_description(),
            'sku' => (string) $product->get_sku(),
            // The decimal STRINGS, never floats: `alpha.39`'s rule, and the
            // caller decides what an inexact one means rather than rounding.
            'regular_price' => (string) $product->get_regular_price(),
            'sale_price' => (string) $product->get_sale_price(),
            'date_on_sale_from' => $this->dateOf($product, 'get_date_on_sale_from'),
            'date_on_sale_to' => $this->dateOf($product, 'get_date_on_sale_to'),
            'manage_stock' => (bool) $product->get_manage_stock(),
            'stock_quantity' => $product->get_stock_quantity() === null ? null : (int) $product->get_stock_quantity(),
            'stock_status' => (string) $product->get_stock_status(),
            'backorders' => (string) $product->get_backorders(),
            'sold_individually' => (bool) $product->get_sold_individually(),
            'weight' => (string) $product->get_weight(),
            'length' => (string) $product->get_length(),
            'width' => (string) $product->get_width(),
            'height' => (string) $product->get_height(),
            'tax_status' => (string) $product->get_tax_status(),
            'tax_class' => (string) $product->get_tax_class(),
            'shipping_class_id' => (int) $product->get_shipping_class_id(),
            'category_ids' => array_map('intval', (array) $product->get_category_ids()),
            'tag_ids' => array_map('intval', (array) $product->get_tag_ids()),
            'brand' => $brand['name'],
            'brand_term_id' => $brand['term_id'],
            'image_id' => (int) $product->get_image_id(),
            'gallery_image_ids' => array_map('intval', (array) $product->get_gallery_image_ids()),
            'attribute_count' => count((array) $product->get_attributes()),
            'variation_ids' => array_map('intval', (array) $product->get_children()),
            'upsell_ids' => array_map('intval', (array) $product->get_upsell_ids()),
            'cross_sell_ids' => array_map('intval', (array) $product->get_cross_sell_ids()),
            'menu_order' => (int) $product->get_menu_order(),
        ];
    }

    public function quantityRule(int $wcProductId): array
    {
        $meta = [];
        foreach (ProjectedQuantity::keys() as $key) {
            $meta[$key] = $wcProductId > 0 && function_exists('get_post_meta')
                ? (string) get_post_meta($wcProductId, $key, true)
                : '';
        }
        // `ProjectedQuantity::ruleFrom()` is the one reader of these three
        // rows, and reusing it is the point: a second interpretation of
        // «empty maximum» or «step 1» would be a second rule.
        $rule = ProjectedQuantity::ruleFrom($meta);
        return ['min' => $rule->min, 'max' => $rule->max, 'step' => $rule->step];
    }

    public function vendorSignals(int $wcProductId): array
    {
        $out = [
            'author_id' => 0,
            'author_exists' => false,
            'author_roles' => [],
            'author_is_admin' => false,
            'vendor_profile' => '',
            'vendor_profile_user' => 0,
            'dokan_seller' => false,
            'marketplace_vendor_meta' => 0,
            'marketplace_rows' => [],
        ];
        if ($wcProductId <= 0) {
            return $out;
        }
        $author = (int) ($this->db->getVar(
            'SELECT post_author FROM `' . $this->db->prefix() . 'posts` WHERE ID = %d',
            [$wcProductId]
        ) ?? 0);
        $out['author_id'] = $author;
        $out['marketplace_rows'] = $this->marketplaceRowsFor([$wcProductId])[$wcProductId] ?? [];
        if (function_exists('get_post_meta')) {
            $out['marketplace_vendor_meta'] = (int) get_post_meta(
                $wcProductId,
                WooCommerceProjector::VENDOR_META,
                true
            );
        }
        if ($author <= 0) {
            return $out;
        }
        $user = function_exists('get_userdata') ? get_userdata($author) : false;
        if ($user !== false && is_object($user)) {
            $out['author_exists'] = true;
            $out['author_roles'] = array_values(array_map('strval', (array) ($user->roles ?? [])));
            $out['author_is_admin'] = in_array('administrator', $out['author_roles'], true)
                || in_array('shop_manager', $out['author_roles'], true);
            // The SAME definition `WpDokanReader` uses — the `seller` role
            // inside the serialized capabilities meta — so the two readers
            // cannot disagree about who a Dokan seller is.
            $out['dokan_seller'] = in_array('seller', $out['author_roles'], true);
        }
        $application = $this->vendors?->findApplicationByUser($author);
        if ($application !== null) {
            $out['vendor_profile'] = $application->status->value;
            $out['vendor_profile_user'] = $author;
        } elseif ($this->vendors?->findProfileByUser($author) !== null) {
            // A profile with no application: an imported or hand-made shop.
            // Named rather than read as «no vendor», because it is one.
            $out['vendor_profile'] = 'profile_without_application';
            $out['vendor_profile_user'] = $author;
        }
        return $out;
    }

    public function publishedWithoutPrice(int $limit = 100): array
    {
        $posts = '`' . $this->db->prefix() . 'posts`';
        $products = '`' . $this->db->prefix() . M0005CreateProductTables::PRODUCTS . '`';
        $meta = '`' . $this->db->prefix() . 'postmeta`';
        // `MIN(id)` for the same reason `unionSql()` uses it: `tmc_products`
        // has no unique index on `wc_product_id`, so a plain join multiplies
        // the product by however many rows point at it (`alpha.56`).
        //
        // The shop's own price comes from a correlated subquery rather than a
        // join, because `wp_postmeta` is not unique on `(post_id, meta_key)`
        // either — measured on WordPress 7.1 in `alpha.54`.
        $rows = $this->db->getResults(
            'SELECT p.ID AS wc_product_id, m.id AS product_id, p.post_title AS title,
                    m.' . '`' . M0010LinkOwnership::COLUMN . '`' . ' AS ownership,
                    (SELECT mm.meta_value FROM ' . $meta . ' mm
                      WHERE mm.post_id = p.ID AND mm.meta_key = %s
                      ORDER BY mm.meta_id ASC LIMIT 1) AS shop_price
               FROM ' . $posts . ' p
               INNER JOIN (SELECT wc_product_id, MIN(id) AS first_id
                             FROM ' . $products . '
                            WHERE wc_product_id IS NOT NULL
                            GROUP BY wc_product_id) g ON g.wc_product_id = p.ID
               INNER JOIN ' . $products . ' m ON m.id = g.first_id
              WHERE p.post_type = %s AND p.post_status = %s AND m.price_minor <= 0
              ORDER BY p.ID ASC LIMIT %d',
            ['_regular_price', 'product', 'publish', max(1, min(500, $limit))]
        );
        $out = [];
        foreach ($rows as $row) {
            $price = $row['shop_price'];
            $out[] = [
                'wc_product_id' => (int) $row['wc_product_id'],
                'product_id' => (int) $row['product_id'],
                'title' => (string) $row['title'],
                // Absence told apart from emptiness, because they are two
                // different things to do next: one needs a price typed in,
                // the other needs the shop's own number brought across.
                'shop_price' => $price === null ? '__absent__' : ((string) $price === '' ? '__empty__' : (string) $price),
                'ownership' => (string) $row['ownership'],
            ];
        }
        return $out;
    }

    public function duplicateLinks(int $limit = 50): array
    {
        $rows = $this->db->getResults(
            'SELECT wc_product_id, COUNT(*) AS rows_seen
               FROM `' . $this->db->prefix() . 'tmc_products`
              WHERE wc_product_id IS NOT NULL
              GROUP BY wc_product_id HAVING COUNT(*) > 1
              ORDER BY wc_product_id ASC LIMIT %d',
            [max(1, min(200, $limit))]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'wc_product_id' => (int) $row['wc_product_id'],
                'rows' => (int) $row['rows_seen'],
            ];
        }
        return $out;
    }

    public function pluginKeys(int $wcProductId): array
    {
        if ($wcProductId <= 0) {
            return [];
        }
        // Keys only. `meta_value` is never selected, so this cannot become a
        // dump of another plugin's data.
        $rows = $this->db->getResults(
            'SELECT DISTINCT meta_key FROM `' . $this->db->prefix() . 'postmeta`
              WHERE post_id = %d',
            [$wcProductId]
        );
        $out = [];
        foreach ($rows as $row) {
            $key = (string) ($row['meta_key'] ?? '');
            foreach (self::KNOWN_PREFIXES as $prefix => $label) {
                if (str_starts_with($key, $prefix)) {
                    $out[$label] = ($out[$label] ?? 0) + 1;
                    continue 2;
                }
            }
        }
        return $out;
    }

    public function preservationRisks(int $wcProductId): array
    {
        $detail = $this->detail($wcProductId);
        if ($detail === []) {
            return ['product' => 'محصول خوانده نشد'];
        }
        $risks = [];
        if (!in_array((string) $detail['type'], self::SUPPORTED_TYPES, true)) {
            $risks['type'] = sprintf(
                'نوع «%s» در فرم این بازارگاه وجود ندارد و تبدیل نمی‌شود.',
                (string) $detail['type']
            );
        }
        if ((string) $detail['catalog_visibility'] !== 'visible') {
            // `project()` writes catalogue visibility from the marketplace
            // STATUS — `visible` for published, `hidden` otherwise — so any
            // other value is lost on the first projection. There is no field
            // for it in the four-step form.
            $risks['catalog_visibility'] = sprintf(
                'نمایش در فهرست «%s» است و این بازارگاه فیلدی برایش ندارد؛ اولین همگام‌سازی آن را به «visible» برمی‌گرداند.',
                (string) $detail['catalog_visibility']
            );
        }
        if ($detail['manage_stock'] === false) {
            $risks['manage_stock'] = 'مدیریت موجودی خاموش است و همگام‌سازی بازارگاه آن را روشن می‌کند.';
        }
        if ((string) $detail['backorders'] !== 'no') {
            $risks['backorders'] = sprintf(
                'سفارش پس‌افت «%s» است و این بازارگاه فیلدی برایش ندارد.',
                (string) $detail['backorders']
            );
        }
        if ($detail['sold_individually'] === true) {
            $risks['sold_individually'] = 'فروش تک‌واحدی روشن است؛ قاعدهٔ تعداد خرید بازارگاه کنار می‌کشد و تناقض ثبت می‌شود.';
        }
        foreach (['length' => 'طول', 'width' => 'عرض', 'height' => 'ارتفاع'] as $key => $label) {
            if (trim((string) $detail[$key]) !== '') {
                $risks['dimensions'] = 'ابعاد (طول/عرض/ارتفاع) در این بازارگاه یک فیلد متنی است و همگام‌سازی آن‌ها را نمی‌نویسد؛ مقدار فعلی ووکامرس دست‌نخورده می‌مانَد.';
                break;
            }
        }
        if ((int) $detail['shipping_class_id'] > 0) {
            $risks['shipping_class'] = 'کلاس ارسال در این بازارگاه فیلدی ندارد؛ مقدار فعلی دست‌نخورده می‌مانَد.';
        }
        if ($detail['tag_ids'] !== []) {
            $risks['tags'] = 'برچسب‌های محصول در این بازارگاه فیلدی ندارند؛ مقدار فعلی دست‌نخورده می‌مانَد.';
        }
        if ($detail['upsell_ids'] !== [] || $detail['cross_sell_ids'] !== []) {
            $risks['related'] = 'محصولات مرتبط (upsell/cross-sell) در این بازارگاه فیلدی ندارند؛ مقدار فعلی دست‌نخورده می‌مانَد.';
        }
        if (!$this->isIntegerAmount((string) $detail['regular_price'])
            || !$this->isIntegerAmount((string) $detail['sale_price'])) {
            $risks['price_scale'] = 'قیمت این محصول عدد صحیح نیست و این بازارگاه قیمت را به‌صورت عدد صحیح نگه می‌دارد؛ گرد کردنش ممنوع است.';
        }
        return $risks;
    }

    /** `''` and an exact integer both pass; anything with a fraction does not. */
    private function isIntegerAmount(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return true;
        }
        return preg_match('/^-?\d+(\.0+)?$/', $value) === 1;
    }

    private function dateOf(mixed $product, string $getter): string
    {
        if (!method_exists($product, $getter)) {
            return '';
        }
        $date = $product->{$getter}();
        if ($date === null) {
            return '';
        }
        return is_object($date) && method_exists($date, 'date') ? (string) $date->date('Y-m-d') : '';
    }

    /** @return array{name:string, term_id:int} */
    private function brandOf(int $wcProductId): array
    {
        if (!function_exists('wp_get_object_terms') || !function_exists('taxonomy_exists')
            || !taxonomy_exists(WooCommerceBrandDirectory::TAXONOMY)) {
            return ['name' => '', 'term_id' => 0];
        }
        $terms = wp_get_object_terms($wcProductId, WooCommerceBrandDirectory::TAXONOMY);
        if (!is_array($terms) || $terms === [] || !is_object($terms[0])) {
            return ['name' => '', 'term_id' => 0];
        }
        return ['name' => (string) $terms[0]->name, 'term_id' => (int) $terms[0]->term_id];
    }

    /**
     * @param list<int> $ids
     * @return array<int,string>
     */
    private function typesOf(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $rows = $this->db->getResults(
            'SELECT r.object_id, t.name AS type
               FROM `' . $this->db->prefix() . 'term_relationships` r
         INNER JOIN `' . $this->db->prefix() . 'term_taxonomy` tt
                 ON tt.term_taxonomy_id = r.term_taxonomy_id AND tt.taxonomy = %s
         INNER JOIN `' . $this->db->prefix() . 'terms` t ON t.term_id = tt.term_id
              WHERE r.object_id IN (' . $placeholders . ')',
            array_merge(['product_type'], $ids)
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['object_id']] = (string) $row['type'];
        }
        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int,string>
     */
    private function metaOf(array $ids, string $key): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $rows = $this->db->getResults(
            'SELECT post_id, meta_value FROM `' . $this->db->prefix() . 'postmeta`
              WHERE meta_key = %s AND post_id IN (' . $placeholders . ')
              ORDER BY meta_id ASC',
            array_merge([$key], $ids)
        );
        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['post_id'];
            // The LOWEST `meta_id` wins, which is what WordPress itself reads
            // — `wp_postmeta` has no unique index on `(post_id, meta_key)`.
            $out[$id] ??= (string) $row['meta_value'];
        }
        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int,int>
     */
    private function childCounts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $rows = $this->db->getResults(
            'SELECT post_parent, COUNT(*) AS total FROM `' . $this->db->prefix() . 'posts`
              WHERE post_type = %s AND post_parent IN (' . $placeholders . ')
              GROUP BY post_parent',
            array_merge(['product_variation'], $ids)
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['post_parent']] = (int) $row['total'];
        }
        return $out;
    }

    /**
     * Every marketplace row pointing at each of these products — ALL of them,
     * including a second one, because a duplicate link is a fact the inventory
     * has to be able to report.
     *
     * @param list<int> $ids
     * @return array<int,list<array{product_id:int, vendor_user_id:int, ownership:string, status:string}>>
     */
    private function marketplaceRowsFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $rows = $this->db->getResults(
            'SELECT id, wc_product_id, vendor_user_id, status, `' . M0010LinkOwnership::COLUMN . '` AS ownership
               FROM `' . $this->db->prefix() . 'tmc_products`
              WHERE wc_product_id IN (' . $placeholders . ')
              ORDER BY id ASC',
            $ids
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['wc_product_id']][] = [
                'product_id' => (int) $row['id'],
                'vendor_user_id' => (int) $row['vendor_user_id'],
                'ownership' => (string) $row['ownership'],
                'status' => (string) $row['status'],
            ];
        }
        return $out;
    }
}
