<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Migration;

enum MigrationStatus: string
{
    case UpToDate = 'up_to_date';
    case Applied = 'applied';
    case Locked = 'locked';
    case Failed = 'failed';
}
