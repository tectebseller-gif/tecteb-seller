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

    public function save(int $vendorUserId, StoreSettings $settings): bool
    {
        $now = $this->now();
        // The two DATE columns are the only nullable ones here, and a
        // placeholder cannot carry NULL through wpdb's prepare(): an empty
        // string reaches a DATE column and strict mode rejects the whole row.
        // So the literal is chosen here, and the value is still a parameter
        // whenever there IS one.
        $from = $settings->closedFrom === null ? 'NULL' : '%s';
        $to = $settings->closedTo === null ? 'NULL' : '%s';
        $sql = 'INSERT INTO `' . $this->table() . '`
                (user_id, store_name, city, intro, logo_id, banner_id, preparation_days, origin_warehouse,
                 carriers, closed, closed_from, closed_to, reopen_message, social, created_at, updated_at)
                VALUES (%d, %s, %s, %s, %d, %d, %d, %s, %s, %d, ' . $from . ', ' . $to . ', %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                 city = VALUES(city), intro = VALUES(intro), logo_id = VALUES(logo_id),
                 banner_id = VALUES(banner_id), preparation_days = VALUES(preparation_days),
                 origin_warehouse = VALUES(origin_warehouse), carriers = VALUES(carriers),
                 closed = VALUES(closed), closed_from = VALUES(closed_from), closed_to = VALUES(closed_to),
                 reopen_message = VALUES(reopen_message), social = VALUES(social), updated_at = VALUES(updated_at)';
        $params = [
            $vendorUserId,
            $settings->storeName,
            $settings->city,
            $settings->intro,
            $settings->logoId,
            $settings->bannerId,
            $settings->preparationDays,
            $settings->originWarehouse,
            json_encode(array_values($settings->carriers), JSON_UNESCAPED_UNICODE),
            $settings->closed ? 1 : 0,
        ];
        if ($settings->closedFrom !== null) {
            $params[] = $settings->closedFrom;
        }
        if ($settings->closedTo !== null) {
            $params[] = $settings->closedTo;
        }
        array_push($params, $settings->reopenMessage, json_encode($settings->social, JSON_UNESCAPED_UNICODE), $now, $now);

        $saved = $this->db->execute($sql, $params) !== null;
        if ($saved) {
            self::announce($vendorUserId);
        }
        return $saved;
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
