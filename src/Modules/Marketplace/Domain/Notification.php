<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Domain;

/**
 * One thing a panel has to tell one person.
 *
 * **It carries a key, never a sentence.** `event` is looked up by the screen
 * that shows it, the same way the action queue works, so the Application layer
 * never calls `__()` and the same row reads correctly on a dashboard, in a
 * list and in a future digest. `context` is the numbers that sentence needs.
 *
 * **Unread is `readAt === null`, and that is the whole state.** No counter to
 * drift, no second table, and «همه را خوانده‌شده کن» is one UPDATE.
 *
 * **Nothing here is a message to the outside world.** Alpha has no sender and
 * no gateway (`docs/decision-log.md` F-02), so a notification is something a
 * person finds when they open their panel — never something this plugin pushes
 * at them. The day a real, documented adapter exists, this row is what it
 * would read; until then, claiming an e-mail went out would be a lie.
 */
final class Notification
{
    /** Subject kinds, so a screen can link to the right place. */
    public const SUBJECT_TICKET = 'ticket';
    public const SUBJECT_PRODUCT = 'product';
    public const SUBJECT_ORDER_ITEM = 'order_item';
    public const SUBJECT_RETURN = 'return';
    public const SUBJECT_VENDOR = 'vendor';
    public const SUBJECT_WITHDRAWAL = 'withdrawal';

    /**
     * @param array<string,scalar> $context values the sentence needs, never the sentence
     */
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly int $vendorUserId,
        public readonly string $event,
        public readonly string $subjectType,
        public readonly string $subjectId,
        public readonly array $context = [],
        public readonly ?string $readAt = null,
        public readonly string $createdAt = ''
    ) {
    }

    public function isUnread(): bool
    {
        return $this->readAt === null || $this->readAt === '';
    }
}
