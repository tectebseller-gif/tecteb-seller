<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts;

enum ModuleKind: string
{
    /** Always-on plumbing (core, environment guard). */
    case Infrastructure = 'infrastructure';
    /** Feature module with real code in this release. */
    case Operational = 'operational';
    /** Roadmap entry only: no code, no hooks, cannot be activated. */
    case Planned = 'planned';
}
