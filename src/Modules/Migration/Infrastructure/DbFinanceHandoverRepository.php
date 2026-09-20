<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Core\Migration\Migrations\M0018HandoverRowsAndRecordVersion as H;
use Tecteb\Marketplace\Modules\Migration\Application\FinanceHandoverRepositoryInterface;

/**
 * The hand-over decisions, one row per shop.
 *
 * `closing_at_decision` is a `DECIMAL(19,4)` column and comes back out through
 * `CAST(... AS CHAR)`, for the reason every other amount in this module is a
 * string: the figure is somebody's money as another system recorded it, and
 * letting PHP see it as a float is the first rounding nobody asked for.
 */
final class DbFinanceHandoverRepository implements FinanceHandoverRepositoryInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock
    ) {
    }

    public function decisionFor(int $vendorUserId): array
    {
        $row = $this->db->getRow(
            'SELECT ' . self::COLUMNS . ' FROM `' . $this->t() . '` WHERE vendor_user_id = %d',
            [$vendorUserId]
        );
        return $row === null ? self::undecided() : self::hydrate($row);
    }

    public function allDecisions(): array
    {
        $rows = $this->db->getResults(
            'SELECT ' . self::COLUMNS . ' FROM `' . $this->t() . '` ORDER BY vendor_user_id ASC',
            []
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['vendor_user_id']] = self::hydrate($row);
        }
        return $out;
    }

    public function record(int $vendorUserId, array $record): bool
    {
        // One statement, one row. `ON DUPLICATE KEY UPDATE` rather than
        // delete-then-insert so that a shop being re-decided never passes
        // through a moment of having no decision at all.
        return $this->db->execute(
            'INSERT INTO `' . $this->t() . '`
             (vendor_user_id, decision, closing_at_decision, decided_at, decided_by,
              note, pending_requests_untouched, figures_token, records_version, updated_at)
             VALUES (%d, %s, %s, %s, %d, %s, %d, %s, %d, %s)
             ON DUPLICATE KEY UPDATE
                decision = VALUES(decision),
                closing_at_decision = VALUES(closing_at_decision),
                decided_at = VALUES(decided_at),
                decided_by = VALUES(decided_by),
                note = VALUES(note),
                pending_requests_untouched = VALUES(pending_requests_untouched),
                figures_token = VALUES(figures_token),
                records_version = VALUES(records_version),
                updated_at = VALUES(updated_at)',
            [
                $vendorUserId,
                mb_substr((string) ($record['decision'] ?? ''), 0, 30),
                // is_numeric rather than a cast: '' would silently become 0
                // and invent a frozen figure for a decision that froze none.
                is_numeric((string) ($record['closing_at_decision'] ?? ''))
                    ? (string) $record['closing_at_decision']
                    : '0',
                $this->dateOrNull((string) ($record['decided_at'] ?? '')),
                (int) ($record['decided_by'] ?? 0),
                (string) ($record['note'] ?? ''),
                (int) ($record['pending_requests_untouched'] ?? 0),
                mb_substr((string) ($record['figures_token'] ?? ''), 0, 64),
                (int) ($record['records_version'] ?? 0),
                $this->now(),
            ]
        ) !== null;
    }

    private const COLUMNS = 'vendor_user_id, decision,
            CAST(closing_at_decision AS CHAR) AS closing_at_decision,
            decided_at, decided_by, note, pending_requests_untouched,
            figures_token, records_version';

    /** @return array<string,mixed> */
    public static function undecided(): array
    {
        return [
            'decision' => 'open',
            'closing_at_decision' => '',
            'decided_at' => '',
            'decided_by' => 0,
            'note' => '',
            'pending_requests_untouched' => 0,
            'figures_token' => '',
            'records_version' => 0,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate(array $row): array
    {
        return [
            'decision' => (string) $row['decision'],
            'closing_at_decision' => (string) $row['closing_at_decision'],
            'decided_at' => (string) ($row['decided_at'] ?? ''),
            'decided_by' => (int) $row['decided_by'],
            'note' => (string) ($row['note'] ?? ''),
            'pending_requests_untouched' => (int) $row['pending_requests_untouched'],
            'figures_token' => (string) $row['figures_token'],
            'records_version' => (int) $row['records_version'],
        ];
    }

    private function dateOrNull(string $value): ?string
    {
        return $value === '' || str_starts_with($value, '0000-') ? null : $value;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    private function t(): string
    {
        return H::table($this->db, H::HANDOVER);
    }
}
