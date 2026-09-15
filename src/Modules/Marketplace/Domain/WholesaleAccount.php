<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Domain;

/** A buyer who asked to buy wholesale, and what a manager decided. */
final class WholesaleAccount
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly WholesaleStatus $status,
        public readonly string $company = '',
        public readonly string $registrationId = '',
        public readonly string $note = '',
        public readonly ?int $decidedBy = null,
        public readonly ?string $decidedAt = null,
        public readonly string $createdAt = ''
    ) {
    }
}
