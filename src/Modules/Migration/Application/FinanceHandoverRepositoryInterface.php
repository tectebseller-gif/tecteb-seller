<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

/**
 * Who is responsible for each shop's imported balance — one row per shop.
 *
 * ### Why not the option it used to live in
 *
 * Every decision used to sit in one serialised array under a single
 * `wp_options` key. Recording one meant reading the whole map, setting one
 * shop's key and writing the whole map back — a read-modify-write over shared
 * state, which is the oldest lost update there is. Two managers deciding two
 * DIFFERENT shops in the same few milliseconds both read the map, and the
 * second write dropped the first manager's decision. No error, no audit gap
 * (the audit row was written before the loss), nothing on screen: just a shop
 * that was decided and then quietly was not.
 *
 * One row per shop does not narrow that race, it removes it. Two decisions
 * about two shops are two primary keys and cannot touch each other, whatever
 * the timing.
 */
interface FinanceHandoverRepositoryInterface
{
    /**
     * One shop's recorded decision, or the «nobody has decided» default.
     *
     * @return array{decision:string, closing_at_decision:string, decided_at:string, decided_by:int, note:string, pending_requests_untouched:int, figures_token:string, records_version:int}
     */
    public function decisionFor(int $vendorUserId): array;

    /**
     * Every recorded decision, keyed by vendor id.
     *
     * @return array<int, array<string,mixed>>
     */
    public function allDecisions(): array;

    /**
     * Write one shop's decision, replacing whatever that shop had.
     *
     * Only this shop's row is touched, so a concurrent decision about another
     * shop is not something this call can lose.
     *
     * @param array<string,mixed> $record
     */
    public function record(int $vendorUserId, array $record): bool;
}
