<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Marketplace\Application\NotificationRepositoryInterface;
use Tecteb\Marketplace\Modules\Marketplace\Domain\Notification;
use Tecteb\Marketplace\Modules\Marketplace\Domain\TicketAttachment;
use Tecteb\Marketplace\Modules\Marketplace\Infrastructure\Migrations\M0011AttachmentsAndNotices as T;

/**
 * Notices and ticket attachments, on the plugin's own schema.
 *
 * Two shapes are worth naming, and both put a rule in the SQL rather than in a
 * caller:
 *
 *  - **`INSERT IGNORE` for a notice**, so «the same thing does not notify
 *    twice» is the unique index's job. A check-then-insert is how two requests
 *    both pass the check.
 *  - **`WHERE user_id = %d` on every read and on the mark-as-read**, so an
 *    inbox cannot be somebody else's even if a screen forgets to ask. There is
 *    no query here that can return a row addressed to another person.
 *
 * And one absence: no `deleteAttachment`. A file is hidden with a reason and an
 * actor, never removed, exactly like the message it hangs from (UX §10.1).
 */
final class DbNotificationRepository implements NotificationRepositoryInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock
    ) {
    }

    // --- notifications ------------------------------------------------------

    public function addNotification(Notification $notification): bool
    {
        $written = $this->db->execute(
            'INSERT IGNORE INTO `' . $this->t(T::NOTIFICATIONS) . '`
             (user_id, vendor_user_id, event, subject_type, subject_id, context, read_at, created_at)
             VALUES (%d, %d, %s, %s, %s, %s, NULL, %s)',
            [
                $notification->userId,
                $notification->vendorUserId,
                $notification->event,
                $notification->subjectType,
                $notification->subjectId,
                $this->encode($notification->context),
                $notification->createdAt !== '' ? $notification->createdAt : $this->now(),
            ]
        );
        return $written !== null && $written > 0;
    }

    public function notificationsFor(int $userId, bool $unreadOnly = false, int $limit = 50): array
    {
        $sql = 'SELECT * FROM `' . $this->t(T::NOTIFICATIONS) . '` WHERE user_id = %d';
        if ($unreadOnly) {
            $sql .= ' AND read_at IS NULL';
        }
        $sql .= ' ORDER BY id DESC LIMIT %d';
        return array_map(
            [$this, 'hydrateNotification'],
            $this->db->getResults($sql, [$userId, max(1, min(500, $limit))])
        );
    }

    public function unreadNotificationCount(int $userId): int
    {
        return (int) $this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->t(T::NOTIFICATIONS) . '`
             WHERE user_id = %d AND read_at IS NULL',
            [$userId]
        );
    }

    public function markNotificationRead(int $userId, int $notificationId, string $at): bool
    {
        // The owner check is IN the WHERE clause. A read-then-write would have
        // a window in which a guessed id belongs to somebody else.
        $written = $this->db->execute(
            'UPDATE `' . $this->t(T::NOTIFICATIONS) . '`
             SET read_at = %s WHERE id = %d AND user_id = %d AND read_at IS NULL',
            [$at, $notificationId, $userId]
        );
        return $written !== null && $written > 0;
    }

    public function markAllNotificationsRead(int $userId, string $at): int
    {
        $written = $this->db->execute(
            'UPDATE `' . $this->t(T::NOTIFICATIONS) . '`
             SET read_at = %s WHERE user_id = %d AND read_at IS NULL',
            [$at, $userId]
        );
        return $written === null ? 0 : $written;
    }

    // --- ticket attachments -------------------------------------------------

    public function addTicketAttachment(TicketAttachment $attachment): int
    {
        $written = $this->db->execute(
            'INSERT INTO `' . $this->t(T::TICKET_ATTACHMENTS) . '`
             (ticket_id, message_id, uploaded_by, original_name, stored_path, mime, size_bytes,
              hidden, hidden_reason, hidden_by, created_at)
             VALUES (%d, %d, %d, %s, %s, %s, %d, 0, %s, NULL, %s)',
            [
                $attachment->ticketId,
                $attachment->messageId,
                $attachment->uploadedBy,
                $attachment->originalName,
                $attachment->storedPath,
                $attachment->mime,
                $attachment->sizeBytes,
                '',
                $attachment->createdAt !== '' ? $attachment->createdAt : $this->now(),
            ]
        );
        return $written === null ? 0 : (int) $this->db->getVar('SELECT LAST_INSERT_ID()');
    }

    public function attachmentsForTicket(int $ticketId): array
    {
        return array_map(
            [$this, 'hydrateAttachment'],
            $this->db->getResults(
                'SELECT * FROM `' . $this->t(T::TICKET_ATTACHMENTS) . '`
                 WHERE ticket_id = %d ORDER BY id ASC',
                [$ticketId]
            )
        );
    }

    public function findAttachment(int $attachmentId): ?TicketAttachment
    {
        $row = $this->db->getRow(
            'SELECT * FROM `' . $this->t(T::TICKET_ATTACHMENTS) . '` WHERE id = %d',
            [$attachmentId]
        );
        return $row === null ? null : $this->hydrateAttachment($row);
    }

    public function hideAttachment(int $attachmentId, int $actorId, string $reason): bool
    {
        $written = $this->db->execute(
            'UPDATE `' . $this->t(T::TICKET_ATTACHMENTS) . '`
             SET hidden = 1, hidden_reason = %s, hidden_by = %d
             WHERE id = %d AND hidden = 0',
            [$reason, $actorId, $attachmentId]
        );
        return $written !== null && $written > 0;
    }

    // --- hydration ----------------------------------------------------------

    /** @param array<string,mixed> $row */
    private function hydrateNotification(array $row): Notification
    {
        return new Notification(
            (int) $row['id'],
            (int) $row['user_id'],
            (int) $row['vendor_user_id'],
            (string) $row['event'],
            (string) $row['subject_type'],
            (string) $row['subject_id'],
            $this->decode((string) ($row['context'] ?? '')),
            $row['read_at'] !== null ? (string) $row['read_at'] : null,
            (string) $row['created_at']
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateAttachment(array $row): TicketAttachment
    {
        return new TicketAttachment(
            (int) $row['id'],
            (int) $row['ticket_id'],
            (int) $row['message_id'],
            (int) $row['uploaded_by'],
            (string) $row['original_name'],
            (string) $row['stored_path'],
            (string) $row['mime'],
            (int) $row['size_bytes'],
            (int) $row['hidden'] === 1,
            (string) $row['hidden_reason'],
            $row['hidden_by'] !== null ? (int) $row['hidden_by'] : null,
            (string) $row['created_at']
        );
    }

    /** @param array<string,scalar> $context */
    private function encode(array $context): string
    {
        return $context === [] ? '' : (string) json_encode($context, JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string,scalar> */
    private function decode(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function t(string $suffix): string
    {
        return T::table($this->db, $suffix);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
