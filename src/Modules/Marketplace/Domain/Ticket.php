<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Domain;

/** A vendor↔manager thread, and whether it is still taking replies. */
final class Ticket
{
    public const OPEN = 'open';
    public const ANSWERED = 'answered';
    public const CLOSED = 'closed';

    public const ROLE_VENDOR = 'vendor';
    public const ROLE_MANAGER = 'manager';

    public function __construct(
        public readonly int $id,
        public readonly int $vendorUserId,
        public readonly string $subject,
        public readonly string $status = self::OPEN,
        public readonly string $orderRef = '',
        public readonly bool $locked = false,
        public readonly int $openedBy = 0,
        public readonly ?string $lastReplyAt = null,
        public readonly string $lastReplyRole = '',
        public readonly string $createdAt = ''
    ) {
    }

    /**
     * A locked or closed thread takes no new messages — from either side.
     *
     * UX §10.1 gives the manager the power to lock a thread; a lock that only
     * stopped the vendor would be a manager talking to somebody who cannot
     * answer, which is worse than a closed thread.
     */
    public function acceptsReplies(): bool
    {
        return !$this->locked && $this->status !== self::CLOSED;
    }

    /** Waiting on the marketplace, which is what a manager's queue sorts by. */
    public function awaitsManager(): bool
    {
        return $this->status !== self::CLOSED && $this->lastReplyRole !== self::ROLE_MANAGER;
    }
}
