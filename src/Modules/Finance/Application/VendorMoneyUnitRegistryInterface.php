<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Application;

use Tecteb\Marketplace\Modules\Finance\Domain\MoneyUnitClaim;

/**
 * The one place that answers «which money are this vendor's books kept in».
 *
 * It exists because `alpha.39` asked the LEDGER, and the ledger cannot answer
 * safely: it holds many rows per vendor by design, so there is no index for a
 * second concurrent writer to collide with, and `getResults()` returns an empty
 * array both for «no rows» and for «the read failed». Two first captures at the
 * same moment both saw «empty» and both wrote, in different units.
 *
 * Nothing here is a business decision. The unit is RECORDED by the first
 * accrual and read thereafter; no screen edits it and no setting overrides it.
 */
interface VendorMoneyUnitRegistryInterface
{
    /**
     * Fixes this vendor's unit if it is not fixed yet, and says whether the
     * offered unit is the one their books hold.
     *
     * Safe to call concurrently: the write collides on a primary key and the
     * answer is read back from the STORED row either way.
     */
    public function claim(int $vendorUserId, string $currency, int $exponent): MoneyUnitClaim;

    /**
     * The unit this vendor's money is recorded in, or null when there is none
     * to state — no sales yet, books that hold more than one unit, or a read
     * that failed. A caller that needs to tell those apart asks `isMixed()`
     * and `claim()`.
     *
     * @return array{currency:string, exponent:int}|null
     */
    public function unitOf(int $vendorUserId): ?array;

    /**
     * Whether this vendor's ledger already holds more than one unit — or null
     * when the question could not be asked. Three answers, because `false`
     * and «I could not look» are not the same and the whole point of this
     * interface is that they stopped being confused.
     */
    public function isMixed(int $vendorUserId): ?bool;
}
