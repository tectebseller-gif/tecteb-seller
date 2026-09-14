<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Domain;

/**
 * A field the vendor cannot change alone (UX §11: store name, and banking).
 *
 * The old value is kept beside the new one so the manager decides with both
 * in front of them, and so a rejected request leaves a record of what was
 * asked — an audit trail the vendor cannot rewrite by asking again.
 */
final class ChangeRequest
{
    public const FIELD_STORE_NAME = 'store_name';
    public const FIELD_BANK = 'bank';

    public function __construct(
        public readonly int $id,
        public readonly int $vendorUserId,
        public readonly string $field,
        public readonly string $currentValue,
        public readonly string $requestedValue,
        public readonly ChangeRequestStatus $status,
        public readonly string $note = '',
        public readonly ?int $reviewedBy = null,
        public readonly ?string $reviewedAt = null,
        public readonly string $createdAt = ''
    ) {
    }

    /** @return list<string> */
    public static function fields(): array
    {
        return [self::FIELD_STORE_NAME, self::FIELD_BANK];
    }
}
