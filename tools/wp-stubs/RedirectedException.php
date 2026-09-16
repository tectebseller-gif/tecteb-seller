<?php
declare(strict_types=1);

namespace TmcWpStubs;

/**
 * Stands in for the `exit` that follows every `wp_safe_redirect()` in this
 * plugin.
 *
 * A redirect in WordPress ends the request: the code after it never runs, and
 * a test that let execution continue would be measuring a world that does not
 * exist. `exit` itself cannot be tested in-process — it takes PHPUnit with it —
 * so with `State::$throwOnRedirect` on, the stub throws this instead. The test
 * catches it exactly where the real request would have stopped.
 *
 * Off by default, so every test written before this existed keeps its old
 * behaviour.
 */
final class RedirectedException extends \RuntimeException
{
    public function __construct(public readonly string $location, public readonly int $status)
    {
        parent::__construct('redirected ' . $status . ' to ' . $location);
    }
}
