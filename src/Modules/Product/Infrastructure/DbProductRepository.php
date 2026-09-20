<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductSeo;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\ProductRowVersion;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0010LinkOwnership;
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
        string $importRunId = ''
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
            return 0;
        }
        // Connection-scoped, so two vendors inserting at the same moment
        // cannot be handed each other's id — which "ORDER BY id DESC" would.
        return (int) $this->db->getVar('SELECT LAST_INSERT_ID()');
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

    public function saveSpecs(int $productId, array $values, int $schemaVersion): bool
    {
        $now = $this->now();
        $ok = true;
        foreach ($values as $key => $value) {
            $written = $this->db->execute(
                'INSERT INTO `' . $this->specsTable() . '` (product_id, field_key, value, schema_version, updated_at)
                 VALUES (%d, %s, %s, %d, %s)
                 ON DUPLICATE KEY UPDATE value = VALUES(value), schema_version = VALUES(schema_version), updated_at = VALUES(updated_at)',
                [$productId, (string) $key, (string) $value, $schemaVersion, $now]
            );
            $ok = $ok && $written !== null;
        }
        $bumped = $this->db->execute(
            'UPDATE `' . $this->products() . '` SET spec_schema_version = %d, updated_at = %s WHERE id = %d',
            [$schemaVersion, $now, $productId]
        );
        return $ok && $bumped !== null;
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
    public function saveImages(int $productId, array $mediaIds, int $mainImageId): bool
    {
        $this->db->execute('DELETE FROM `' . $this->imagesTable() . '` WHERE product_id = %d', [$productId]);
        $ok = true;
        foreach (array_values($mediaIds) as $sort => $mediaId) {
            $written = $this->db->execute(
                'INSERT INTO `' . $this->imagesTable() . '` (product_id, media_id, sort_order) VALUES (%d, %d, %d)
                 ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)',
                [$productId, (int) $mediaId, (int) $sort]
            );
            $ok = $ok && $written !== null;
        }
        $main = $this->db->execute(
            'UPDATE `' . $this->products() . '` SET main_image_id = %d, updated_at = %s WHERE id = %d',
            [$mainImageId, $this->now(), $productId]
        );
        return $ok && $main !== null;
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
        $columns = ['title', 'type', 'category_key', 'brand', 'short_description', 'price_minor'];
        $placeholders = ['%s', '%s', '%s', '%s', '%s', '%d'];
        $params = [$d->title, $d->type, $d->categoryKey, $d->brand, $d->shortDescription, $d->priceMinor];

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
            isset($row['row_version']) ? (string) (int) $row['row_version'] : ''
        );
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
