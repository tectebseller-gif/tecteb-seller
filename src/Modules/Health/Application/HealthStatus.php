<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Health\Application;

/** UX-01: every check is healthy / action required / unknown — never "green by default". */
enum HealthStatus: string
{
    case Healthy = 'healthy';
    case ActionRequired = 'action_required';
    case Unknown = 'unknown';
}
