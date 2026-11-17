<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Product\Application\VariationRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ProductAttribute;
use Tecteb\Marketplace\Modules\Product\Domain\ProductVariation;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders as T;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0040AttributeRoles as Roles;

/** Attributes and variations on the plugin's own tables. */
final class DbVariationRepository implements VariationRepositoryInterface
{
    /** Memoised per INSTANCE and never for a `null` (`alpha.68`). */
    private ?bool $roles = null;

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock
    ) {
    }

    /**
     * Whether the attribute table carries the two role columns.
     *
     * `null` means the question could not be answered, which is a reason for
     * the caller to refuse a role change rather than write one that will not
     * be honoured.
     */
    public function rolesSupported(): ?bool
    {
        if ($this->roles === null) {
            $answer = Roles::carriedBy($this->db);
            if ($answer === null) {
                return null;        // not memoised: ask again next time
            }
            $this->roles = $answer;
        }
        return $this->roles;
    }

    public function attributes(int $productId): array
    {
        $rows = $this->db->getResults(
            'SELECT * FROM `' . $this->attributesTable() . '` WHERE product_id = %d ORDER BY sort_order ASC, id ASC',
            [$productId]
        );
        return array_map(static function (array $row): ProductAttribute {
            $options = json_decode((string) ($row['options'] ?? ''), true);
            return new ProductAttribute(
                (int) $row['id'],
                (string) $row['attr_key'],
                (string) $row['label'],
                is_array($options) ? array_values(array_map('strval', $options)) : [],
                (int) $row['sort_order'],
                // ⛔ A MISSING KEY IS «TRUE», NOT «FALSE», and that is the
                // compatibility argument rather than a convenience. This
                // release's files can serve one request against a table that
                // migration 40 has not yet reached (`alpha.67`: swapping the
                // files runs nothing), and in that state no vendor can ever
                // have expressed a different decision — every row means both
                // flags true, because that is what the projector wrote
                // unconditionally. `array_key_exists` rather than `??` so a
                // stored `0` stays a nought (`alpha.52`).
                !array_key_exists(Roles::VISIBLE, $row) || (int) $row[Roles::VISIBLE] === 1,
                !array_key_exists(Roles::VARIATION, $row) || (int) $row[Roles::VARIATION] === 1
            );
        }, $rows);
    }

    public function saveAttribute(
        int $productId,
        string $key,
        string $label,
        array $options,
        int $sort = 0,
        bool $visible = true,
        bool $variation = true
    ): int {
        // ⛔ THE ROLE COLUMNS ARE NAMED ONLY WHEN THEY EXIST. A statement that
        // names a column the table has not got fails as a whole, so on a
        // database this release's files reached before migration 40 did, an
        // unconditional write would lose the attribute itself rather than
        // just its roles (`alpha.67`). An unreadable catalogue takes the same
        // branch for the write; the SERVICE asks `rolesSupported()` and
        // refuses a role CHANGE there, so a vendor's decision is never
        // silently dropped at the moment they make it.
        $carried = $this->rolesSupported() === true;
        $columns = 'product_id, attr_key, label, options, sort_order, created_at'
            . ($carried ? ', ' . Roles::VISIBLE . ', ' . Roles::VARIATION : '');
        $values = '%d, %s, %s, %s, %d, %s' . ($carried ? ', %d, %d' : '');
        $update = 'label = VALUES(label), options = VALUES(options), sort_order = VALUES(sort_order)'
            . ($carried
                ? ', ' . Roles::VISIBLE . ' = VALUES(' . Roles::VISIBLE . ')'
                  . ', ' . Roles::VARIATION . ' = VALUES(' . Roles::VARIATION . ')'
                : '');
        $parameters = [
            $productId, $key, $label,
            (string) json_encode(array_values($options), JSON_UNESCAPED_UNICODE),
            $sort, $this->now(),
        ];
        if ($carried) {
            $parameters[] = $visible ? 1 : 0;
            $parameters[] = $variation ? 1 : 0;
        }
        $written = $this->db->execute(
            'INSERT INTO `' . $this->attributesTable() . '` (' . $columns . ')
             VALUES (' . $values . ')
             ON DUPLICATE KEY UPDATE ' . $update,
            $parameters
        );
        if ($written === null) {
            return 0;
        }
        return (int) $this->db->getVar(
            'SELECT id FROM `' . $this->attributesTable() . '` WHERE product_id = %d AND attr_key = %s',
            [$productId, $key]
        );
    }

    public function deleteAttribute(int $productId, int $attributeId): bool
    {
        return $this->db->execute(
            'DELETE FROM `' . $this->attributesTable() . '` WHERE id = %d AND product_id = %d',
            [$attributeId, $productId]
        ) !== null;
    }

    public function variations(int $productId): array
    {
        $rows = $this->db->getResults(
            'SELECT * FROM `' . $this->variationsTable() . '` WHERE product_id = %d ORDER BY id ASC',
            [$productId]
        );
        return array_map([$this, 'hydrate'], $rows);
    }

    public function findVariation(int $productId, int $variationId): ?ProductVariation
    {
        $row = $this->db->getRow(
            'SELECT * FROM `' . $this->variationsTable() . '` WHERE id = %d AND product_id = %d',
            [$variationId, $productId]
        );
        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Upsert by combination, never by id: two saves of «size=s» are the same
     * variation even if the caller lost the id in between, and the unique
     * index makes that true under concurrency as well.
     */
    public function saveVariation(int $productId, ProductVariation $variation): int
    {
        $now = $this->now();
        $combination = $variation->combination();
        $sale = $variation->salePriceMinor === null ? 'NULL' : '%d';
        $params = [
            $productId,
            $combination,
            (string) json_encode($variation->attributes, JSON_UNESCAPED_UNICODE),
            $variation->sku,
            $variation->priceMinor,
        ];
        if ($variation->salePriceMinor !== null) {
            $params[] = $variation->salePriceMinor;
        }
        array_push($params, $variation->stock, $variation->mediaId, $variation->enabled ? 1 : 0, $now, $now);

        $written = $this->db->execute(
            'INSERT INTO `' . $this->variationsTable() . '`
             (product_id, combination, attributes, sku, price_minor, sale_price_minor, stock, media_id, enabled, created_at, updated_at)
             VALUES (%d, %s, %s, %s, %d, ' . $sale . ', %d, %d, %d, %s, %s)
             ON DUPLICATE KEY UPDATE
               attributes = VALUES(attributes), sku = VALUES(sku), price_minor = VALUES(price_minor),
               sale_price_minor = VALUES(sale_price_minor), stock = VALUES(stock), media_id = VALUES(media_id),
               enabled = VALUES(enabled), updated_at = VALUES(updated_at)',
            $params
        );
        if ($written === null) {
            return 0;
        }
        return (int) $this->db->getVar(
            'SELECT id FROM `' . $this->variationsTable() . '` WHERE product_id = %d AND combination = %s',
            [$productId, $combination]
        );
    }

    public function deleteVariation(int $productId, int $variationId): bool
    {
        return $this->db->execute(
            'DELETE FROM `' . $this->variationsTable() . '` WHERE id = %d AND product_id = %d',
            [$variationId, $productId]
        ) !== null;
    }

    public function updateVariationStock(int $variationId, int $stock): bool
    {
        return $this->db->execute(
            'UPDATE `' . $this->variationsTable() . '` SET stock = %d, updated_at = %s WHERE id = %d',
            [$stock, $this->now(), $variationId]
        ) !== null;
    }

    public function linkVariation(int $variationId, ?int $wcVariationId): bool
    {
        $value = $wcVariationId === null || $wcVariationId <= 0 ? 'NULL' : '%d';
        $params = [];
        if ($value === '%d') {
            $params[] = $wcVariationId;
        }
        array_push($params, $this->now(), $variationId);
        return $this->db->execute(
            'UPDATE `' . $this->variationsTable() . '` SET wc_variation_id = ' . $value . ', updated_at = %s WHERE id = %d',
            $params
        ) !== null;
    }

    public function forProducts(array $productIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $rows = $this->db->getResults(
            'SELECT * FROM `' . $this->variationsTable() . '` WHERE product_id IN (' . $placeholders . ') ORDER BY product_id ASC, id ASC',
            $ids
        );
        return array_map([$this, 'hydrate'], $rows);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): ProductVariation
    {
        $attributes = json_decode((string) ($row['attributes'] ?? ''), true);
        $clean = [];
        foreach (is_array($attributes) ? $attributes : [] as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $clean[$key] = (string) $value;
            }
        }
        return new ProductVariation(
            (int) $row['id'],
            (int) $row['product_id'],
            $clean,
            (int) $row['price_minor'],
            $row['sale_price_minor'] === null ? null : (int) $row['sale_price_minor'],
            (string) $row['sku'],
            (int) $row['stock'],
            (int) $row['media_id'],
            (bool) (int) $row['enabled'],
            $row['wc_variation_id'] === null ? null : (int) $row['wc_variation_id']
        );
    }

    private function attributesTable(): string
    {
        return T::table($this->db, T::ATTRIBUTES);
    }

    private function variationsTable(): string
    {
        return T::table($this->db, T::VARIATIONS);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
