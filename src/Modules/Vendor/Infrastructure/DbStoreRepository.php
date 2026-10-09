<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\StoreRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\StoreSettings;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0003CreateStoreAndStaffTables as T;

/** Store settings on the plugin's own table; every value is a parameter. */
final class DbStoreRepository implements StoreRepositoryInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock
    ) {
    }

    public function find(int $vendorUserId): ?StoreSettings
    {
        $row = $this->db->getRow('SELECT * FROM `' . $this->table() . '` WHERE user_id = %d', [$vendorUserId]);
        if ($row === null) {
            return null;
        }
        return new StoreSettings(
            (string) $row['store_name'],
            (string) $row['city'],
            (string) ($row['intro'] ?? ''),
            (int) $row['logo_id'],
            (int) $row['banner_id'],
            (int) $row['preparation_days'],
            (string) $row['origin_warehouse'],
            $this->decodeList($row['carriers'] ?? ''),
            (bool) (int) $row['closed'],
            $row['closed_from'] !== null ? (string) $row['closed_from'] : null,
            $row['closed_to'] !== null ? (string) $row['closed_to'] : null,
            (string) $row['reopen_message'],
            $this->decodeMap($row['social'] ?? '')
        );
    }

    /**
     * The shop's settings — and, when a scope is given, ONLY those fields.
     *
     * **Why the scope exists.** The row is written with `INSERT … ON DUPLICATE
     * KEY UPDATE`, and until `alpha.41` the update half named all twelve
     * settings columns. So a save of the shipping tab rewrote the city and the
     * introduction too, from the `$current` the caller had read moments
     * earlier. Filtering the posted input (`StoreSettings::fieldsOfTab()`)
     * stops that save from carrying an EMPTY city — it does not stop it from
     * writing a city it read before somebody else changed it, and two browser
     * tabs open on two sections would each put the other's fields back.
     *
     * Naming only the columns in play removes the window rather than narrowing
     * it: a shipping save does not mention `city` in the SQL at all, so no
     * ordering of two saves can revert it. `saveBank()` below has worked this
     * way since `alpha.5`; this is the same shape, not a new idea.
     *
     * An empty scope writes no settings column — which is `bank`'s real
     * answer, and `INSERT` still creates the row for a shop that has none so
     * that «approved with no settings row» (the `alpha.23` 404) cannot happen
     * through this path.
     *
     * @param list<string>|null $fields field keys of `StoreSettings::TAB_FIELDS`;
     *                                  null writes every settings column, which
     *                                  is what the seed and the manager's own
     *                                  paths want.
     */
    public function save(int $vendorUserId, StoreSettings $settings, ?array $fields = null): bool
    {
        $now = $this->now();
        // The two DATE columns are the only nullable ones here, and a
        // placeholder cannot carry NULL through wpdb's prepare(): an empty
        // string reaches a DATE column and strict mode rejects the whole row.
        // So the literal is chosen here, and the value is still a parameter
        // whenever there IS one.
        $from = $settings->closedFrom === null ? 'NULL' : '%s';
        $to = $settings->closedTo === null ? 'NULL' : '%s';
        // column => [placeholder, value|null]. `null` means the literal is
        // already in the placeholder (the two dates), so nothing is bound.
        $columns = [
            'city' => ['%s', $settings->city],
            'intro' => ['%s', $settings->intro],
            'logo_id' => ['%d', $settings->logoId],
            'banner_id' => ['%d', $settings->bannerId],
            'preparation_days' => ['%d', $settings->preparationDays],
            'origin_warehouse' => ['%s', $settings->originWarehouse],
            'carriers' => ['%s', json_encode(array_values($settings->carriers), JSON_UNESCAPED_UNICODE)],
            'closed' => ['%d', $settings->closed ? 1 : 0],
            'closed_from' => [$from, $settings->closedFrom],
            'closed_to' => [$to, $settings->closedTo],
            'reopen_message' => ['%s', $settings->reopenMessage],
            'social' => ['%s', json_encode($settings->social, JSON_UNESCAPED_UNICODE)],
        ];
        // Which COLUMNS a field owns. Only `general`'s two pictures and
        // `closure`'s tick-plus-dates are more than one column each, and the
        // map is here rather than in the Domain because a column name is this
        // class's business.
        $owned = [
            'city' => ['city'],
            'intro' => ['intro'],
            'logo_id' => ['logo_id'],
            'banner_id' => ['banner_id'],
            'preparation_days' => ['preparation_days'],
            'origin_warehouse' => ['origin_warehouse'],
            'carriers' => ['carriers'],
            'closed' => ['closed'],
            'closed_from' => ['closed_from'],
            'closed_to' => ['closed_to'],
            'reopen_message' => ['reopen_message'],
            'social' => ['social'],
        ];
        $write = [];
        if ($fields === null) {
            $write = array_keys($columns);
        } else {
            foreach ($fields as $field) {
                foreach ($owned[$field] ?? [] as $column) {
                    $write[] = $column;
                }
            }
        }

        // The INSERT always carries every column — a new row needs values for
        // all of them, and for the ones out of scope those values are the
        // defaults a fresh shop gets. The UPDATE half is what the scope
        // narrows, and that is the half an existing row takes.
        $insertColumns = ['user_id', 'store_name'];
        $insertPlaceholders = ['%d', '%s'];
        $params = [$vendorUserId, $settings->storeName];
        foreach ($columns as $column => [$placeholder, $value]) {
            $insertColumns[] = $column;
            $insertPlaceholders[] = $placeholder;
            if ($placeholder !== 'NULL') {
                $params[] = $value;
            }
        }
        array_push($insertColumns, 'created_at', 'updated_at');
        array_push($insertPlaceholders, '%s', '%s');
        array_push($params, $now, $now);

        $updates = ['updated_at = VALUES(updated_at)'];
        foreach ($write as $column) {
            $updates[] = '`' . $column . '` = VALUES(`' . $column . '`)';
        }

        $sql = 'INSERT INTO `' . $this->table() . '` (' . implode(', ', $insertColumns) . ')
                VALUES (' . implode(', ', $insertPlaceholders) . ')
                ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);

        $saved = $this->db->execute($sql, $params) !== null;
        if ($saved) {
            self::announce($vendorUserId);
        }
        return $saved;
    }

    public function seedName(int $vendorUserId, string $storeName): bool
    {
        if ($storeName === '') {
            return false;
        }
        // Two statements, both idempotent, and neither can clobber a name
        // somebody chose: the INSERT only fires when there is no row, and the
        // UPDATE only fires while the name is still empty.
        $this->db->execute(
            'INSERT IGNORE INTO `' . $this->table() . '` (user_id, store_name, created_at, updated_at)
             VALUES (%d, %s, %s, %s)',
            [$vendorUserId, $storeName, $this->now(), $this->now()]
        );
        $filled = $this->db->execute(
            'UPDATE `' . $this->table() . '` SET store_name = %s, updated_at = %s
             WHERE user_id = %d AND store_name = \'\'',
            [$storeName, $this->now(), $vendorUserId]
        ) !== null;
        self::announce($vendorUserId);
        return $filled;
    }

    public function renameStore(int $vendorUserId, string $storeName): bool
    {
        $renamed = $this->db->execute(
            'UPDATE `' . $this->table() . '` SET store_name = %s, updated_at = %s WHERE user_id = %d',
            [$storeName, $this->now(), $vendorUserId]
        ) !== null;
        if ($renamed) {
            self::announce($vendorUserId);
        }
        return $renamed;
    }

    /**
     * «this shop's settings changed» — fired from the one place they can.
     *
     * The public page's cache listens to this rather than to the vendor route
     * that used to call it directly. That mattered: closing and reopening a
     * shop through any other path — a manager action, WP-CLI, a future
     * service — left the pre-closure page cached, because the invalidation
     * hung off the caller instead of the write. Measured on the disposable
     * site, where a reopen served a `hit` of the page from before the closure.
     *
     * An action rather than a direct `StorePageCache::forget()` so this class
     * keeps knowing nothing about caching, and so anything else that needs to
     * hear about a store change has a seam to use.
     */
    private static function announce(int $vendorUserId): void
    {
        if ($vendorUserId > 0 && function_exists('do_action')) {
            do_action('tmc_store_settings_saved', $vendorUserId);
        }
    }

    public function bank(int $vendorUserId): array
    {
        $row = $this->db->getRow(
            'SELECT bank_iban, bank_holder, bank_document_id, bank_status, settlement_on_hold
             FROM `' . $this->table() . '` WHERE user_id = %d',
            [$vendorUserId]
        );
        return [
            'iban' => (string) ($row['bank_iban'] ?? ''),
            'holder' => (string) ($row['bank_holder'] ?? ''),
            'document_id' => (int) ($row['bank_document_id'] ?? 0),
            'status' => (string) ($row['bank_status'] ?? 'none'),
            'on_hold' => (bool) (int) ($row['settlement_on_hold'] ?? 0),
        ];
    }

    public function saveBank(int $vendorUserId, string $iban, string $holder, int $documentId, string $status, bool $onHold): bool
    {
        $now = $this->now();
        $sql = 'INSERT INTO `' . $this->table() . '`
                (user_id, bank_iban, bank_holder, bank_document_id, bank_status, settlement_on_hold, created_at, updated_at)
                VALUES (%d, %s, %s, %d, %s, %d, %s, %s)
                ON DUPLICATE KEY UPDATE
                 bank_iban = VALUES(bank_iban), bank_holder = VALUES(bank_holder),
                 bank_document_id = VALUES(bank_document_id), bank_status = VALUES(bank_status),
                 settlement_on_hold = VALUES(settlement_on_hold), updated_at = VALUES(updated_at)';
        return $this->db->execute($sql, [
            $vendorUserId, $iban, $holder, $documentId, $status, $onHold ? 1 : 0, $now, $now,
        ]) !== null;
    }

    private function table(): string
    {
        return T::table($this->db, T::STORES);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    /** @return list<string> */
    private function decodeList(mixed $raw): array
    {
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
    }

    /** @return array<string,string> */
    private function decodeMap(mixed $raw): array
    {
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $out[$key] = (string) $value;
            }
        }
        return $out;
    }
}
