<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Operations\Application;

use Tecteb\Marketplace\Contracts\OutboxRepositoryInterface;
use Tecteb\Marketplace\Core\Environment\EnvironmentResolver;
use Tecteb\Marketplace\Core\Environment\OutboundPolicy;
use Tecteb\Marketplace\Core\Exceptions\OutboundBlockedException;
use Tecteb\Marketplace\Core\Jobs\Job;
use Tecteb\Marketplace\Core\Jobs\JobBatch;
use Tecteb\Marketplace\Core\Jobs\JobHandlerInterface;

/**
 * The delivery worker — which, in this release, exists to be refused.
 *
 * It is here rather than absent because the refusal has to be a MEASURED fact
 * and not a missing feature. This handler walks the recorded events, asks
 * `OutboundPolicy::assertAllowed()` whether it may send, and marks every row
 * `blocked` with the reason when the answer is no — which it always is: the
 * Alpha's outbound lock is unconditional and cannot be opened by an option, a
 * constant or a filter, and the architecture suite asserts that no branch of it
 * returns true. So «هیچ sender واقعی وجود ندارد» is something a manager can see
 * on a page, row by row, instead of a sentence in a document.
 *
 * `blocked` is a terminal state on purpose. A blocked event is not a failure
 * and not a queue backing up: it is an event correctly recorded, correctly
 * signed, and correctly not sent. Re-attempting it every five minutes for ever
 * would turn a working system into a red light nobody looks at.
 *
 * The day an endpoint exists, the change is here and nowhere else: the rows,
 * their ids, their payloads and their signatures are already exactly what would
 * go on the wire.
 */
final class DeliverEventsJob implements JobHandlerInterface
{
    public const TYPE = 'event_delivery';

    public function __construct(
        private readonly OutboxRepositoryInterface $outbox,
        private readonly EnvironmentResolver $environment
    ) {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function batchSize(): int
    {
        return 50;
    }

    public function step(Job $job): JobBatch
    {
        $after = (int) ($job->cursor['after'] ?? 0);
        $rows = $this->outbox->pending($this->batchSize(), $after);
        if ($rows === []) {
            return JobBatch::finished(['after' => $after]);
        }

        $blocked = 0;
        $last = $after;
        foreach ($rows as $row) {
            $last = (int) $row['id'];
            try {
                OutboundPolicy::assertAllowed($this->environment->resolve(), 'event_webhook');
            } catch (OutboundBlockedException) {
                // The expected path in this release, and not an error. The row
                // keeps its payload and its signature; only its state moves.
                $this->outbox->markDelivery($last, 'blocked', 'outbound_blocked');
                $blocked++;
                continue;
            }
            // Unreachable while the lock stands. Left explicit rather than as a
            // `// TODO`, so the one place a sender would be added is named.
            $this->outbox->markDelivery($last, 'failed', 'no_endpoint');
        }

        return JobBatch::progress(['after' => $last], $blocked, 0, 0, ['blocked:' . $blocked]);
    }
}
