<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Migration;

enum MigrationStatus: string
{
    case UpToDate = 'up_to_date';
    case Applied = 'applied';
    /** Another run holds the lock; this run did nothing. */
    case Locked = 'locked';
    /**
     * This run's lock expired and a newer run took it over. The old run stops
     * and writes NOTHING: neither the schema version nor the last-error
     * option, because the new owner is authoritative.
     */
    case LockLost = 'lock_lost';
    case Failed = 'failed';
    /** Stored schema is NEWER than this build supports; never migrated down. */
    case Ahead = 'ahead';
}
