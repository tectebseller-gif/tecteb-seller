<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Application;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Modules\Marketplace\Domain\Notification;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;

/**
 * In-panel notifications: targeted, read/unread, and nothing that leaves the site.
 *
 * **Nothing is sent anywhere.** Alpha has no sender and no gateway, so the
 * honest shape of «اعلان» is a row a person finds when they open their panel.
 * Calling it a notification and quietly not delivering it would be the same
 * failure as an e-mail that silently goes nowhere; this class never claims a
 * delivery it did not make, and there is deliberately no `send()`.
 *
 * **A notice is addressed, never broadcast.** A vendor's staff each get their
 * own row, because read/unread is a fact about a person and a shared row would
 * mark a message read for five people when one of them opened it.
 *
 * **The same thing does not notify twice.** The unique index on
 * (user, event, subject) is what enforces it, not a lookup-then-insert that
 * two requests can both pass. A repeat is a no-op, not an error: a retried
 * webhook or a double-clicked button is a normal thing to survive.
 *
 * **Reading is scoped to the reader.** `inbox()` takes the id of whoever is
 * asking and can only ever return rows addressed to them — there is no path
 * that reads somebody else's inbox, not even for the manager.
 */
final class Notify
{
    /** Events, as keys. The Persian sentences live in Presentation. */
    public const TICKET_REPLIED = 'notice.ticket_replied';
    public const TICKET_OPENED = 'notice.ticket_opened';
    public const PRODUCT_APPROVED = 'notice.product_approved';
    public const PRODUCT_REJECTED = 'notice.product_rejected';
    public const VENDOR_APPROVED = 'notice.vendor_approved';
    public const VENDOR_SUSPENDED = 'notice.vendor_suspended';
    public const RETURN_OPENED = 'notice.return_opened';
    public const RETURN_DECIDED = 'notice.return_decided';
    public const WITHDRAWAL_DECIDED = 'notice.withdrawal_decided';
    public const STOREFRONT_STOPPED = 'notice.storefront_stopped';
    public const RATING_RECEIVED = 'notice.rating_received';
    public const RATING_MODERATED = 'notice.rating_moderated';

    public function __construct(
        private readonly NotificationRepositoryInterface $repository,
        private readonly StaffAccess $access,
        private readonly ClockInterface $clock
    ) {
    }

    /**
     * Tells one person one thing, once.
     *
     * @param array<string,scalar> $context
     * @return bool false when this person has already been told, which is a
     *         truth a retry needs and not an error
     */
    public function toUser(
        int $userId,
        string $event,
        string $subjectType,
        string $subjectId,
        array $context = [],
        int $vendorUserId = 0
    ): bool {
        if ($userId <= 0 || $event === '') {
            return false;
        }
        return $this->repository->addNotification(new Notification(
            0,
            $userId,
            $vendorUserId,
            $event,
            $subjectType,
            $subjectId,
            $context,
            null,
            $this->clock->now()->format('Y-m-d H:i:s')
        ));
    }

    /**
     * Tells a shop — which means every person who works in it, separately.
     *
     * The owner and their staff each get a row, because «خوانده شد» is a fact
     * about a person. `StaffAccess` is the only place that knows who those
     * people are, and asking it here is what keeps a suspended staff member
     * from being notified: a suspension removes them from the store's roll.
     *
     * @param array<string,scalar> $context
     * @return int how many people were told
     */
    public function toStore(
        int $vendorUserId,
        string $event,
        string $subjectType,
        string $subjectId,
        array $context = []
    ): int {
        $told = 0;
        foreach ($this->access->activeMembersOf($vendorUserId) as $userId) {
            if ($this->toUser($userId, $event, $subjectType, $subjectId, $context, $vendorUserId)) {
                $told++;
            }
        }
        return $told;
    }

    /**
     * Tells every manager.
     *
     * @param array<string,scalar> $context
     * @return int how many people were told
     */
    public function toManagers(
        array $managerIds,
        string $event,
        string $subjectType,
        string $subjectId,
        array $context = []
    ): int {
        $told = 0;
        foreach ($managerIds as $userId) {
            if ($this->toUser((int) $userId, $event, $subjectType, $subjectId, $context)) {
                $told++;
            }
        }
        return $told;
    }

    /**
     * This person's own notices, newest first.
     *
     * @return list<Notification>
     */
    public function inbox(int $userId, bool $unreadOnly = false, int $limit = 50): array
    {
        return $userId > 0 ? $this->repository->notificationsFor($userId, $unreadOnly, $limit) : [];
    }

    public function unreadCount(int $userId): int
    {
        return $userId > 0 ? $this->repository->unreadNotificationCount($userId) : 0;
    }

    /**
     * Marks one notice read — and only if it belongs to the reader.
     *
     * The ownership check is in the WHERE clause rather than in a read-then-
     * write, so there is no window in which somebody else's id can be marked
     * by guessing a number.
     */
    public function markRead(int $userId, int $notificationId): bool
    {
        return $userId > 0 && $notificationId > 0
            && $this->repository->markNotificationRead($userId, $notificationId, $this->clock->now()->format('Y-m-d H:i:s'));
    }

    public function markAllRead(int $userId): int
    {
        return $userId > 0
            ? $this->repository->markAllNotificationsRead($userId, $this->clock->now()->format('Y-m-d H:i:s'))
            : 0;
    }
}
