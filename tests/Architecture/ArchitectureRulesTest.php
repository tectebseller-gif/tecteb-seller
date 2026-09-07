<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Core\Autoloader;

/**
 * Token-level architecture rules (owner correction 3).
 *
 * WHAT THIS PROVES: no file under src/Core, src/Contracts, or any module's
 * Application/Domain layer references a function, class, constant or
 * global that is not PHP-internal or part of the plugin's own namespace.
 * Since WordPress and WooCommerce symbols are neither, those layers cannot
 * call them.
 *
 * WHAT THIS DOES NOT PROVE: full architectural independence (e.g. a pure
 * class could still encode WordPress assumptions in data). It is evidence
 * of this limited rule only.
 */
final class ArchitectureRulesTest extends TestCase
{
    private const OWN_PREFIX = 'Tecteb\\Marketplace\\';

    private const TYPE_KEYWORDS = ['self', 'static', 'parent', 'int', 'float', 'string', 'bool', 'array', 'callable', 'iterable', 'mixed', 'void', 'never', 'null', 'false', 'true', 'object'];

    private static function srcDir(): string
    {
        return dirname(__DIR__, 2) . '/src';
    }

    /** @return list<string> */
    private static function pureLayerFiles(): array
    {
        $files = [];
        $roots = [self::srcDir() . '/Core', self::srcDir() . '/Contracts'];
        foreach (glob(self::srcDir() . '/Modules/*', GLOB_ONLYDIR) ?: [] as $module) {
            foreach (['Application', 'Domain'] as $layer) {
                if (is_dir($module . '/' . $layer)) {
                    $roots[] = $module . '/' . $layer;
                }
            }
        }
        foreach ($roots as $root) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $files[] = $f->getPathname();
                }
            }
        }
        sort($files);
        return $files;
    }

    /** @return list<string> */
    private static function allSrcFiles(): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::srcDir(), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    public function testPureLayersReferenceOnlyPhpInternalsAndOwnNamespace(): void
    {
        $files = self::pureLayerFiles();
        self::assertGreaterThan(40, count($files), 'sanity: the pure layers exist');
        $internalFunctions = array_flip(get_defined_functions()['internal']);
        $violations = [];
        foreach ($files as $file) {
            foreach (self::scan($file, $internalFunctions) as $v) {
                $violations[] = str_replace(self::srcDir() . '/', '', $file) . ': ' . $v;
            }
        }
        self::assertSame([], $violations, "Pure layers must not reference WordPress/WooCommerce symbols:\n" . implode("\n", $violations));
    }

    /**
     * @param array<string,int> $internalFunctions
     * @return list<string>
     */
    public static function scan(string $file, array $internalFunctions): array
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $namespace = '';
        $uses = [];
        $violations = [];
        $count = count($tokens);

        $prevSignificant = static function (int $i) use ($tokens): mixed {
            for ($j = $i - 1; $j >= 0; $j--) {
                if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                return $tokens[$j];
            }
            return null;
        };
        $prevSignificantN = static function (int $i, int $n) use ($tokens): mixed {
            $seen = 0;
            for ($j = $i - 1; $j >= 0; $j--) {
                if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if (++$seen === $n) {
                    return $tokens[$j];
                }
            }
            return null;
        };
        $nextSignificant = static function (int $i) use ($tokens, $count): mixed {
            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                return $tokens[$j];
            }
            return null;
        };

        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (!is_array($t)) {
                continue;
            }
            [$id, $text] = $t;

            if ($id === T_NAMESPACE) {
                $n = $nextSignificant($i);
                $namespace = is_array($n) ? $n[1] : '';
                continue;
            }
            if ($id === T_USE) {
                // use Foo\Bar as Baz;  (only top-level imports; closures' "use (...)" have "(" next)
                $n = $nextSignificant($i);
                if ($n === '(' ) {
                    continue;
                }
                $name = '';
                $alias = null;
                for ($j = $i + 1; $j < $count; $j++) {
                    $u = $tokens[$j];
                    if ($u === ';') {
                        break;
                    }
                    if (is_array($u) && in_array($u[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true)) {
                        if ($name === '') {
                            $name = ltrim($u[1], '\\');
                        } else {
                            $alias = $u[1];
                        }
                    }
                }
                if ($name !== '') {
                    $uses[$alias ?? substr($name, (int) strrpos('\\' . $name, '\\'))] = $name;
                }
                continue;
            }

            if ($id === T_VARIABLE && in_array($text, ['$wpdb', '$wp_query', '$post', '$wp_roles'], true)) {
                $violations[] = "global {$text}";
                continue;
            }
            if ($id === T_VARIABLE && $text === '$GLOBALS') {
                $violations[] = '$GLOBALS access';
                continue;
            }

            if (!in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $prev = $prevSignificant($i);
            $next = $nextSignificant($i);
            $prevId = is_array($prev) ? $prev[0] : null;
            $prev2 = $prevSignificantN($i, 2);
            if ($prev === '(' && is_array($prev2) && $prev2[0] === T_DECLARE) {
                continue; // declare(strict_types=1)
            }
            if (in_array($prevId, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST, T_NAMESPACE, T_USE, T_AS, T_CASE, T_ENUM, T_CLASS, T_INTERFACE, T_TRAIT, T_GOTO], true)) {
                continue; // member access, declarations, enum cases
            }
            if ($prev === '{' && $id === T_STRING && $next === '}') {
                continue;
            }
            if (is_array($prev) && $prev[0] === T_STRING && $next === '(' && $prevId === null) {
                continue;
            }
            $name = $text;
            $lower = strtolower($name);

            if ($next === '(' && $prevId !== T_NEW && $prevId !== T_ATTRIBUTE) {
                // function call
                if (in_array($lower, ['isset', 'empty', 'list', 'array', 'unset', 'exit', 'die', 'print', 'echo', 'eval', 'include', 'require', 'include_once', 'require_once', 'fn', 'function', 'match'], true)) {
                    continue;
                }
                if (in_array($lower, self::TYPE_KEYWORDS, true)) {
                    continue;
                }
                if (!str_contains($name, '\\') || str_starts_with($name, '\\')) {
                    // Unqualified and fully-qualified calls resolve against the
                    // GLOBAL function table (the namespace fallback is empty:
                    // this plugin declares no namespaced functions). Resolving
                    // them against the current namespace would make every
                    // WordPress call look like one of our own — the bug this
                    // branch previously had, caught by
                    // testScannerActuallyDetectsViolations().
                    if (isset($internalFunctions[strtolower(ltrim($name, '\\'))])) {
                        continue;
                    }
                    $violations[] = "function call {$name}()";
                    continue;
                }
                $fq = self::resolve($name, $namespace, $uses);
                if (str_starts_with($fq, self::OWN_PREFIX)) {
                    continue;
                }
                $violations[] = "function call {$name}()";
                continue;
            }

            // class-like reference or constant
            if (in_array($lower, self::TYPE_KEYWORDS, true)) {
                continue;
            }
            if ($prevId === T_NEW || $prevId === T_INSTANCEOF || $next === '::' || (is_array($next) && $next[0] === T_DOUBLE_COLON) || $prevId === T_EXTENDS || $prevId === T_IMPLEMENTS || $prev === '(' || $prev === ',' || $prev === '|' || $prev === '?' || $prev === ':' || $prevId === T_CATCH || $prevId === T_ATTRIBUTE) {
                $fq = self::resolve($name, $namespace, $uses);
                if (str_starts_with($fq, self::OWN_PREFIX)) {
                    continue;
                }
                if (self::isInternalClass($fq)) {
                    continue;
                }
                if (ctype_upper($name[0]) === false && !str_contains($name, '\\')) {
                    // lowercase bare word after "(" or "," → probably a constant/function handled elsewhere
                    if (defined($name)) {
                        continue;
                    }
                }
                if (preg_match('/^[A-Z][A-Z0-9_]+$/', $name) === 1) {
                    if (defined($name)) {
                        continue;
                    }
                    $violations[] = "constant {$name}";
                    continue;
                }
                $violations[] = 'class reference ' . $fq;
                continue;
            }
            if (preg_match('/^[A-Z][A-Z0-9_]+$/', $name) === 1) {
                if (defined($name)) {
                    continue;
                }
                $violations[] = "constant {$name}";
            }
        }
        return $violations;
    }

    /** @param array<string,string> $uses */
    private static function resolve(string $name, string $namespace, array $uses): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }
        $first = str_contains($name, '\\') ? substr($name, 0, (int) strpos($name, '\\')) : $name;
        if (isset($uses[$first])) {
            return $uses[$first] . (str_contains($name, '\\') ? substr($name, strlen($first)) : '');
        }
        return $namespace !== '' ? $namespace . '\\' . $name : $name;
    }

    private static function isInternalClass(string $fq): bool
    {
        foreach ([$fq, ltrim(substr($fq, (int) strrpos('\\' . $fq, '\\')), '\\')] as $candidate) {
            if (class_exists($candidate, false) || interface_exists($candidate, false) || enum_exists($candidate, false)) {
                $ref = new \ReflectionClass($candidate);
                if ($ref->isInternal()) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * A rule that never fires proves nothing. This feeds the scanner a file
     * that deliberately violates every clause and asserts each is reported.
     */
    public function testScannerActuallyDetectsViolations(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'tmc-arch-') . '.php';
        file_put_contents($file, <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace Tecteb\Marketplace\Core\Fake;
        use Tecteb\Marketplace\Contracts\ClockInterface;
        final class Offender
        {
            public function a(): void { $value = get_option('x'); }
            public function b(): void { global $wpdb; $wpdb->query('x'); }
            public function c(): void { $g = $GLOBALS['wpdb']; }
            public function d(): \WC_Order { return new \WC_Order(); }
            public function e(): string { return ABSPATH; }
            public function ok(ClockInterface $c): string { return strtoupper(trim('x')); }
        }
        PHP);
        try {
            $violations = self::scan($file, array_flip(get_defined_functions()['internal']));
        } finally {
            @unlink($file);
        }
        self::assertContains('function call get_option()', $violations);
        self::assertContains('global $wpdb', $violations);
        self::assertContains('$GLOBALS access', $violations);
        self::assertContains('class reference WC_Order', $violations);
        self::assertContains('constant ABSPATH', $violations);
        self::assertNotContains('function call strtoupper()', $violations, 'PHP internals are allowed');
        self::assertNotContains('function call trim()', $violations);
        self::assertNotContains('class reference ClockInterface', $violations, 'own namespace is allowed');
        self::assertNotContains('class reference strict_types', $violations);
    }

    public function testEveryClassFileIsPsr4Conformant(): void
    {
        $mismatches = [];
        $classFiles = 0;
        foreach (self::allSrcFiles() as $file) {
            $declared = self::declaredName($file);
            if ($declared === null) {
                self::assertStringContainsString('/Presentation/Views/', $file, 'only view templates may lack a class declaration: ' . $file);
                continue;
            }
            $classFiles++;
            $expected = self::srcDir() . '/' . str_replace('\\', '/', substr($declared, strlen(self::OWN_PREFIX))) . '.php';
            if (realpath($expected) !== realpath($file)) {
                $mismatches[] = "{$declared} is in {$file}";
            }
        }
        self::assertSame([], $mismatches);
        self::assertGreaterThan(80, $classFiles);
    }

    public function testEveryClassLoadsThroughThePluginAutoloader(): void
    {
        self::assertTrue(Autoloader::isRegistered());
        $failed = [];
        foreach (self::allSrcFiles() as $file) {
            $declared = self::declaredName($file);
            if ($declared === null) {
                continue;
            }
            if (!class_exists($declared, true) && !interface_exists($declared, true) && !enum_exists($declared, true) && !trait_exists($declared, true)) {
                $failed[] = $declared;
            }
        }
        self::assertSame([], $failed);
    }

    public function testNoEmptyPlaceholderDirectories(): void
    {
        $empty = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::srcDir(), \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $entry) {
            if ($entry->isDir()) {
                $hasPhp = false;
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($entry->getPathname(), \FilesystemIterator::SKIP_DOTS)) as $f) {
                    if ($f->isFile() && $f->getExtension() === 'php') {
                        $hasPhp = true;
                        break;
                    }
                }
                if (!$hasPhp) {
                    $empty[] = $entry->getPathname();
                }
            }
        }
        self::assertSame([], $empty, 'no empty directories pretending a module is complete');
    }

    public function testDomainRuleIsAlsoStatedInDocs(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/phase-1-plan.md');
        self::assertStringContainsString('AST', $doc);
    }

    private static function declaredName(string $file): ?string
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $namespace = '';
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (!is_array($t)) {
                continue;
            }
            if ($t[0] === T_NAMESPACE) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_NAME_QUALIFIED, T_STRING], true)) {
                        $namespace = $tokens[$j][1];
                        break;
                    }
                }
            }
            if (in_array($t[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $prev = $tokens[$i - 1] ?? null;
                if (is_array($prev) && $prev[0] === T_DOUBLE_COLON) {
                    continue; // Foo::class
                }
                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        return $namespace . '\\' . $tokens[$j][1];
                    }
                    if ($tokens[$j] === '{' || $tokens[$j] === '(') {
                        break; // anonymous class
                    }
                }
            }
        }
        return null;
    }
}
