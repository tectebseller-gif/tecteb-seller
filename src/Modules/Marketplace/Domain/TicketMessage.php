<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Domain;

/**
 * One message in a thread — written once and never edited.
 *
 * UX §10.1: «پیام و فایل ویرایش عادی ندارند؛ پنهان‌سازی مدیریتی محتوای حساس با
 * دلیل و audit ممکن است». So there is no edit path anywhere in this module.
 * Hiding sets a flag and a reason and leaves the row exactly as it was, which
 * is the difference between moderating a conversation and rewriting it.
 */
final class TicketMessage
{
    public function __construct(
        public readonly int $id,
        public readonly int $ticketId,
        public readonly int $authorId,
        public readonly string $authorRole,
        public readonly string $body,
        public readonly bool $hidden = false,
        public readonly string $hiddenReason = '',
        public readonly ?int $hiddenBy = null,
        public readonly string $createdAt = ''
    ) {
    }

    /** What a reader is shown: the text, or the fact that it was hidden. */
    public function visibleBody(): string
    {
        return $this->hidden ? '' : $this->body;
    }
}
