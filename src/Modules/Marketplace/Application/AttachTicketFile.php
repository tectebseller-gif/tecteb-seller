<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Application;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\Files\PrivateFileStorageInterface;
use Tecteb\Marketplace\Contracts\Files\PrivateStorageUnavailable;
use Tecteb\Marketplace\Contracts\Files\UploadedFile;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Marketplace\Domain\TicketAttachment;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;

/**
 * A file on a support ticket — stored where no URL reaches it, readable only
 * by the people in that conversation.
 *
 * **Why an allowlist here rather than the vendor-document rules.** A document
 * type is something the manager configures per requirement; a ticket
 * attachment has no type and no requirement — it is whatever a person needed
 * to show. So the rule is a short fixed list of things that are safe to hand
 * back to a browser later, and everything else is refused with the reason.
 * The list is deliberately narrow: an image, a PDF, or a plain-text log.
 *
 * **The extension is never trusted.** What is checked is the type the server
 * detected from the bytes, because `receipt.pdf.php` is the oldest trick there
 * is and a name is whatever the sender typed.
 *
 * **Storage refusing is a refusal, not a fallback.** If there is no directory
 * outside every web root, the upload fails with a sentence the screen can
 * show. Writing into `uploads/` instead would turn a visible failure into a
 * silent exposure — the same rule ADR-007 set for vendor documents.
 *
 * **Who may read it is who is in the thread.** `mayRead()` is the only door,
 * and it asks two questions: is this the shop the ticket belongs to, or is
 * this a manager. A hidden file answers no to everyone except the manager,
 * because hiding is moderation and moderation has to be reviewable.
 */
final class AttachTicketFile
{
    /** Detected types a ticket may carry. Narrow on purpose. */
    public const ALLOWED_MIME = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
        'text/plain',
    ];

    /** Five megabytes: enough for a photo of a damaged box or an invoice. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** How many files one message may carry. */
    public const MAX_PER_MESSAGE = 3;

    public function __construct(
        private readonly NotificationRepositoryInterface $repository,
        private readonly EngagementRepositoryInterface $tickets,
        private readonly ManageTickets $access,
        private readonly PrivateFileStorageInterface $storage,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock
    ) {
    }

    /**
     * Attaches one file to a message the caller has just written.
     *
     * The message id matters: an attachment belongs to something somebody
     * said, not to a thread in general, so a hidden message hides its files
     * with it.
     */
    public function attach(int $actorId, int $ticketId, int $messageId, UploadedFile $file): OperationResult
    {
        if (!$this->access->mayPostTo($actorId, $ticketId)) {
            return OperationResult::failure('forbidden');
        }
        if ($file->errorCode !== 0 || $file->sizeBytes <= 0) {
            return OperationResult::failure('attachment_no_file');
        }
        if ($file->sizeBytes > self::MAX_BYTES) {
            return OperationResult::failure('attachment_too_large', [
                'max_bytes' => self::MAX_BYTES,
                'bytes' => $file->sizeBytes,
            ]);
        }
        if (!in_array($file->detectedMime, self::ALLOWED_MIME, true)) {
            // The DETECTED type, never the extension.
            return OperationResult::failure('attachment_type_not_allowed', [
                'mime' => $file->detectedMime,
            ]);
        }
        if (count($this->forMessage($ticketId, $messageId)) >= self::MAX_PER_MESSAGE) {
            return OperationResult::failure('attachment_too_many', [
                'max' => self::MAX_PER_MESSAGE,
            ]);
        }

        try {
            $stored = $this->storage->store($file, 'tickets/' . $ticketId);
        } catch (PrivateStorageUnavailable $e) {
            return OperationResult::failure('attachment_storage_unavailable', [
                'reason' => $e->getMessage(),
            ]);
        } catch (\RuntimeException) {
            return OperationResult::failure('attachment_storage_failed');
        }

        $id = $this->repository->addTicketAttachment(new TicketAttachment(
            0,
            $ticketId,
            $messageId,
            $actorId,
            $file->originalName,
            $stored->relativePath,
            $stored->mime,
            $stored->sizeBytes,
            false,
            '',
            null,
            $this->clock->now()->format('Y-m-d H:i:s')
        ));
        if ($id === 0) {
            // The row is what makes the file findable. Without it the bytes
            // are unreachable by anything, so they are removed rather than
            // left as an orphan nobody can audit.
            $this->storage->delete($stored->relativePath);
            return OperationResult::failure('attachment_storage_failed');
        }

        $this->audit->log(AuditEventCatalog::TICKET_FILE_ATTACHED, $actorId, 'ticket', (string) $ticketId, [
            'ticket_id' => $ticketId,
            'message_id' => $messageId,
            'attachment_id' => $id,
            'bytes' => $stored->sizeBytes,
            'mime' => $stored->mime,
        ]);
        return OperationResult::success('attachment_added', [
            'attachment_id' => $id,
            'name' => $file->originalName,
        ]);
    }

    /** @return list<TicketAttachment> */
    public function forTicket(int $actorId, int $ticketId): array
    {
        if (!$this->access->mayPostTo($actorId, $ticketId)) {
            return [];
        }
        return $this->repository->attachmentsForTicket($ticketId);
    }

    /** @return list<TicketAttachment> */
    public function forMessage(int $ticketId, int $messageId): array
    {
        $files = [];
        foreach ($this->repository->attachmentsForTicket($ticketId) as $attachment) {
            if ($attachment->messageId === $messageId) {
                $files[] = $attachment;
            }
        }
        return $files;
    }

    /**
     * The bytes, or null — the ONLY way out of private storage.
     *
     * Ownership is asked first and the file is read second, so a wrong answer
     * costs nothing and reveals nothing: a stranger and a missing file are the
     * same null.
     */
    public function read(int $actorId, int $attachmentId): ?array
    {
        $attachment = $this->repository->findAttachment($attachmentId);
        if ($attachment === null) {
            return null;
        }
        if (!$this->mayRead($actorId, $attachment)) {
            return null;
        }
        $bytes = $this->storage->read($attachment->storedPath);
        if ($bytes === null) {
            return null;
        }
        $this->audit->log(AuditEventCatalog::TICKET_FILE_READ, $actorId, 'ticket', (string) $attachment->ticketId, [
            'attachment_id' => $attachment->id,
            'ticket_id' => $attachment->ticketId,
        ]);
        return [
            'bytes' => $bytes,
            'mime' => $attachment->mime,
            'name' => $attachment->originalName,
            'size' => $attachment->sizeBytes,
        ];
    }

    /** Hides a file with a reason, and never deletes it. */
    public function hide(int $actorId, int $attachmentId, string $reason): OperationResult
    {
        $attachment = $this->repository->findAttachment($attachmentId);
        if ($attachment === null) {
            return OperationResult::failure('not_found');
        }
        if (!$this->access->mayModerate($actorId)) {
            return OperationResult::failure('forbidden');
        }
        if (trim($reason) === '') {
            return OperationResult::failure('hide_reason_required');
        }
        if (!$this->repository->hideAttachment($attachmentId, $actorId, trim($reason))) {
            return OperationResult::failure('storage_failed');
        }
        $this->audit->log(AuditEventCatalog::TICKET_FILE_HIDDEN, $actorId, 'ticket', (string) $attachment->ticketId, [
            'attachment_id' => $attachmentId,
            'ticket_id' => $attachment->ticketId,
            'reason' => trim($reason),
        ]);
        return OperationResult::success('attachment_hidden', ['attachment_id' => $attachmentId]);
    }

    private function mayRead(int $actorId, TicketAttachment $attachment): bool
    {
        if ($attachment->hidden) {
            // A hidden file stays readable by the manager who has to review
            // the decision, and by nobody else — including the person who
            // uploaded it.
            return $this->access->mayModerate($actorId);
        }
        return $this->access->mayPostTo($actorId, $attachment->ticketId);
    }
}
