<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Marketplace\Domain\Notification;
use Tecteb\Marketplace\Modules\Marketplace\Domain\Ticket;
use Tecteb\Marketplace\Modules\Marketplace\Domain\TicketMessage;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffArea;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffLevel;

/**
 * The vendor↔marketplace conversation (UX §10.1), with the two properties
 * that spec insists on.
 *
 * **Messages are never edited.** There is no update path in this service or
 * in the repository under it — not a guarded one, not an "admin only" one.
 * A manager may HIDE a message, which keeps the row and its text and records
 * who hid it and why, and that is the whole of what moderation can do here.
 *
 * **A lock stops both sides.** The manager may lock a thread; a lock that only
 * silenced the vendor would leave a manager talking at somebody who cannot
 * answer.
 *
 * Nothing expires. How long a thread is kept is part of DEC-05 («زمان نگهداری
 * مدارک/تیکت»), so there is no retention window here and nothing deletes
 * itself on a timer.
 */
final class ManageTickets
{
    public const RETENTION_UNDECIDED = 'ticket_retention_undecided';
    public const DECISION = 'DEC-05';

    public function __construct(
        private readonly EngagementRepositoryInterface $repository,
        private readonly StaffAccess $access,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
        private readonly ?CapabilityCheckerInterface $capabilities = null,
        // Optional so this service still constructs where notices are not
        // wired — the conversation is the feature, the notice is a courtesy,
        // and a missing courtesy must not take the conversation down with it.
        private readonly ?Notify $notify = null
    ) {
    }

    public function open(int $actorId, int $vendorUserId, string $subject, string $body, string $orderRef = ''): OperationResult
    {
        if (!$this->mayTalk($actorId, $vendorUserId)) {
            return OperationResult::failure('forbidden');
        }
        $subject = trim($subject);
        $body = trim($body);
        if ($subject === '' || $body === '') {
            return OperationResult::failure('ticket_empty');
        }
        $ticketId = $this->repository->openTicket(new Ticket(
            0,
            $vendorUserId,
            $subject,
            Ticket::OPEN,
            trim($orderRef),
            false,
            $actorId
        ));
        if ($ticketId === 0) {
            return OperationResult::failure('storage_failed');
        }
        $messageId = $this->post($ticketId, $actorId, Ticket::ROLE_VENDOR, $body);
        $this->audit->log(AuditEventCatalog::TICKET_OPENED, $actorId, 'ticket', (string) $ticketId, [
            'vendor_id' => $vendorUserId,
            'ticket_id' => $ticketId,
            'has_order_ref' => trim($orderRef) !== '',
        ]);
        // The message id travels back because an attachment hangs off a
        // MESSAGE, not off a thread, and the caller has no other way to learn
        // which one was just written.
        return OperationResult::success('ticket_opened', [
            'ticket_id' => $ticketId,
            'message_id' => $messageId,
        ]);
    }

    public function reply(int $actorId, int $ticketId, string $body, bool $asManager = false): OperationResult
    {
        $ticket = $this->repository->findTicket($ticketId);
        if ($ticket === null) {
            return OperationResult::failure('not_found');
        }
        $role = $asManager ? Ticket::ROLE_MANAGER : Ticket::ROLE_VENDOR;
        if ($asManager) {
            if ($this->capabilities === null || !$this->capabilities->can(Capabilities::REVIEW_VENDOR)) {
                return OperationResult::failure('forbidden');
            }
        } elseif (!$this->mayTalk($actorId, $ticket->vendorUserId)) {
            return OperationResult::failure('forbidden');
        }
        if (!$ticket->acceptsReplies()) {
            return OperationResult::failure($ticket->locked ? 'ticket_locked' : 'ticket_closed');
        }
        $body = trim($body);
        if ($body === '') {
            return OperationResult::failure('ticket_empty');
        }
        $messageId = $this->post($ticketId, $actorId, $role, $body);
        if ($messageId === 0) {
            return OperationResult::failure('storage_failed');
        }
        $this->audit->log(AuditEventCatalog::TICKET_REPLIED, $actorId, 'ticket', (string) $ticketId, [
            'vendor_id' => $ticket->vendorUserId,
            'ticket_id' => $ticketId,
            'role' => $role,
        ]);
        // Whoever did NOT write this message is the one who needs telling.
        if ($role === Ticket::ROLE_MANAGER) {
            $this->notify?->toStore(
                $ticket->vendorUserId,
                Notify::TICKET_REPLIED,
                Notification::SUBJECT_TICKET,
                (string) $ticketId . ':' . $messageId,
                ['subject' => $ticket->subject]
            );
        }
        return OperationResult::success('ticket_replied', [
            'ticket_id' => $ticketId,
            'message_id' => $messageId,
        ]);
    }

    /** Closing, reopening or locking — all a manager's, all recorded. */
    public function setState(int $actorId, int $ticketId, string $status, bool $locked): OperationResult
    {
        if ($this->capabilities === null || !$this->capabilities->can(Capabilities::REVIEW_VENDOR)) {
            return OperationResult::failure('forbidden');
        }
        $ticket = $this->repository->findTicket($ticketId);
        if ($ticket === null) {
            return OperationResult::failure('not_found');
        }
        if (!in_array($status, [Ticket::OPEN, Ticket::ANSWERED, Ticket::CLOSED], true)) {
            return OperationResult::failure('ticket_status_invalid');
        }
        if (!$this->repository->setTicketState($ticketId, $status, $locked, null, '')) {
            return OperationResult::failure('storage_failed');
        }
        $this->audit->log(AuditEventCatalog::TICKET_STATE_CHANGED, $actorId, 'ticket', (string) $ticketId, [
            'vendor_id' => $ticket->vendorUserId,
            'ticket_id' => $ticketId,
            'to' => $status,
            'locked' => $locked,
        ]);
        return OperationResult::success('ticket_' . $status, ['ticket_id' => $ticketId]);
    }

    /**
     * Hides a message. Needs a reason, and keeps the text.
     *
     * The reason is required rather than optional because UX §10.1 asks for
     * «با دلیل و audit», and a hidden message with no stated reason is
     * indistinguishable from one that was hidden by mistake.
     */
    public function hideMessage(int $actorId, int $messageId, string $reason): OperationResult
    {
        if ($this->capabilities === null || !$this->capabilities->can(Capabilities::REVIEW_VENDOR)) {
            return OperationResult::failure('forbidden');
        }
        $reason = trim($reason);
        if ($reason === '') {
            return OperationResult::failure('hide_reason_required');
        }
        if (!$this->repository->hideTicketMessage($messageId, $actorId, $reason)) {
            return OperationResult::failure('storage_failed');
        }
        $this->audit->log(AuditEventCatalog::TICKET_MESSAGE_HIDDEN, $actorId, 'ticket_message', (string) $messageId, [
            'message_id' => $messageId,
            'has_reason' => true,
        ]);
        return OperationResult::success('ticket_message_hidden', ['message_id' => $messageId]);
    }

    /** @return list<Ticket> */
    public function forVendor(int $actorId, int $vendorUserId): array
    {
        if (!$this->access->can($actorId, $vendorUserId, StaffArea::Order, StaffLevel::View)) {
            return [];
        }
        return $this->repository->ticketsForVendor($vendorUserId);
    }

    /** @return list<Ticket> */
    public function forManager(?string $status = null): array
    {
        if ($this->capabilities === null || !$this->capabilities->can(Capabilities::REVIEW_VENDOR)) {
            return [];
        }
        return $this->repository->allTickets($status);
    }

    /** @return list<TicketMessage> */
    public function thread(int $actorId, int $ticketId): array
    {
        $ticket = $this->repository->findTicket($ticketId);
        if ($ticket === null) {
            return [];
        }
        $isManager = $this->capabilities?->can(Capabilities::REVIEW_VENDOR) ?? false;
        if (!$isManager && !$this->access->can($actorId, $ticket->vendorUserId, StaffArea::Order, StaffLevel::View)) {
            return [];
        }
        return $this->repository->ticketMessages($ticketId);
    }

    /**
     * May this person take part in this thread at all?
     *
     * The one question anything hanging off a ticket has to ask — a file, in
     * particular. Public because the attachment service is a separate class
     * and must not re-derive the answer: two places deciding who is in a
     * conversation is how they end up disagreeing.
     */
    public function mayPostTo(int $actorId, int $ticketId): bool
    {
        $ticket = $this->repository->findTicket($ticketId);
        if ($ticket === null) {
            return false;
        }
        return $this->mayModerate($actorId) || $this->mayTalk($actorId, $ticket->vendorUserId);
    }

    /**
     * Whether this person is the marketplace's side of every conversation.
     *
     * Hiding a message or a file is a manager's act and is reviewable, so the
     * capability is the same one that reviews vendors — there is no separate
     * «moderator» role, and inventing one would put a permission in the code
     * that nobody approved.
     */
    public function mayModerate(int $actorId): bool
    {
        return $this->capabilities?->can(Capabilities::REVIEW_VENDOR) ?? false;
    }

    /**
     * Answering is «پاسخ», which is the support role's level — not «ویرایش».
     * A shop's support staff may talk to the marketplace without being able to
     * ship or cancel anything.
     */
    private function mayTalk(int $actorId, int $vendorUserId): bool
    {
        return $this->access->can($actorId, $vendorUserId, StaffArea::Order, StaffLevel::Respond);
    }

    private function post(int $ticketId, int $authorId, string $role, string $body): int
    {
        $messageId = $this->repository->addTicketMessage(
            new TicketMessage(0, $ticketId, $authorId, $role, $body)
        );
        if ($messageId === 0) {
            return 0;
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        // A manager's reply answers the thread; a vendor's reopens the wait.
        $this->repository->setTicketState(
            $ticketId,
            $role === Ticket::ROLE_MANAGER ? Ticket::ANSWERED : Ticket::OPEN,
            false,
            $now,
            $role
        );
        return $messageId;
    }
}
