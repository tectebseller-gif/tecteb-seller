<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Application;

use Tecteb\Marketplace\Contracts\AuditRepositoryInterface;
use Tecteb\Marketplace\Contracts\ClockInterface;

/**
 * What a shop's staff have actually been doing — per person, over a window.
 *
 * ### Why this is not «last seen»
 *
 * The staff list has carried a `last_seen_at` stamp since `alpha.5`, and
 * until now that was the whole of the answer to Master §۵. A timestamp says
 * somebody opened a page. It cannot tell an owner whether the person they
 * gave order rights to has touched an order, whether the shipping clerk has
 * shipped anything this month, or which member made the change that is now
 * being asked about. «Still logged in» and «doing the job» are different
 * questions and only one of them was being answered.
 *
 * The audit log already holds the answer — every write in this plugin goes
 * through it — so this report READS, and stores nothing of its own. No new
 * table, no counter kept in parallel with the log it would be derived from:
 * a second copy of a number is a second number to be wrong.
 *
 * ### Counted in SQL, not by fetching
 *
 * The first version asked `search()` for 2000 rows and tallied them in PHP.
 * `search()` caps its page at **200**, silently, so a shop whose staff did
 * more than that was counted at 200 — and the «these numbers are a floor»
 * warning, written as `count($rows) >= 2000`, could never fire. The report
 * understated the work AND claimed to be complete, which is worse than
 * either on its own.
 *
 * `countByActor()` and `latestByActor()` are `GROUP BY` queries with no page
 * to be capped to, so there is no cap to disagree with and nothing to warn
 * about. Two queries, whatever the volume.
 *
 * ### What it is not allowed to be
 *
 * It is scoped to one shop's own staff and nothing else. The audit log is
 * site-wide, so an unscoped read here would hand a vendor the manager's
 * activity and every other shop's. The actor list is built from
 * `StaffRepositoryInterface::forVendor()` and the query is restricted to
 * exactly those ids — a shop with no staff gets an empty report rather than
 * an unfiltered one, which is the failure mode that matters.
 *
 * Reading it is gated the same way managing staff is (`canManageStore`),
 * because it answers a question about the people in the shop and that is the
 * owner's business, not a colleague's.
 */
final class StaffActivityReport
{
    /** Windows the owner may choose between, in days. */
    public const WINDOWS = [7, 30, 90];

    public const DEFAULT_WINDOW = 30;


    public function __construct(
        private readonly StaffRepositoryInterface $staff,
        private readonly AuditRepositoryInterface $audit,
        private readonly StaffAccess $access,
        private readonly ClockInterface $clock
    ) {
    }

    /**
     * @return array{
     *     allowed:bool,
     *     reason:string,
     *     window_days:int,
     *     from:string,
     *     rows:list<array{staff_id:int, staff_user_id:int, display_name:string, role:string, status:string, last_seen_at:?string, actions:int, last_event:string, last_at:string}>
     * }
     */
    public function forVendor(int $viewerUserId, int $vendorUserId, int $windowDays = self::DEFAULT_WINDOW): array
    {
        $windowDays = in_array($windowDays, self::WINDOWS, true) ? $windowDays : self::DEFAULT_WINDOW;
        $from = $this->clock->now()->modify('-' . $windowDays . ' days')->format('Y-m-d');
        $empty = [
            'allowed' => false,
            'reason' => 'forbidden',
            'window_days' => $windowDays,
            'from' => $from,
            'rows' => [],
        ];

        // The same gate as the staff page itself. Asked first, so nothing
        // below can read a row on behalf of somebody who may not see it.
        if (!$this->access->canManageStore($viewerUserId, $vendorUserId)) {
            return $empty;
        }

        $members = $this->staff->forVendor($vendorUserId);
        if ($members === []) {
            return ['allowed' => true, 'reason' => 'no_staff'] + array_slice($empty, 2, null, true);
        }

        $byUser = [];
        foreach ($members as $member) {
            if ($member->staffUserId > 0) {
                $byUser[$member->staffUserId] = $member;
            }
        }
        if ($byUser === []) {
            // Invited-but-never-activated rows have no WordPress user yet, so
            // there is nothing they could have done. Saying «no activity» is
            // the honest answer; querying with an empty actor list would read
            // the whole site's log instead.
            return ['allowed' => true, 'reason' => 'no_active_accounts'] + array_slice($empty, 2, null, true);
        }

        $scope = ['actors' => array_keys($byUser), 'from' => $from];
        $counts = $this->audit->countByActor($scope);
        $latest = $this->audit->latestByActor($scope);

        $rows = [];
        foreach ($members as $member) {
            // Belt and braces over the SQL filter: only this shop's people
            // are ever listed, whatever the query returned.
            $actor = $member->staffUserId;
            $last = $latest[$actor] ?? null;
            $rows[] = [
                'staff_id' => $member->id,
                'staff_user_id' => $actor,
                'display_name' => $member->displayName,
                'role' => $member->preset->value,
                'status' => $member->status->value,
                'last_seen_at' => $member->lastSeenAt,
                'actions' => (int) ($counts[$actor] ?? 0),
                'last_event' => (string) ($last['event_type'] ?? ''),
                'last_at' => (string) ($last['created_at'] ?? ''),
            ];
        }

        // Busiest first: the question an owner opens this with is «who is
        // doing the work», and a list sorted by id answers a different one.
        usort($rows, static fn (array $a, array $b): int => $b['actions'] <=> $a['actions']);

        return [
            'allowed' => true,
            'reason' => 'ok',
            'window_days' => $windowDays,
            'from' => $from,
            'rows' => $rows,
        ];
    }
}
