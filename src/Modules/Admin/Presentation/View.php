<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Admin\Presentation;

/** Includes a template with exactly one variable in scope: $vm. */
final class View
{
    /** @param array<string,mixed> $vm */
    public static function render(string $name, array $vm): void
    {
        if (preg_match('/^[a-z-]+$/', $name) !== 1) {
            throw new \InvalidArgumentException('Invalid view name.');
        }
        $file = __DIR__ . '/Views/' . $name . '.php';
        (static function (string $__file, array $vm): void {
            include $__file;
        })($file, $vm);
    }

    /** @param array<string,mixed> $vm */
    public static function capture(string $name, array $vm): string
    {
        ob_start();
        try {
            self::render($name, $vm);
        } finally {
            $out = ob_get_clean();
        }
        return (string) $out;
    }
}
