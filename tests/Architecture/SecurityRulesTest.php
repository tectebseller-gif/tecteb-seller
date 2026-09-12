<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Static security rules over the SHIPPED source (CORE-03/04/06/08, SEC-01/02).
 * These are cheap invariants that catch a regression the moment it is written,
 * rather than relying on someone re-running a grep by hand.
 */
final class SecurityRulesTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return list<string> */
    private static function shippedPhpFiles(): array
    {
        $files = [self::root() . '/tecteb-marketplace-core.php', self::root() . '/uninstall.php'];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    private static function rel(string $path): string
    {
        return str_replace(self::root() . '/', '', $path);
    }

    /**
     * Exactly one file may read a superglobal, and it is the one whose whole
     * job is to sanitise them.
     *
     * Phase 1 needed none: its only input came through the Settings API,
     * which WordPress hands over already nonce- and capability-checked. The
     * vendor area has its own front controller and its own forms, so raw
     * input exists. Rather than weaken the rule to "be careful", it is
     * narrowed to one auditable file — if a second one appears, this fails.
     */
    private const REQUEST_READER = 'src/Infrastructure/WordPress/Http/Request.php';

    public function testOnlyTheRequestReaderTouchesSuperglobals(): void
    {
        $offenders = [];
        foreach (self::shippedPhpFiles() as $file) {
            if (self::rel($file) === self::REQUEST_READER) {
                continue;
            }
            if (preg_match_all('/\$_(POST|GET|REQUEST|COOKIE|FILES|SERVER)\b/', (string) file_get_contents($file), $m) > 0) {
                $offenders[] = self::rel($file) . ': ' . implode(', ', array_unique($m[0]));
            }
        }
        self::assertSame([], $offenders);
    }

    /** The exception only holds while that file really is the sanitiser. */
    public function testTheRequestReaderSanitisesEverythingItReturns(): void
    {
        $path = self::root() . '/' . self::REQUEST_READER;
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);
        foreach (['sanitize_key', 'sanitize_text_field', 'sanitize_textarea_field', 'sanitize_email', 'sanitize_file_name', 'wp_unslash', 'wp_verify_nonce'] as $needle) {
            self::assertStringContainsString($needle, $src, "the request reader must use {$needle}");
        }
        // A reader that decided permissions would be doing two jobs; nonce
        // helpers are fine, capability checks belong to the caller.
        self::assertStringNotContainsString('current_user_can', $src);
    }

    /**
     * Removes every string literal and every call to an escaping helper
     * (with balanced parentheses) from an expression. Whatever variable is
     * left was echoed without escaping.
     */
    private static function stripSafeParts(string $expr): string
    {
        $expr = preg_replace("/'(?:\\\\.|[^'\\\\])*'/s", "''", $expr) ?? $expr;
        $safeCall = '/(?:esc_(?:html|attr|url|textarea)(?:__)?|__|Components::\w+|Messages::\w+)\s*\(/';
        while (preg_match($safeCall, $expr, $m, PREG_OFFSET_CAPTURE) === 1) {
            $start = (int) $m[0][1];
            $open = $start + strlen($m[0][0]) - 1;
            $depth = 0;
            $close = null;
            for ($i = $open, $len = strlen($expr); $i < $len; $i++) {
                if ($expr[$i] === '(') {
                    $depth++;
                } elseif ($expr[$i] === ')') {
                    if (--$depth === 0) {
                        $close = $i;
                        break;
                    }
                }
            }
            if ($close === null) {
                break;
            }
            $expr = substr($expr, 0, $start) . 'SAFE' . substr($expr, $close + 1);
        }
        return $expr;
    }

    public function testEveryEchoInAViewIsEscapedOrAPreEscapedComponent(): void
    {
        $offenders = [];
        $checked = 0;
        foreach (glob(self::root() . '/src/Modules/Admin/Presentation/Views/*.php') ?: [] as $view) {
            $src = (string) file_get_contents($view);
            self::assertStringNotContainsString('<?=', $src, self::rel($view) . ' must not use short echo tags');
            preg_match_all('/\becho\s+(.+?);/s', $src, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[1] as [$expr, $offset]) {
                $checked++;
                $line = substr_count(substr($src, 0, (int) $offset), "\n") + 1;
                $residue = self::stripSafeParts(trim(preg_replace('/\s+/', ' ', $expr) ?? ''));
                // $badge only ever holds Components::badge() output, built a few
                // lines above each use; $invalid() returns a literal class name.
                $residue = str_replace(['$badge', '$invalid'], 'SAFE', $residue);
                if (str_contains($residue, '$')) {
                    $offenders[] = self::rel($view) . ":{$line}: " . trim(preg_replace('/\s+/', ' ', $expr) ?? '');
                }
            }
        }
        self::assertGreaterThan(15, $checked, 'sanity: echo statements were actually found');
        self::assertSame([], $offenders, "unescaped output in a view:\n" . implode("\n", $offenders));
    }

    public function testEveryDatabaseCallUsesPreparedStatements(): void
    {
        $offenders = [];
        foreach (self::shippedPhpFiles() as $file) {
            $src = (string) file_get_contents($file);
            // A query/get_* call whose SQL literal interpolates anything other
            // than a wpdb-owned identifier ($wpdb->options / ->prefix / $table).
            if (preg_match_all('/->(query|get_var|get_row|get_results)\s*\(\s*"([^"]*)"/s', $src, $m, PREG_SET_ORDER) > 0) {
                foreach ($m as $hit) {
                    if (preg_match('/\$(?!\{?this->wpdb->(options|prefix)\b)/', $hit[2]) === 1) {
                        $offenders[] = self::rel($file) . ': ' . $hit[1] . '() with interpolated SQL';
                    }
                }
            }
        }
        self::assertSame([], $offenders);

        // Every statement builder in WpLockStore goes through prepare(), and
        // the only thing any SQL literal interpolates is the wpdb-owned table
        // name (never a value).
        $lock = (string) file_get_contents(self::root() . '/src/Infrastructure/WordPress/WpLockStore.php');
        self::assertSame(6, preg_match_all('/\$this->wpdb->prepare\(/', $lock), 'insert, read, CAS, CAD and the two guarded writes each prepare');
        preg_match_all('/"([^"]*)"/', $lock, $literals);
        $sqlLiterals = 0;
        foreach ($literals[1] as $literal) {
            if (preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b/i', $literal) !== 1) {
                continue;
            }
            $sqlLiterals++;
            $withoutTable = str_replace('{$this->wpdb->options}', 'TABLE', $literal);
            self::assertStringNotContainsString('$', $withoutTable, 'SQL interpolates a value: ' . $literal);
            self::assertMatchesRegularExpression('/%s|option_name|option_value/', $literal);
        }
        self::assertSame(6, $sqlLiterals, 'six SQL statements in the lock store: four lock primitives + two guarded writes');
    }

    public function testEveryAdminPageAndRestRouteIsCapabilityGated(): void
    {
        $pages = array_values(array_filter(
            glob(self::root() . '/src/Modules/Admin/Presentation/Pages/*Page.php') ?: [],
            static fn (string $p): bool => basename($p) !== 'AbstractPage.php'
        ));
        self::assertCount(4, $pages, 'exactly four concrete pages in phase 1');
        foreach ($pages as $page) {
            $src = (string) file_get_contents($page);
            self::assertMatchesRegularExpression(
                '/public const CAPABILITY = Capabilities::[A-Z_]+;/',
                $src,
                self::rel($page) . ' must declare its capability'
            );
        }
        // The shared gate lives in the base class and dies with a neutral 403.
        $base = (string) file_get_contents(self::root() . '/src/Modules/Admin/Presentation/Pages/AbstractPage.php');
        self::assertStringContainsString('if (!current_user_can(static::CAPABILITY))', $base);
        self::assertStringContainsString("'response' => 403", $base);

        $rest = (string) file_get_contents(self::root() . '/src/Modules/Health/Infrastructure/Rest/HealthController.php');
        self::assertStringContainsString("'permission_callback' => [\$this, 'permission']", $rest);
        self::assertStringContainsString('Capabilities::VIEW_HEALTH', $rest);
        self::assertStringContainsString('no-store', $rest);
    }

    public function testAuditNeverPersistsAnythingOutsideTheAllowlist(): void
    {
        $logger = (string) file_get_contents(self::root() . '/src/Core/Audit/AuditLogger.php');
        self::assertStringContainsString('$this->sanitizer->sanitize(', $logger, 'every write goes through the sanitiser');
        self::assertStringNotContainsString('new AuditRecord($eventType, $actorId, $objectType, $objectId, $payload', $logger, 'the raw payload never reaches the record');

        $record = (string) file_get_contents(self::root() . '/src/Contracts/AuditRecord.php');
        self::assertStringContainsString('go through Core\Audit\AuditLogger', $record, 'the contract documents the only construction path');
    }

    public function testOutboundLockCannotBeOpenedByConfiguration(): void
    {
        $policy = (string) file_get_contents(self::root() . '/src/Core/Environment/OutboundPolicy.php');
        // The allow-check must ignore its argument entirely and return false.
        self::assertMatchesRegularExpression(
            '/isTmcOutboundAllowed\(ResolvedEnvironment \$environment\): bool\s*\{\s*return false;\s*\}/',
            $policy,
            'no branch may return true'
        );
        self::assertStringContainsString('throw new OutboundBlockedException', $policy);
        self::assertSame("'blocked'", trim(explode(';', explode('const TMC =', $policy)[1])[0]));
        self::assertSame("'unknown'", trim(explode(';', explode('const OTHER_PLUGINS =', $policy)[1])[0]));
    }
}
