<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Finance\Application\CommissionRuleRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionRate;
use Tecteb\Marketplace\Modules\Finance\Domain\RateScope;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables as T;

/** Commission rules, where NULL means "not set" and 0 means "no commission". */
final class DbCommissionRuleRepository implements CommissionRuleRepositoryInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock
    ) {
    }

    public function rate(RateScope $scope, string $reference): CommissionRate
    {
        $row = $this->db->getRow(
            'SELECT rate_bp FROM `' . $this->table() . '` WHERE scope = %s AND reference = %s',
            [$scope->value, $reference]
        );
        // A missing row and a row holding NULL mean the same thing, and it is
        // not zero: both are «هنوز تعیین نشده».
        return CommissionRate::fromStored($row['rate_bp'] ?? null);
    }

    public function setRate(RateScope $scope, string $reference, CommissionRate $rate): bool
    {
        $now = $this->now();
        if (!$rate->isSet()) {
            return $this->clearRate($scope, $reference);
        }
        return $this->db->execute(
            'INSERT INTO `' . $this->table() . '` (scope, reference, rate_bp, created_at, updated_at)
             VALUES (%s, %s, %d, %s, %s)
             ON DUPLICATE KEY UPDATE rate_bp = VALUES(rate_bp), updated_at = VALUES(updated_at)',
            [$scope->value, $reference, (int) $rate->basisPoints, $now, $now]
        ) !== null;
    }

    public function clearRate(RateScope $scope, string $reference): bool
    {
        return $this->db->execute(
            'DELETE FROM `' . $this->table() . '` WHERE scope = %s AND reference = %s',
            [$scope->value, $reference]
        ) !== null;
    }

    public function all(): array
    {
        $rows = $this->db->getResults('SELECT * FROM `' . $this->table() . '` ORDER BY scope, reference');
        $out = [];
        foreach ($rows as $row) {
            $scope = RateScope::tryFrom((string) $row['scope']);
            if ($scope === null) {
                continue;
            }
            $out[] = [
                'scope' => $scope,
                'reference' => (string) $row['reference'],
                'rate' => CommissionRate::fromStored($row['rate_bp'] ?? null),
                'updated_at' => (string) $row['updated_at'],
            ];
        }
        return $out;
    }

    private function table(): string
    {
        return T::table($this->db, T::RULES);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
