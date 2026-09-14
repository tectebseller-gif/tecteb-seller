<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Application;

use Tecteb\Marketplace\Modules\Finance\Domain\CommissionRate;
use Tecteb\Marketplace\Modules\Finance\Domain\RateScope;

interface CommissionRuleRepositoryInterface
{
    /** Unset when no rule exists for that scope and reference. */
    public function rate(RateScope $scope, string $reference): CommissionRate;

    public function setRate(RateScope $scope, string $reference, CommissionRate $rate): bool;

    /** Removing a rule restores inheritance; it does not mean zero. */
    public function clearRate(RateScope $scope, string $reference): bool;

    /** @return list<array{scope:RateScope, reference:string, rate:CommissionRate, updated_at:string}> */
    public function all(): array;
}
