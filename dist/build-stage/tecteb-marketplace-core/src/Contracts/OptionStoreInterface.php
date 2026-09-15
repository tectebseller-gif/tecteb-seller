<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts;

/**
 * Key/value option persistence (wp_options behind the WordPress adapter).
 */
interface OptionStoreInterface
{
    public function get(string $key, mixed $default = null): mixed;

    /** Creates or updates. Returns false only on persistence failure. */
    public function set(string $key, mixed $value): bool;

    public function delete(string $key): bool;
}
