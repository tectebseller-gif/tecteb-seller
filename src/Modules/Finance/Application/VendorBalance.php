<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Application;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Core\Config\SettingsService;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;

/**
 * What a vendor has earned, and how much of it they may ask for today.
 *
 * Three numbers, and the difference between them is the whole design:
 *
 *   earned      every recorded share, whatever state it is in
 *   pending     earned but not yet eligible — either nobody has called the
 *               sale complete, or the approved waiting period has not passed
 *   eligible    complete, waited out, and not already locked by a request
 *
 * `pending` is not a rounding of `eligible`. A vendor looking at their
 * dashboard has to be able to tell "you have not earned this yet" from "you
 * have earned it and cannot have it yet", and a single balance cannot say
 * both. The reason a line is pending is returned with it.
 *
 * The waiting period is the approved four days (Master §8.3, A.2, F-03), read
 * from settings — not invented here. The moment it counts FROM is what DEC-02
 * has not defined, so it is a date a manager recorded on the line (ORDER-01),
 * never one this class inferred from a payment or a shipment.
 *
 * A share that was never recorded (an unresolvable rate, FIN-02) counts as
 * nothing at all here — not as zero. It is reported separately as
 * `unrecorded`, because a vendor whose sale produced no share is owed an
 * answer, not a silent omission.
 */
final class VendorBalance
{
    public function __construct(
        private readonly OrderItemRepositoryInterface $orderItems,
        private readonly SettingsService $settings,
        private readonly ClockInterface $clock
    ) {
    }

    /**
     * @return array{
     *   earned:int, pending:int, eligible:int, reserved:int, paid:int,
     *   unrecorded:int, eligible_line_ids:list<int>, delay_days:int,
     *   awaiting_completion:int, awaiting_delay:int
     * }
     */
    public function of(int $vendorUserId): array
    {
        $delayDays = $this->settings->load()->settlementDelayDays;
        $cutoff = $this->clock->now()->modify('-' . $delayDays . ' days');

        $earned = 0;
        $pending = 0;
        $eligible = 0;
        $reserved = 0;
        $paid = 0;
        $unrecorded = 0;
        $awaitingCompletion = 0;
        $awaitingDelay = 0;
        $eligibleIds = [];

        foreach ($this->orderItems->settlementView($vendorUserId) as $line) {
            $share = $line['vendor_share_minor'];
            if ($share === null) {
                $unrecorded++;
                continue;       // unset is not zero (FIN-02)
            }
            $earned += $share;
            if ($line['paid']) {
                $paid += $share;
                continue;
            }
            if ($line['withdrawal_id'] !== null) {
                $reserved += $share;
                continue;
            }
            $completedAt = $line['settlement_completed_at'];
            if ($completedAt === null) {
                $pending += $share;
                $awaitingCompletion++;
                continue;
            }
            if ($completedAt > $cutoff->format('Y-m-d H:i:s')) {
                $pending += $share;
                $awaitingDelay++;
                continue;
            }
            $eligible += $share;
            $eligibleIds[] = $line['id'];
        }

        return [
            'earned' => $earned,
            'pending' => $pending,
            'eligible' => $eligible,
            'reserved' => $reserved,
            'paid' => $paid,
            'unrecorded' => $unrecorded,
            'eligible_line_ids' => $eligibleIds,
            'delay_days' => $delayDays,
            'awaiting_completion' => $awaitingCompletion,
            'awaiting_delay' => $awaitingDelay,
        ];
    }
}
