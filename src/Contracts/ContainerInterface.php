<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts;

/**
 * Minimal dependency container used by modules during register()/boot().
 */
interface ContainerInterface
{
    public function has(string $id): bool;

    /** @throws \Tecteb\Marketplace\Core\Exceptions\ContainerException when $id is unknown */
    public function get(string $id): mixed;

    /** Registers a lazy, shared factory. */
    public function bind(string $id, callable $factory): void;

    /** Registers an already-built instance. */
    public function instance(string $id, object $instance): void;
}
