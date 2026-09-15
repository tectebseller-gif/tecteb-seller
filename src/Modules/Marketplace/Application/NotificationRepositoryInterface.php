<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Application;

use Tecteb\Marketplace\Modules\Marketplace\Domain\Notification;
use Tecteb\Marketplace\Modules\Marketplace\Domain\TicketAttachment;

/**
 * Where in-panel notices and ticket attachments live.
 *
 * Kept apart from `EngagementRepositoryInterface` rather than bolted onto it,
 * because these two are the only things in this module a person can be told
 * about or hand a file to, and both have an ownership rule that belongs in the
 * SQL rather than in a caller's `if`.
 *
 * Every method that returns somebody's rows takes that somebody's id. There is
 * no «all notifications» read, not even for a manager: an inbox is a person's,
 * and a method that could return another person's would eventually be called
 * by a screen that forgot to check.
 */
interface NotificationRepositoryInterface
{
    // --- notifications ---

    /**
     * Adds a notice, unless this person has already had this exact one.
     *
     * The "unless" is the unique index doing the work, not a lookup first:
     * two requests that both check and then both insert are the ordinary way
     * a duplicate gets in.
     *
     * @return bool false when it was already there — a truth, not an error
     */
    public function addNotification(Notification $notification): bool;

    /** @return list<Notification> newest first, this user's only */
    public function notificationsFor(int $userId, bool $unreadOnly = false, int $limit = 50): array;

    public function unreadNotificationCount(int $userId): int;

    /** Marks one, and only if it belongs to this user. */
    public function markNotificationRead(int $userId, int $notificationId, string $at): bool;

    /** @return int how many were still unread */
    public function markAllNotificationsRead(int $userId, string $at): int;

    // --- ticket attachments ---

    public function addTicketAttachment(TicketAttachment $attachment): int;

    /** @return list<TicketAttachment> */
    public function attachmentsForTicket(int $ticketId): array;

    public function findAttachment(int $attachmentId): ?TicketAttachment;

    /** Hides a file without deleting it, exactly as a message is hidden. */
    public function hideAttachment(int $attachmentId, int $actorId, string $reason): bool;
}
