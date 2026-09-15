<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core;

/**
 * Small PSR-4 autoloader limited to the plugin namespace (ADR-001).
 *
 * Constraints (tested in tests/Unit/Core/AutoloaderTest.php):
 *  - answers ONLY the Tecteb\Marketplace\ prefix; returns immediately otherwise
 *  - deterministic PSR-4 mapping onto the configured base directory
 *  - never errors on a missing file (lets the next autoloader run)
 *  - idempotent registration, appended (not prepended) to the SPL chain
 *  - no WordPress dependency
 */
final class Autoloader
{
    public const PREFIX = 'Tecteb\\Marketplace\\';

    private static ?self $registered = null;

    private string $baseDir;

    private function __construct(string $baseDir)
    {
        $this->baseDir = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR;
    }

    /** Registers once; later calls are no-ops and return the same instance. */
    public static function register(string $baseDir): self
    {
        if (self::$registered !== null) {
            return self::$registered;
        }
        $loader = new self($baseDir);
        spl_autoload_register([$loader, 'load'], true, false);
        self::$registered = $loader;
        return $loader;
    }

    public static function isRegistered(): bool
    {
        return self::$registered !== null;
    }

    /** Resolves a class name to a file path, or null when outside the prefix. */
    public function resolve(string $class): ?string
    {
        $len = strlen(self::PREFIX);
        if (strncmp($class, self::PREFIX, $len) !== 0) {
            return null;
        }
        $relative = substr($class, $len);
        if ($relative === '' || str_contains($relative, '..') || str_contains($relative, '/')) {
            return null;
        }
        return $this->baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    }

    public function load(string $class): void
    {
        $file = $this->resolve($class);
        if ($file === null || !is_file($file)) {
            return;
        }
        require $file;
    }
}
