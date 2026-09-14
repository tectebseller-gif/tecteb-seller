<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Every `Something::class` in shipped code must resolve to a class that
 * exists.
 *
 * PHP resolves an unimported `Foo::class` to the CURRENT namespace and says
 * nothing: it is a valid string, the file lints clean, and every unit test
 * passes because none of them touches that line. The first sign is a fatal
 * error on a real page — which is exactly how this was found, on the store
 * settings screen, one build before delivery.
 */
final class ClassReferenceTest extends TestCase
{
    public function testEveryClassConstantReferenceResolves(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $unresolved = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            $namespace = preg_match('/^namespace\s+([^;]+);/m', $source, $m) === 1 ? trim($m[1]) : '';
            preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?;/m', $source, $useMatches, PREG_SET_ORDER);
            $aliases = [];
            foreach ($useMatches as $use) {
                $alias = $use[2] ?? substr(strrchr('\\' . $use[1], '\\') ?: '', 1);
                $aliases[$alias] = $use[1];
            }

            // Only UNQUALIFIED references: one already written as
            // \Some\Fully\Qualified\Name::class resolves on its own, and
            // matching its last segment would report a false problem.
            preg_match_all('/(?<![\\\\\w])([A-Z][A-Za-z0-9_]*)::class\b/', $source, $refs);
            foreach (array_unique($refs[1]) as $short) {
                if ($short === 'self' || $short === 'static' || $short === 'parent') {
                    continue;
                }
                $candidates = [
                    $aliases[$short] ?? null,
                    $namespace !== '' ? $namespace . '\\' . $short : $short,
                    $short,
                ];
                $found = false;
                foreach (array_filter($candidates) as $fqcn) {
                    if (class_exists($fqcn) || interface_exists($fqcn) || enum_exists($fqcn) || trait_exists($fqcn)) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $unresolved[] = str_replace($root . '/', '', $file->getPathname()) . ': ' . $short . '::class';
                }
            }
        }

        self::assertSame([], $unresolved, "these resolve to nothing at runtime:\n" . implode("\n", $unresolved));
    }
}
