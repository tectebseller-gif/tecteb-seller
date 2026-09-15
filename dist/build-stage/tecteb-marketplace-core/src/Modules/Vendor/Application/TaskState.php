<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Application;

/**
 * Where one step of the vendor's journey stands.
 *
 * `Waiting` exists so the interface never asks someone to do something they
 * cannot do: waiting on the manager's review, or on a service the site has
 * not connected, is a state — not a task with a disabled button.
 */
enum TaskState: string
{
    case Done = 'done';
    case Todo = 'todo';
    case Waiting = 'waiting';
}
