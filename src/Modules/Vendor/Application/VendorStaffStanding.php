<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Application;

use Tecteb\Marketplace\Modules\Vendor\Domain\StaffPermissions;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffStatus;

/**
 * Where a viewer stands in the shop whose screen they are looking at.
 *
 * **Why this is not just the `StaffMember` row.** The dashboard needs two facts
 * about the person reading it — «are you on this roster, and is your access
 * live» — and the row carries their name, username, e-mail and mobile as well.
 * Handing the whole row to a view is how a field that nobody meant to print
 * ends up printed; handing these two is a promise the view cannot break.
 *
 * Both facts are about the VIEWER and nobody else, so there is nothing here
 * that belongs to the shop's owner or to another shop. The owner's application,
 * their review note and the shop's figures are all decided elsewhere and none
 * of them is reachable from this object.
 *
 * And it grants nothing. `StaffAccess::can()` is still the only answer to «may
 * this person do X», and every gate still asks it: `permissions` is here so a
 * screen can TELL somebody what their access covers, which is a different
 * sentence from letting them use it.
 */
final class VendorStaffStanding
{
    public function __construct(
        public readonly StaffStatus $status,
        public readonly StaffPermissions $permissions
    ) {
    }

    /**
     * Is this person's access live right now?
     *
     * Their own standing only. A live membership in a suspended shop still
     * answers `true` here and still does nothing, because the shop's standing is
     * the other half and the screen says both.
     */
    public function canAct(): bool
    {
        return $this->status->canAct();
    }
}
