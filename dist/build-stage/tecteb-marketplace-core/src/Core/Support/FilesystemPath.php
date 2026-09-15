<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Support;

/**
 * Lexical path arithmetic, with no filesystem behind it.
 *
 * realpath() cannot answer these questions: the directory being judged may
 * not exist yet, and a containment check that silently returns false for a
 * missing path would read as "outside the web root" — the dangerous answer.
 * So this compares strings, deliberately, and callers probe the filesystem
 * separately.
 */
final class FilesystemPath
{
    /** Collapses separators, resolves `.` and `..`, drops the trailing slash. */
    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '') {
            return '';
        }
        $absolute = str_starts_with($path, '/');
        $out = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($out !== [] && end($out) !== '..') {
                    array_pop($out);
                    continue;
                }
                if ($absolute) {
                    continue;   // `/..` is still `/`
                }
            }
            $out[] = $part;
        }
        return ($absolute ? '/' : '') . implode('/', $out);
    }

    /** True when $path IS $root or sits under it. */
    public static function isInside(string $path, string $root): bool
    {
        $path = self::normalize($path);
        $root = self::normalize($root);
        if ($root === '' || $path === '') {
            return false;
        }
        return $path === $root || str_starts_with($path . '/', $root . '/');
    }

    /** @param list<string> $roots */
    public static function isInsideAny(string $path, array $roots): bool
    {
        foreach ($roots as $root) {
            if (self::isInside($path, $root)) {
                return true;
            }
        }
        return false;
    }
}
