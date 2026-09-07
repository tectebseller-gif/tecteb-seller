<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Exceptions\ContainerException;

/**
 * Deliberately tiny: shared factories + instances. No autowiring, no
 * reflection. Enough for phase 1 (ARCH-01: "container پیچیده ... ضروری نیست").
 */
final class Container implements ContainerInterface
{
    /** @var array<string, callable> */
    private array $factories = [];

    /** @var array<string, object> */
    private array $instances = [];

    /** @var array<string, true> guards against circular factories */
    private array $resolving = [];

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]);
    }

    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if (!isset($this->factories[$id])) {
            throw new ContainerException('Unknown service: ' . $id);
        }
        if (isset($this->resolving[$id])) {
            throw new ContainerException('Circular dependency while resolving: ' . $id);
        }
        $this->resolving[$id] = true;
        try {
            $value = ($this->factories[$id])($this);
        } finally {
            unset($this->resolving[$id]);
        }
        if (is_object($value)) {
            $this->instances[$id] = $value;
        }
        return $value;
    }

    public function bind(string $id, callable $factory): void
    {
        unset($this->instances[$id]);
        $this->factories[$id] = $factory;
    }

    public function instance(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
    }
}
