<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Core\Migration\Migrations\M0015DokanOrderHistory as T;
use Tecteb\Marketplace\Modules\Migration\Application\OrderHistoryRepositoryInterface;

/**
 * Dokan's past orders, on the plugin's own schema, unchanged.
 *
 * `INSERT IGNORE` on `(wc_order_id, vendor_user_id)`, for the reason the whole
 * codebase keeps rediscovering: a resumed job re-runs its last page, and
 * «have we imported this order already?» asked in PHP is a question two
 * batches can both answer no. The index answers it once.
 *
 * Every figure is bound straight from what the reader found. Nothing here
 * multiplies, divides or applies a rate — the sums in `summaryForVendor()` add
 * Dokan's own numbers together and nothing else.
 */
final class DbOrderHistoryRepository implements OrderHistoryRepositoryInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock
    ) {
    }

    public function record(string $runId, array $order): string
    {
        $written = $this->db->execute(
            'INSERT IGNORE INTO `' . $this->t() . '`
             (run_id, wc_order_id, vendor_user_id, status, total_minor, net_minor,
              commission_minor, refunded, source, imported_at)
             VALUES (%s, %d, %d, %s, %d, %d, %d, %d, %s, %s)',
            [
                mb_substr($runId, 0, 64),
                (int) $order['wc_order_id'],
                (int) $order['vendor_user_id'],
                mb_substr((string) $order['status'], 0, 32),
                (int) $order['total_minor'],
                (int) $order['net_minor'],
                (int) $order['commission_minor'],
                ($order['refunded'] ?? false) ? 1 : 0,
                'dokan',
                $this->now(),
            ]
        );
        // Three answers, not two. `execute()` returns null on FAILURE and a
        // row count on success — and `INSERT IGNORE` counts 0 for a duplicate.
        // Collapsing those into one boolean made a broken insert look exactly
        // like a row that was already there, so a resumed job reported
        // «already imported» about orders it had never managed to write.
        return match (true) {
            $written === null => self::FAILED,
            $written > 0 => self::RECORDED,
            default => self::ALREADY,
        };
    }

    public function forVendor(int $vendorUserId, int $limit = 50, int $offset = 0): array
    {
        return $this->db->getResults(
            'SELECT * FROM `' . $this->t() . '`
             WHERE vendor_user_id = %d ORDER BY wc_order_id DESC LIMIT %d OFFSET %d',
            [$vendorUserId, max(1, min(200, $limit)), max(0, $offset)]
        );
    }

    public function countForVendor(int $vendorUserId): int
    {
        return (int) $this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->t() . '` WHERE vendor_user_id = %d',
            [$vendorUserId]
        );
    }

    public function summaryForVendor(int $vendorUserId): array
    {
        $row = $this->db->getRow(
            'SELECT COUNT(*) AS orders, COALESCE(SUM(total_minor), 0) AS total_minor,
                    COALESCE(SUM(net_minor), 0) AS net_minor
             FROM `' . $this->t() . '` WHERE vendor_user_id = %d',
            [$vendorUserId]
        );
        return [
            'orders' => (int) ($row['orders'] ?? 0),
            'total_minor' => (int) ($row['total_minor'] ?? 0),
            'net_minor' => (int) ($row['net_minor'] ?? 0),
        ];
    }

    public function deleteRun(string $runId): int
    {
        if ($runId === '') {
            // Refusing an empty run id is not defensive noise: `run_id` has a
            // default of '', so a `DELETE WHERE run_id = ''` would take every
            // row a pre-run-id build ever wrote.
            return 0;
        }
        $removed = $this->db->execute(
            'DELETE FROM `' . $this->t() . '` WHERE run_id = %s',
            [$runId]
        );
        return $removed ?? 0;
    }

    public function countForRun(string $runId): int
    {
        return (int) $this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->t() . '` WHERE run_id = %s',
            [$runId]
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    private function t(): string
    {
        return T::table($this->db, T::HISTORY);
    }
}
