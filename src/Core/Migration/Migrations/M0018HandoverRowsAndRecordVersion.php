<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Migration\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;

/**
 * Two tables the balance hand-over needs before it can be honest about
 * concurrency: a version counter per shop, and one row per decision.
 *
 * ### Why a counter and not another read
 *
 * `alpha.19` guarded acceptance by re-reading the figures and comparing a
 * hash. That catches the slow case — a manager who left the page open while
 * an import ran — and misses the fast one entirely, because a comparison made
 * between a read and a write is a comparison two writers both pass. It is the
 * same defect the product form had in `alpha.13` and the same fix
 * (`row_version`, migration 14): the check belongs in a `WHERE` clause, over a
 * counter the database moves, not in PHP over values PHP happened to read.
 *
 * So `records_version` is bumped **in the same transaction as every write to
 * that shop's imported rows**. Bumping after a committed write would leave a
 * window where the rows are visible under the old version — a reader could
 * take a figure that had already moved and be told it had not. Because the
 * bump and the insert commit together, a reader holding the version row's
 * lock cannot see rows whose bump has not landed.
 *
 * ### Why the decisions leave the option
 *
 * They were one serialised array in `wp_options`: read the whole map, set one
 * shop's key, write the whole map back. Two managers deciding two DIFFERENT
 * shops at the same moment both read the map, and the second write silently
 * dropped the first manager's decision — a lost decision about money, with no
 * error anywhere. One row per shop removes the interference instead of
 * narrowing it: two decisions about two shops are two rows and never race.
 *
 * The existing option is copied across here rather than left behind, because
 * a site that upgrades mid-review must not find its recorded decisions gone.
 * The option itself is left in place, untouched, so a rollback to `alpha.19`
 * still reads what it wrote.
 */
final class M0018HandoverRowsAndRecordVersion implements MigrationInterface
{
    public const VERSION_TABLE = 'tmc_dokan_shop_version';
    public const HANDOVER = 'tmc_dokan_handover';

    public const TABLES = [self::VERSION_TABLE, self::HANDOVER];

    /** The option the decisions used to live in, carried across below. */
    public const LEGACY_OPTION = 'tmc_dokan_finance_handover';

    public function version(): int
    {
        return 18;
    }

    public function id(): string
    {
        return '0018_handover_rows_and_record_version';
    }

    public static function table(DatabaseInterface $db, string $suffix): string
    {
        return $db->prefix() . $suffix;
    }

    public function up(DatabaseInterface $db): void
    {
        $collate = $db->charsetCollate();

        // One row per shop, and the ONLY thing in it that matters is a number
        // that goes up. It is not a fingerprint of the figures: a fingerprint
        // collides when a rollback restores exactly what was there before,
        // and «the numbers came back» is still a change a decision must not
        // be silently carried across.
        $version = self::table($db, self::VERSION_TABLE);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$version}` (
            `vendor_user_id` BIGINT UNSIGNED NOT NULL,
            `records_version` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`vendor_user_id`)
        ) {$collate}");

        // `closing_at_decision` is DECIMAL(19,4) — the same type Dokan uses
        // and the same type the imported rows use — so the frozen figure is
        // stored as the number it is rather than as text that has to be
        // trusted. `records_version` records WHICH version of the imported
        // past the decision was taken against, so «what did they agree to»
        // has an answer that survives a later re-import.
        $handover = self::table($db, self::HANDOVER);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$handover}` (
            `vendor_user_id` BIGINT UNSIGNED NOT NULL,
            `decision` VARCHAR(30) NOT NULL DEFAULT 'open',
            `closing_at_decision` DECIMAL(19,4) NOT NULL DEFAULT 0,
            `decided_at` DATETIME NULL,
            `decided_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `note` TEXT NULL,
            `pending_requests_untouched` INT UNSIGNED NOT NULL DEFAULT 0,
            `figures_token` VARCHAR(64) NOT NULL DEFAULT '',
            `records_version` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`vendor_user_id`),
            KEY `tmc_handover_decision` (`decision`)
        ) {$collate}");

        $this->carryDecisionsAcross($db, $handover);
    }

    public function verify(DatabaseInterface $db): bool
    {
        foreach (self::TABLES as $suffix) {
            $table = self::table($db, $suffix);
            if ((string) $db->getVar('SHOW TABLES LIKE %s', [$table]) !== $table) {
                return false;
            }
        }
        return true;
    }

    /**
     * Copy whatever the option holds into rows, once.
     *
     * `INSERT IGNORE` rather than a replace: running the migration twice must
     * not overwrite a decision somebody has taken since the first run. The
     * option is read straight from the options table because a migration runs
     * before the plugin's own option adapter is necessarily available, and
     * because that is where WordPress actually keeps it.
     */
    private function carryDecisionsAcross(DatabaseInterface $db, string $handover): void
    {
        $raw = $db->getVar(
            'SELECT option_value FROM `' . $db->prefix() . 'options` WHERE option_name = %s',
            [self::LEGACY_OPTION]
        );
        if (!is_string($raw) || $raw === '') {
            return;
        }
        // A hand-edited or truncated option must not abort an upgrade: the
        // decisions are recoverable from the audit log, the schema is not.
        $decoded = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($decoded)) {
            return;
        }
        foreach ($decoded as $vendorUserId => $record) {
            if (!is_array($record)) {
                continue;
            }
            $vendorUserId = (int) $vendorUserId;
            if ($vendorUserId <= 0) {
                continue;
            }
            $decidedAt = trim((string) ($record['decided_at'] ?? ''));
            $closing = trim((string) ($record['closing_at_decision'] ?? ''));
            $this->run($db, 'INSERT IGNORE INTO `' . $handover . '`
                 (vendor_user_id, decision, closing_at_decision, decided_at, decided_by,
                  note, pending_requests_untouched, figures_token, records_version, updated_at)
                 VALUES (%d, %s, %s, %s, %d, %s, %d, %s, %d, %s)', [
                $vendorUserId,
                mb_substr((string) ($record['decision'] ?? 'open'), 0, 30),
                // is_numeric, not a cast: '' would become 0 and invent a
                // frozen figure of zero for a decision that recorded none.
                is_numeric($closing) ? $closing : '0',
                $decidedAt === '' || str_starts_with($decidedAt, '0000-') ? null : $decidedAt,
                (int) ($record['decided_by'] ?? 0),
                (string) ($record['note'] ?? ''),
                (int) ($record['pending_requests_untouched'] ?? 0),
                mb_substr((string) ($record['figures_token'] ?? ''), 0, 64),
                // The old rows were never taken against a counted version.
                // Zero says exactly that, and reads back as «unknown».
                0,
                $decidedAt === '' || str_starts_with($decidedAt, '0000-')
                    ? gmdate('Y-m-d H:i:s')
                    : $decidedAt,
            ]);
        }
    }

    /** `execute()` answers with a row count, and a successful DDL affects none. */
    private function run(DatabaseInterface $db, string $sql, array $params = []): void
    {
        if ($db->execute($sql, $params) === null) {
            throw new MigrationException('migration ' . $this->id() . ' failed: ' . $db->lastError());
        }
    }
}
