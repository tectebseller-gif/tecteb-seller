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
 * **A read that fails is NOT «sound», and `alpha.49` corrected that.**
 * `alpha.48` let an unreadable `information_schema` through on the argument
 * that refusing every product save over a failed read is the narrower risk.
 * Measured on its own bytes, the price of that argument was
 * `reads_as_sound=yes problem=product_table_unreadable wrote=1`: the class
 * KNEW the read had failed, said «sound» anyway, and the write landed. The
 * owner's instruction is the correction — «ساختار نامعلوم با سالم یکی نباشد»
 * — and it is the right one, because the whole point of asking at the write is
 * that the write must not proceed on an assumption.
 *
 * So there are now THREE answers, and `isSound()` is true for one of them:
 *
 *  - **sound** — every column is the shape a write needs.
 *  - **unsound** — one or more columns are not, and they are named.
 *  - **unknown** — the read failed. `isKnown()` is false, `isSound()` is
 *    false, and the refusal has its own code (`schema_unknown`) so that
 *    «ستونی غلط است» is never said about a question nobody could answer.
 *
 * A failed read is NOT memoised, so the next write of the same request asks
 * again: a transient failure must not freeze a working site for the rest of a
 * page load, and a persistent one costs one query per write on a site that is
 * refusing writes anyway.
 */
final class ProductTableShape
{
    /**
     * What a write needs each column to be.
     *
     * `nullable` is the question SQL will not raise: a NOT NULL column with no
     * default refuses a write that omits it, while a NULLable column accepts
     * it and stores nothing. `default` is checked where the default IS the
     * meaning — `purchase_step`'s 1 is migration 24's whole backfill, and a 0
     * there would read as «no quantity may be bought» to anything that did not
     * coerce it.
     *
     * **`types` is new in `alpha.49`, and the measurement is why.** `alpha.48`
     * asked about nullability and default and not about the type, so narrowing
     * `stock` from `int` to `tinyint` kept its name, its nullability and its
     * default — every question that was being asked — and the vendor's 500
     * was stored as 127. Measured on the `alpha.48` bytes:
     * `column_now=tinyint(4) reads_as_sound=yes save_code=inventory_saved
     * asked_stock=500 row_stock=127`. Nothing raised an error because this
     * server's `sql_mode` has no `STRICT_TRANS_TABLES` (measured:
     * `ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION`),
     * which is the ordinary WordPress configuration — `wpdb` strips the strict
     * modes itself.
     *
     * A LIST of types rather than one, because **narrower is a loss and wider
     * is not**: a `bigint` where the migration declares `int` holds everything
     * an `int` holds, so refusing it would stop a site somebody had widened on
     * purpose. Within a family only, which is why `varchar` is not in the list
     * for `stock`: a number stored as text is not «more room», it is a
     * different column.
     *
     * `min_length` is the same question for the text columns, where the loss is
     * truncation rather than clamping: a `varchar(5)` title holds its name, its
     * NOT NULL and its empty default, and silently keeps five characters.
     *
     * @var array<string,array{nullable:bool, default:?string, types:list<string>, min_length:?int}>
     */
    private const REQUIRED = [
        'title' => ['nullable' => false, 'default' => null, 'types' => ['varchar'], 'min_length' => 190],
        'brand' => ['nullable' => false, 'default' => null, 'types' => ['varchar'], 'min_length' => 120],
        'sku' => ['nullable' => false, 'default' => null, 'types' => ['varchar'], 'min_length' => 64],
        'stock' => ['nullable' => false, 'default' => null, 'types' => ['int', 'bigint'], 'min_length' => null],
        'min_purchase' => ['nullable' => false, 'default' => '1', 'types' => ['int', 'bigint'], 'min_length' => null],
        'max_purchase' => ['nullable' => true, 'default' => null, 'types' => ['int', 'bigint'], 'min_length' => null],
        M0024PurchaseStep::STEP => [
            'nullable' => false, 'default' => '1', 'types' => ['int', 'bigint'], 'min_length' => null,
        ],
        M0023ProductDescriptionAndCreateToken::DESCRIPTION => [
            'nullable' => true, 'default' => null, 'types' => ['longtext'], 'min_length' => null,
        ],
        M0025BrandRegistry::BRAND_TERM => [
            'nullable' => true, 'default' => null, 'types' => ['bigint'], 'min_length' => null,
        ],
    ];

    /** @var list<string>|null the unsound columns, or null until asked */
    private ?array $unsound = null;

    /** False only after a read that did not come back (see `isKnown()`). */
    private bool $known = true;

    private string $problem = '';

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly string $table
    ) {
    }

    /**
     * Columns whose shape would make a write store wrong data.
     *
     * Empty means «nothing is wrong with the columns I could see», which is
     * NOT the same as sound: an unreadable `information_schema` answers empty
     * too. `isKnown()` tells the two apart and `isSound()` requires both, so
     * no caller can read this as a clean bill of health by accident.
     *
     * @return list<string>
     */
    public function unsoundColumns(): array
    {
        if ($this->unsound !== null) {
            return $this->unsound;
        }
        $unsound = [];
        $names = array_keys(self::REQUIRED);
        $placeholders = implode(', ', array_fill(0, count($names), '%s'));
        $rows = $this->db->getResults(
            'SELECT COLUMN_NAME AS name, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS col_default,
                    DATA_TYPE AS data_type, CHARACTER_MAXIMUM_LENGTH AS max_length
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
                AND COLUMN_NAME IN (' . $placeholders . ')',
            [$this->table, ...$names]
        );
        if ($rows === []) {
            // Either the table is not there at all or the read failed, and
            // this cannot tell which — so it says UNKNOWN and stops the write
            // under its own name. Nothing is memoised: the next write of this
            // request asks again, because a transient failure must not freeze
            // a working site for a whole page load.
            $this->problem = 'product_table_unreadable';
            $this->known = false;
            return [];
        }
        $this->known = true;
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
                $unsound[] = $column;
                continue;
            }
            $nullable = strtoupper((string) ($row['nullable'] ?? 'YES')) === 'YES';
            if ($nullable !== $wanted['nullable']) {
                $unsound[] = $column;
                continue;
            }
            // THE TYPE, asked before the default, because a column of another
            // type makes the default's spelling meaningless.
            if (!in_array(strtolower((string) ($row['data_type'] ?? '')), $wanted['types'], true)) {
                $unsound[] = $column;
                continue;
            }
            if ($wanted['min_length'] !== null
                && (int) ($row['max_length'] ?? 0) < $wanted['min_length']) {
                $unsound[] = $column;
                continue;
            }
            if ($wanted['default'] === null) {
                continue;                       // the default is not the meaning here
            }
            // MariaDB reports an absent default as the four-character string
            // `NULL` on a nullable column (measured on 10.11), so the
            // comparison is on the string either way.
            if ((string) ($row['col_default'] ?? '') !== $wanted['default']) {
                $unsound[] = $column;
            }
        }
        return $this->unsound = $unsound;
    }

    /**
     * Whether the shape could be read at all.
     *
     * The third answer, and the one `alpha.48` did not have. False means the
     * question has no answer — never «the answer is yes».
     */
    public function isKnown(): bool
    {
        $this->unsoundColumns();
        return $this->known;
    }

    /** Sound means KNOWN and with nothing wrong in it. Unknown is not sound. */
    public function isSound(): bool
    {
        return $this->unsoundColumns() === [] && $this->known;
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
        $this->known = true;
    }
}
