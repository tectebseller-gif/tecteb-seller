<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Domain;

/**
 * Invited → active → suspended, and back.
 *
 * There is no `deleted`: the spec forbids removing a staff member's activity
 * with them (§3.1), so the membership row stays and only its status changes.
 */
enum StaffStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Suspended = 'suspended';

    /** Suspension is immediate: nothing a suspended member holds is honoured. */
    public function canAct(): bool
    {
        return $this === self::Active;
    }
}
