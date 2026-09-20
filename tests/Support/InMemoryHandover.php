<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Modules\Migration\Application\FinanceHandoverRepositoryInterface;

/**
 * Hand-over decisions in memory, one entry per shop.
 *
 * Keyed by vendor id exactly as the table is, so a unit test that writes two
 * shops' decisions is exercising the same «two keys, no interference» shape
 * production has — rather than the single shared array this replaced, which
 * would have let the fake pass a test the real storage failed.
 */
final class InMemoryHandover implements FinanceHandoverRepositoryInterface
{
    /** @var array<int, array<string,mixed>> */
    public array $rows = [];
    public bool $failWrites = false;

    public function decisionFor(int $vendorUserId): array
    {
        return $this->rows[$vendorUserId] ?? self::undecided();
    }

    public function allDecisions(): array
    {
        return $this->rows;
    }

    public function record(int $vendorUserId, array $record): bool
    {
        if ($this->failWrites) {
            return false;
        }
        $this->rows[$vendorUserId] = $record + self::undecided();
        return true;
    }

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
}
