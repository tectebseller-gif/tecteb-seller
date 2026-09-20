<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts;

/**
 * The ability to hold several statements open as one unit of work.
 *
 * Split out of `DatabaseInterface` so that an Application service which needs
 * «read, compare, write, and let nobody in between» can ask for exactly that
 * and nothing else. A service that takes the whole database gateway can also
 * write its own SQL, and the balance hand-over must not be able to.
 *
 * Nesting is counted, not saved: only the outermost `begin()` starts a real
 * transaction and only the outermost `commit()` commits. There are no
 * savepoints, so a `rollback()` at ANY depth abandons the whole unit of work
 * and resets the depth to zero — a partial rollback would hand the outer
 * caller half a written unit while reporting success.
 */
interface TransactionInterface
{
    /** Returns false when the statement itself failed. */
    public function begin(): bool;

    public function commit(): bool;

    public function rollback(): bool;

    /** True while a transaction opened through begin() is still open. */
    public function inTransaction(): bool;
}
