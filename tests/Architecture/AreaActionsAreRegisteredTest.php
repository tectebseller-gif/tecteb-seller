<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Every verb an area handles is also a verb the router lets through.
 *
 * The two live in different places: `ACTIONS` is what the router checks
 * BEFORE dispatching, and the `match ($action)` inside `handle()` is what
 * actually does the work. A verb in one and not the other is not an
 * «unhandled action» — it is a **forbidden** one, and the person is bounced
 * to another page with `tmc_notice=forbidden` and no explanation.
 *
 * That is what «پیش‌نمایش نتیجه» did for eleven versions: `previewBulk()`
 * existed, was wired into `handle()`, had a service behind it and a test of
 * that service — and the one line that lets a request reach it was missing.
 * Nothing noticed, because every test called the service directly.
 *
 * Read from the source, both sides. A test that imported the class and asked
 * it would need the whole container; this needs the file.
 */
final class AreaActionsAreRegisteredTest extends TestCase
{
    /** @return list<array{0:string,1:string}> path, class basename */
    private static function areas(): array
    {
        $out = [];
        $root = dirname(__DIR__, 2) . '/src';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            if (str_contains($src, 'public const ACTIONS')) {
                $out[] = [$file->getPathname(), $file->getBasename('.php')];
            }
        }
        sort($out);
        return $out;
    }

    /** The strings listed in `ACTIONS`. @return list<string> */
    private static function declared(string $src): array
    {
        if (!preg_match('/public const ACTIONS = \[(.*?)\];/s', $src, $m)) {
            return [];
        }
        preg_match_all("/'([a-z0-9_]+)'/", $m[1], $names);
        return $names[1];
    }

    /**
     * The strings the dispatch actually answers — the `match ($action)` arms.
     *
     * Comments are stripped first, so a verb named in a docblock (this file's
     * own lesson from `alpha.22`) is not mistaken for a handled one.
     *
     * @return list<string>
     */
    private static function dispatched(string $src): array
    {
        $code = '';
        foreach (token_get_all($src) as $token) {
            if (is_array($token)) {
                $code .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1];
                continue;
            }
            $code .= $token;
        }
        $at = strpos($code, 'match ($action)');
        if ($at === false) {
            return [];
        }
        // Up to the end of the match: the closing `};` of the statement.
        $end = strpos($code, '};', $at);
        $body = substr($code, $at, $end === false ? null : $end - $at);
        preg_match_all("/'([a-z0-9_]+)'\s*=>/", $body, $names);
        return array_values(array_unique($names[1]));
    }

    public function testEveryDispatchedActionIsAlsoRegistered(): void
    {
        $missing = [];
        foreach (self::areas() as [$path, $name]) {
            $src = (string) file_get_contents($path);
            $declared = self::declared($src);
            foreach (self::dispatched($src) as $verb) {
                if ($verb !== 'default' && !in_array($verb, $declared, true)) {
                    $missing[] = $name . ' handles «' . $verb . '» but does not register it';
                }
            }
        }
        self::assertSame(
            [],
            $missing,
            "an action the router refuses is not «unhandled», it is forbidden:\n" . implode("\n", $missing)
        );
    }

    /**
     * And the other way: a registered verb nothing handles.
     *
     * Harmless today — `match` throws `UnhandledMatchError` — but that is a
     * fatal on a vendor's page rather than a message, so it is worth naming.
     */
    public function testEveryRegisteredActionIsAlsoDispatched(): void
    {
        $orphans = [];
        foreach (self::areas() as [$path, $name]) {
            $src = (string) file_get_contents($path);
            $dispatched = self::dispatched($src);
            if ($dispatched === []) {
                continue;               // this area dispatches some other way
            }
            foreach (self::declared($src) as $verb) {
                if (!in_array($verb, $dispatched, true)) {
                    $orphans[] = $name . ' registers «' . $verb . '» and handles nothing for it';
                }
            }
        }
        self::assertSame([], $orphans, implode("\n", $orphans));
    }
}
