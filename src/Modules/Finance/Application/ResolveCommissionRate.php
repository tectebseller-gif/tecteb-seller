<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Application;

use Tecteb\Marketplace\Modules\Finance\Domain\CommissionRate;
use Tecteb\Marketplace\Modules\Finance\Domain\RateScope;

/**
 * Finds the rate that applies to one item, narrowest scope first.
 *
 * Returns WHERE it found it as well as what it found, because a snapshot that
 * records «۱۰٪» without recording that the ten came from the marketplace
 * default rather than from this product cannot be audited after somebody
 * changes the default.
 *
 * When nothing is set anywhere, the answer is an unset rate — never zero
 * (FIN-02). The caller is then required to stop.
 */
final class ResolveCommissionRate
{
    public function __construct(private readonly CommissionRuleRepositoryInterface $rules)
    {
    }

    /**
     * @param array<string,string> $references scope value => reference id,
     *        e.g. ['product' => '42', 'vendor' => '7', 'category' => 'gloves']
     * @return array{rate:CommissionRate, source:string}
     */
    public function forItem(array $references): array
    {
        foreach (RateScope::precedence() as $scope) {
            $reference = $references[$scope->value] ?? '';
            if ($scope !== RateScope::General && $reference === '') {
                continue;
            }
            $rate = $this->rules->rate($scope, $scope === RateScope::General ? 'general' : $reference);
            if ($rate->isSet()) {
                return ['rate' => $rate, 'source' => $scope->value];
            }
        }
        return ['rate' => CommissionRate::unset(), 'source' => 'none'];
    }
}
