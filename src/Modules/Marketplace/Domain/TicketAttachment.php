<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Domain;

/**
 * One file hanging off one ticket message.
 *
 * **There is no URL, and that is the design.** `storedPath` is a path inside
 * private storage, which lives outside every directory the web server can
 * serve (ADR-007). The only way to the bytes is a capability- and
 * ownership-checked read, so a property called `url` would be an invitation to
 * print something that must not exist.
 *
 * **Hiding is not deleting**, the same rule the messages follow: UX §10.1 says
 * «پیام و فایل ویرایش عادی ندارند؛ پنهان‌سازی مدیریتی … با دلیل و audit ممکن
 * است». A hidden attachment keeps its row, its size and its reason; what
 * changes is that nobody but the manager who hid it is offered the file.
 *
 * The original file name is kept for the person who sent it — but it is never
 * what the file is stored as, because a name a stranger chose is not a name to
 * put on a disk.
 */
final class TicketAttachment
{
    public function __construct(
        public readonly int $id,
        public readonly int $ticketId,
        public readonly int $messageId,
        public readonly int $uploadedBy,
        public readonly string $originalName,
        public readonly string $storedPath,
        public readonly string $mime,
        public readonly int $sizeBytes,
        public readonly bool $hidden = false,
        public readonly string $hiddenReason = '',
        public readonly ?int $hiddenBy = null,
        public readonly string $createdAt = ''
    ) {
    }

    /** What a reader may be offered: the name, or nothing at all. */
    public function visibleName(): string
    {
        return $this->hidden ? '' : $this->originalName;
    }
}
