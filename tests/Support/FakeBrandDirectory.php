<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Modules\Product\Application\BrandDirectoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\Brand;
use Tecteb\Marketplace\Modules\Product\Domain\BrandName;

/**
 * The manager's brand list, as a list — with the ONE behaviour that matters
 * kept honest: `register()` never makes a second term for one name.
 *
 * That is not a convenience here, it is the thing under test. A fake that
 * cheerfully inserted «آمبو بگ» beside «آمبوبگ» would let a test pass about a
 * rule the real taxonomy enforces through `findByKey()`.
 */
final class FakeBrandDirectory implements BrandDirectoryInterface
{
    public bool $available = true;

    /** The next write refuses, as a `wp_insert_term()` that would not store. */
    public bool $registerFails = false;

    /** @var array<int,string> term id => name */
    public array $terms = [];

    private int $nextId = 500;

    public function __construct(string ...$names)
    {
        foreach ($names as $name) {
            $this->terms[$this->nextId++] = BrandName::display($name);
        }
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function search(string $query, int $limit = 30): array
    {
        if (!$this->available) {
            return [];
        }
        $key = BrandName::key($query);
        $out = [];
        foreach ($this->terms as $id => $name) {
            if ($key === '' || str_contains(BrandName::key($name), $key)) {
                $out[] = new Brand((int) $id, $name);
            }
        }
        return array_slice($out, 0, max(1, $limit));
    }

    public function find(int $termId): ?Brand
    {
        if (!$this->available || !isset($this->terms[$termId])) {
            return null;
        }
        return new Brand($termId, $this->terms[$termId]);
    }

    public function findByKey(string $key): ?Brand
    {
        if (!$this->available || $key === '') {
            return null;
        }
        foreach ($this->terms as $id => $name) {
            if (BrandName::key($name) === $key) {
                return new Brand((int) $id, $name);
            }
        }
        return null;
    }

    public function register(string $name): ?Brand
    {
        if (!$this->available) {
            return null;
        }
        $display = BrandName::display($name);
        if (!BrandName::isValid($display)) {
            return null;
        }
        $existing = $this->findByKey(BrandName::key($display));
        if ($existing !== null) {
            return $existing;
        }
        if ($this->registerFails) {
            return null;
        }
        $id = $this->nextId++;
        $this->terms[$id] = $display;
        return new Brand($id, $display);
    }

    public function count(): int
    {
        return $this->available ? count($this->terms) : 0;
    }
}
