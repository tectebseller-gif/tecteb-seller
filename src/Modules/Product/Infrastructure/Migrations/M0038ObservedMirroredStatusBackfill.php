<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;

/**
 * No new column — a BACKFILL, for the rows migration 32 deliberately left
 * alone.
 *
 * ### Why a round that adds no structure still has a migration
 *
 * `M0032ProductMirroredStatus` added `mirrored_status` and wrote nothing into
 * it, and its own docblock gives the reason: a backfill «would have to guess
 * which of today's rows were intaken before this column existed and what the
 * shop held at the time, and the second half of that is not recoverable».
 * That was the right call for the rows that existed in `alpha.67` — rows the
 * marketplace created itself, whose status IS its own decision.
 *
 * ⛔ **It is the wrong answer for the 184 rows the Dokan intake made**, and
 * the reason is that the Dokan path never passed `create()`'s seventh
 * argument at all. So those rows are not «intaken before the column existed»;
 * they are intaken rows whose stamp was dropped by a defect, measured in
 * `docs/dokan-exit-drift-analysis-alpha91-fa.md` §2. `alpha.92` fixes the
 * path, which fixes nothing already on disk: without this step every one of
 * shop 102's rows keeps an empty stamp, and the first projection after an
 * ownership transfer writes `draft` + `hidden` over a product its vendor is
 * selling today.
 *
 * ### What it writes, and the approximation it does NOT hide
 *
 * `mirrored_status = wp_posts.post_status`, read NOW.
 *
 * ⛔ That is the WooCommerce product's status today, and «what the intake
 * saw» is a different sentence: the status may have moved since. The two
 * agree for every row nobody has touched, and where they do not, today's
 * value is the one that matters — the whole purpose of the stamp is to stop a
 * projection changing what a customer currently sees. Recording today's
 * status therefore protects today's product, which is the question being
 * asked. It is still an approximation and it is written here rather than
 * implied, because §32's objection was about guessing, and a guess that is
 * documented is a different thing from one that is not.
 *
 * ### Why it is scoped to `observed` rows with no stamp
 *
 *  * **`mirrored_status IS NULL`** — a stamp already there is either a
 *    catalogue intake's (correct) or a value a manager's decision has since
 *    cleared and re-set. Overwriting it could hold a status open that a human
 *    deliberately released.
 *  * **`link_ownership = 'observed'`** — the rows the marketplace itself
 *    created must NOT be stamped. A stamp freezes the status write, so
 *    stamping a marketplace-owned published product would stop the projector
 *    maintaining a status that IS this marketplace's to decide, until the next
 *    explicit transition cleared it again. Harmless in value and wrong in
 *    meaning, and `alpha.92` adds no behaviour nobody asked for.
 *  * **the JOIN to `wp_posts`** — a row whose WooCommerce product has been
 *    deleted gets nothing, and must get nothing: there is no status to record.
 *    Those rows keep a NULL stamp and `TransferOwnership::take()` refuses them
 *    BY NAME (`storefront_status_unreadable`), which is the «عملیات متوقف
 *    شود» half of the owner's §1. A backfill that invented `draft` for them
 *    would have closed the gate by writing the very value the gate exists to
 *    prevent.
 *
 * Additive in effect as well as in shape: no column is created, altered or
 * dropped, and no row that already carries a value is rewritten.
 *
 * **Rolling back to `alpha.91` needs no database work.** The column stays, the
 * values stay, and `alpha.91`'s projector reads the stamp exactly as this one
 * does — so the rows this step filled are MORE protected under the older
 * package, not less. The cost is stated in `docs/upgrade-and-rollback.md` §72
 * and it is that the older package's Dokan intake drops the stamp again for
 * any NEW import.
 */
final class M0038ObservedMirroredStatusBackfill implements MigrationInterface
{
    public const PRODUCTS = 'tmc_products';

    public function version(): int
    {
        return 38;
    }

    public function id(): string
    {
        return '0038_observed_mirrored_status_backfill';
    }

    /**
     * Whether WordPress's own `posts` table is there to be joined.
     *
     * Three answers, because two is how `alpha.38` lost a step: `null` means
     * the catalogue could not be read and the step must be tried again,
     * `false` means there is no posts table and there is therefore nothing to
     * backfill, and `true` means do the work.
     *
     * ⛔ **And «no posts table» is a safe skip rather than a failure.** No
     * posts table means no WooCommerce products, so no imported row can have
     * one — and the consequence of not filling a stamp is that
     * `TransferOwnership::take()` REFUSES that row by name. The conservative
     * direction here is to leave more work undone, not less (`alpha.89`), and
     * a migration that threw because an unrelated table was absent would
     * block the whole chain over a row that cannot exist.
     */
    private function hasPosts(DatabaseInterface $db): ?bool
    {
        $count = $db->getVar(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            [$db->prefix() . 'posts']
        );
        return $count === null ? null : (int) $count === 1;
    }

    public function up(DatabaseInterface $db): void
    {
        $posts = $db->prefix() . 'posts';
        $carried = $this->hasPosts($db);
        if ($carried === null) {
            throw new MigrationException(
                'migration ' . $this->id() . ' failed asking for `' . $posts . '`: ' . $db->lastError()
            );
        }
        if ($carried === false) {
            return;                     // nothing to backfill; see `hasPosts()`
        }
        $products = $db->prefix() . self::PRODUCTS;
        $written = $db->execute(
            'UPDATE `' . $products . '` p
               JOIN `' . $posts . '` o
                 ON o.ID = p.wc_product_id AND o.post_type = %s
                SET p.`' . M0032ProductMirroredStatus::MIRRORED . '` = LEFT(o.post_status, 20)
              WHERE p.`' . M0032ProductMirroredStatus::MIRRORED . '` IS NULL
                AND p.wc_product_id IS NOT NULL
                AND p.wc_product_id > 0
                AND p.`' . M0010LinkOwnership::COLUMN . '` = %s',
            ['product', LinkOwnership::Observed->value]
        );
        // Nought rows is a SUCCESS and `null` is the failure (`alpha.8`): a
        // site with no imported rows has nothing to fill, and a site whose
        // statement was refused must not be recorded as filled.
        if ($written === null) {
            throw new MigrationException(
                'migration ' . $this->id() . ' failed: ' . $db->lastError()
            );
        }
    }

    /**
     * Whether any row this step is FOR is still waiting — asked with the very
     * predicate `up()` writes with.
     *
     * ⛔ Including the JOIN, and that is the whole care in this method. A
     * verify that counted observed rows with a NULL stamp and no join would
     * answer «not done» for every row whose WooCommerce product has been
     * deleted — rows `up()` cannot fill and must not fill — and the chain
     * would lock on 37 for ever on a real site, retried every five minutes
     * (`alpha.46`). The condition is the same condition, so what `up()`
     * cannot reach `verify()` does not wait for (`alpha.89`).
     *
     * A read that fails answers `null`, which is NOT nought: the step is
     * reported unfinished and tried again, because «I could not count» must
     * never read as «there is nothing left».
     */
    public function verify(DatabaseInterface $db): bool
    {
        $carried = $this->hasPosts($db);
        if ($carried === null) {
            return false;               // could not ask; try again
        }
        if ($carried === false) {
            return true;                // nothing to backfill; see `hasPosts()`
        }
        $products = $db->prefix() . self::PRODUCTS;
        $posts = $db->prefix() . 'posts';
        $left = $db->getVar(
            'SELECT COUNT(*) FROM `' . $products . '` p
               JOIN `' . $posts . '` o
                 ON o.ID = p.wc_product_id AND o.post_type = %s
              WHERE p.`' . M0032ProductMirroredStatus::MIRRORED . '` IS NULL
                AND p.wc_product_id IS NOT NULL
                AND p.wc_product_id > 0
                AND p.`' . M0010LinkOwnership::COLUMN . '` = %s',
            ['product', LinkOwnership::Observed->value]
        );
        if ($left === null) {
            return false;
        }
        return (int) $left === 0;
    }
}
