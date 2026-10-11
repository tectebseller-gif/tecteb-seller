<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;

/**
 * One column on `tmc_products`: the purchase step.
 *
 * **Why this round has a migration at all.** The owner's §4 asks for three
 * purchase-quantity rules — minimum, maximum and STEP. Two of them have had
 * columns since migration 5 (`min_purchase INT NOT NULL DEFAULT 1`,
 * `max_purchase INT NULL`); the third has never existed anywhere in this
 * plugin. `tmc_products` is one column per field, so there is nowhere to put
 * it, and the alternatives were weighed rather than skipped:
 *
 *  - **post meta on the WooCommerce product.** That is the SHOP's copy, which
 *    this round writes as well — but it cannot be the source of truth: a
 *    marketplace product exists, is formed and is validated before it is ever
 *    projected, and a draft that has never reached WooCommerce has no post to
 *    hang meta on. The vendor form would then have a field it could not save.
 *  - **packing it into `max_purchase`.** Two numbers in one column, and the
 *    first reader to forget the encoding writes a maximum of 30001.
 *  - **a side table keyed by product.** A whole table, an extra join on every
 *    read, and a second place a product's selling rules can be half-written —
 *    for one small integer that belongs beside the two it works with.
 *
 * `INT NOT NULL DEFAULT 1` and **no backfill**, because the default IS the
 * backfill: `1` means «no stepping», which is exactly what every existing row
 * does today and what `PurchaseQuantity::of()` reads it as. There is no state
 * to guess at and nothing that could be guessed wrong — unlike migration 23's
 * `description`, where `NULL` had to mean «this row predates the field»
 * precisely because an empty string would have been an instruction.
 *
 * **Rolling back to `alpha.44` needs no database work.** The column is
 * additive and nothing older names it; `alpha.44` reads none of the three
 * quantity rules anywhere, which is the defect this round fixes. The cost is
 * stated in `docs/upgrade-and-rollback.md` §28 and it is the rules going
 * unenforced again, not data loss: the numbers stay in their columns and in
 * the shop's own meta.
 */
final class M0024PurchaseStep implements MigrationInterface
{
    public const PRODUCTS = 'tmc_products';
    public const STEP = 'purchase_step';

    public function version(): int
    {
        return 24;
    }

    public function id(): string
    {
        return '0024_purchase_step';
    }

    public static function table(DatabaseInterface $db, string $suffix): string
    {
        return $db->prefix() . $suffix;
    }

    public function up(DatabaseInterface $db): void
    {
        $table = self::table($db, self::PRODUCTS);
        // Asked in THREE states — `alpha.38`'s rule. A failed read answers
        // `null`, which casts to nought, which would read as «the column is
        // not there» and send an `ALTER` that then fails on a resume.
        $has = $db->getVar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            [$table, self::STEP]
        );
        if ($has === null) {
            throw new MigrationException(
                'migration ' . $this->id() . ' failed asking for `' . self::STEP . '`: ' . $db->lastError()
            );
        }
        if ((int) $has === 1) {
            return;                     // a resumed run; already done
        }
        if ($db->execute(
            "ALTER TABLE `{$table}` ADD COLUMN `" . self::STEP . '` INT NOT NULL DEFAULT 1 AFTER `max_purchase`'
        ) === null) {
            throw new MigrationException(
                'migration ' . $this->id() . ' failed: ' . $db->lastError()
            );
        }
    }

    /**
     * The SHAPE, not the existence — `alpha.37`'s rule.
     *
     * The default is checked as well as the column, because a column added
     * NULLable or defaulting to zero would satisfy «a column of that name
     * exists» and then hand `PurchaseQuantity::of()` a zero on every legacy
     * row. `of()` would coerce it back to 1 and nothing would break, which is
     * exactly why this has to be checked here: a silently wrong column that
     * only a coercion saves is a column the next reader trusts.
     */
    public function verify(DatabaseInterface $db): bool
    {
        $row = $db->getRow(
            'SELECT COLUMN_DEFAULT AS col_default, IS_NULLABLE AS nullable
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            [self::table($db, self::PRODUCTS), self::STEP]
        );
        if ($row === null) {
            return false;
        }
        return (int) ($row['col_default'] ?? 0) === 1
            && strtoupper((string) ($row['nullable'] ?? 'YES')) === 'NO';
    }
}
