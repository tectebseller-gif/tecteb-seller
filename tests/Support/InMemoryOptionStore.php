<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Contracts\OptionStoreInterface;

final class InMemoryOptionStore implements OptionStoreInterface
{
    /** @var array<string,mixed> */
    public array $data = [];
    public bool $failWrites = false;
    public int $writes = 0;

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function set(string $key, mixed $value): bool
    {
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
