<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Contracts\TransactionInterface;

/**
 * A real transaction with one named operation refused.
 *
 * `begin` and `commit` are the two that `alpha.53` never checked, and a
 * refusal has to be a fact about THIS code rather than about a mock — so
 * everything else goes to the gateway underneath, and a refused `commit`
 * really rolls the transaction back, which is what a failed commit does.
 */
final class RefusingTransaction implements TransactionInterface
{
    public int $begins = 0;
    public int $commits = 0;
    public int $rollbacks = 0;

    public function __construct(
        private readonly ?TransactionInterface $inner = null,
        private readonly string $refuse = 'begin'
    ) {
    }

    public function begin(): bool
    {
        $this->begins++;
        if ($this->refuse === 'begin') {
            return false;
        }
        return $this->inner?->begin() ?? true;
    }

    public function commit(): bool
    {
        $this->commits++;
        if ($this->refuse === 'commit') {
            // A commit that fails leaves nothing committed, so the real
            // transaction goes back — otherwise the test would be measuring a
            // commit that silently succeeded.
            $this->inner?->rollback();
            return false;
        }
        return $this->inner?->commit() ?? true;
    }

    public function rollback(): bool
    {
        $this->rollbacks++;
        return $this->inner?->rollback() ?? true;
    }

    public function inTransaction(): bool
    {
        return $this->inner?->inTransaction() ?? false;
    }
}
