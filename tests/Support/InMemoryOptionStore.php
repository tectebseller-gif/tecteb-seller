<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Contracts\OptionStoreInterface;

/** Not final: tests subclass it to model a store that throws. */
class InMemoryOptionStore implements OptionStoreInterface
{
    /** @var array<string,mixed> */
    public array $data = [];
    public bool $failWrites = false;
    public int $writes = 0;

    /**
     * Fired immediately BEFORE a write lands. Lets a test model a take-over
     * that happens in the gap between an ownership check and the write — the
     * gap an unguarded "check, then write" implementation leaves open.
     *
     * @var null|callable(string,mixed):void
     */
    public $onWrite = null;
    private bool $inHook = false;

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function set(string $key, mixed $value): bool
    {
        if ($this->onWrite !== null && !$this->inHook) {
            $this->inHook = true;
            try {
                ($this->onWrite)($key, $value);
            } finally {
                $this->inHook = false;
            }
        }
        if ($this->failWrites) {
            return false;
        }
        $this->data[$key] = $value;
        $this->writes++;
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->data[$key]);
        return true;
    }
}
