<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0023ProductDescriptionAndCreateToken;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0024PurchaseStep;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0025BrandRegistry;

/**
 * Whether `tmc_products` is the shape a product write needs — asked at the
 * WRITE, not at the migration.
 *
 * **The finding this closes.** `alpha.45` added `purchase_step INT NOT NULL
 * DEFAULT 1` and its `verify()` checks the SHAPE rather than the name, for the
 * reason `alpha.37` wrote down: a column of the right name and the wrong shape
 * satisfies «a column of that name exists» and then lies. That guard worked —
 * measured on a mis-shaped column, `verify()` refused and the schema version
 * stayed at 23. What it did NOT do was stop the WRITE: SQL does not mind a
 * nullable column defaulting to nought, so saving a product landed (`wrote=1`),
 * carried the wrong default, and `PurchaseQuantity::of()` turned the nought
 * back into 1 — so nothing anywhere looked wrong and the column stayed
 * invisible. It was carried open through `alpha.46` and `alpha.47`, with the
 * protection being an ORDER: confirm the structure, then save products.
 *
 * An order is not a guard. §6 of the owner's instruction says so: «اگر ساختار
 * لازم سالم نیست، نوشتن وابسته نباید دادهٔ غلط ذخیره کند یا موفق گزارش شود.
 * شمارهٔ ساختار به‌تنهایی کافی نیست.»
 *
 * **So this reads the shape, and not the version.** That is the whole design
 * decision. A version number says what the migration runner BELIEVES; it
 * cannot see a column somebody altered by hand, a restore from a backup of an
 * older schema, or a migration that half ran. Reading the columns answers both
 * questions at once: a column that is MISSING and a column that is present and
 * wrong both come back as unsound, so «the structure is behind» needs no
 * separate check.
 *
 * **And it does not stop the healthy path.** One query, memoised for the
 * request, over `information_schema` for one table — the same read every
 * migration's `verify()` already does, once instead of twenty-five times.
 *
 * **A read that fails is NOT a refusal.** `unknown` is its own answer and it
 * ALLOWS the write, which is deliberate and is the narrower risk: the finding
 * is about a column whose shape is positively wrong, and refusing every
 * product save because `information_schema` could not be read would stop a
 * working site over a failure that is not evidence of anything. The failure is
 * reported by `lastProblem()` instead, and the health page already shows a
 * migration that did not finish.
 */
final class ProductTableShape
{
    /**
     * What a write needs each column to be.
     *
     * `nullable` is the question that matters, because it is the one SQL will
     * not raise: a NOT NULL column with no default refuses a write that omits
     * it, while a NULLable column accepts it and stores nothing. `default` is
     * checked where the default IS the meaning — `purchase_step`'s 1 is
     * migration 24's whole backfill, and a 0 there would read as «no quantity
     * may be bought» to anything that did not coerce it.
     *
     * @var array<string,array{nullable:bool, default:?string}>
     */
    private const REQUIRED = [
        'title' => ['nullable' => false, 'default' => null],
        'brand' => ['nullable' => false, 'default' => null],
        'sku' => ['nullable' => false, 'default' => null],
        'stock' => ['nullable' => false, 'default' => null],
        'min_purchase' => ['nullable' => false, 'default' => '1'],
        'max_purchase' => ['nullable' => true, 'default' => null],
        M0024PurchaseStep::STEP => ['nullable' => false, 'default' => '1'],
        M0023ProductDescriptionAndCreateToken::DESCRIPTION => ['nullable' => true, 'default' => null],
        M0025BrandRegistry::BRAND_TERM => ['nullable' => true, 'default' => null],
    ];

    /** @var list<string>|null the unsound columns, or null until asked */
    private ?array $unsound = null;

    private string $problem = '';

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly string $table
    ) {
    }

    /**
     * Columns whose shape would make a write store wrong data.
     *
     * Empty means sound, and empty is ALSO what an unreadable
     * `information_schema` answers — see the class docblock. `lastProblem()`
     * tells the two apart for anything that wants to say so.
     *
     * @return list<string>
     */
    public function unsoundColumns(): array
    {
        if ($this->unsound !== null) {
            return $this->unsound;
        }
        $this->unsound = [];
        $names = array_keys(self::REQUIRED);
        $placeholders = implode(', ', array_fill(0, count($names), '%s'));
        $rows = $this->db->getResults(
            'SELECT COLUMN_NAME AS name, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS col_default
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
                AND COLUMN_NAME IN (' . $placeholders . ')',
            [$this->table, ...$names]
        );
        if ($rows === []) {
            // Either the table is not there at all or the read failed, and
            // this cannot tell which. Activation and the upgrade gate both
            // answer the first; neither is this method's business, and
            // refusing every write here would stop a site whose only problem
            // is that something could not be read.
            $this->problem = 'product_table_unreadable';
            return $this->unsound;
        }
        $found = [];
        foreach ($rows as $row) {
            $found[(string) ($row['name'] ?? '')] = $row;
        }
        foreach (self::REQUIRED as $column => $wanted) {
            $row = $found[$column] ?? null;
            if ($row === null) {
                // A column the write names and the table does not have. This
                // is «the structure is behind» arriving as a fact rather than
                // as a version number.
                $this->unsound[] = $column;
                continue;
            }
            $nullable = strtoupper((string) ($row['nullable'] ?? 'YES')) === 'YES';
            if ($nullable !== $wanted['nullable']) {
                $this->unsound[] = $column;
                continue;
            }
            if ($wanted['default'] === null) {
                continue;                       // the default is not the meaning here
            }
            // MariaDB reports an absent default as the four-character string
            // `NULL` on a nullable column (measured on 10.11), so the
            // comparison is on the string either way.
            if ((string) ($row['col_default'] ?? '') !== $wanted['default']) {
                $this->unsound[] = $column;
            }
        }
        return $this->unsound;
    }

    public function isSound(): bool
    {
        return $this->unsoundColumns() === [];
    }

    /** Why the answer may be empty without being a clean bill of health. */
    public function lastProblem(): string
    {
        $this->unsoundColumns();
        return $this->problem;
    }

    /** For a test that alters a column and asks again in the same request. */
    public function forget(): void
    {
        $this->unsound = null;
        $this->problem = '';
    }
}
