<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Packaging;

use PHPUnit\Framework\TestCase;

/**
 * CORE-10 packaging gates, executed against the artefacts tools/build.sh
 * actually produced. Fails loudly when dist/ is missing rather than skipping,
 * so "packaging verified" can never be claimed without a build.
 */
final class PackagingTest extends TestCase
{
    private const SLUG = 'tecteb-marketplace-core';

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function zip(): string
    {
        return self::root() . '/dist/' . self::SLUG . '.zip';
    }

    public static function setUpBeforeClass(): void
    {
        if (!is_file(self::zip())) {
            throw new \RuntimeException('dist/' . self::SLUG . '.zip is missing — run tools/build.sh first.');
        }
    }

    /** @return list<string> paths inside the archive */
    private function entries(): array
    {
        exec('unzip -Z1 ' . escapeshellarg(self::zip()), $out, $code);
        self::assertSame(0, $code, 'unzip -Z1 failed');
        return array_values(array_filter(array_map('trim', $out), static fn ($l) => $l !== ''));
    }

    public function testZipHasExactlyOneTopLevelDirectoryWithTheMainFile(): void
    {
        $entries = $this->entries();
        self::assertNotEmpty($entries);
        $tops = [];
        foreach ($entries as $e) {
            $tops[explode('/', $e)[0]] = true;
        }
        self::assertSame([self::SLUG], array_keys($tops), 'exactly one top-level directory');
        self::assertContains(self::SLUG . '/' . self::SLUG . '.php', $entries, 'main file sits inside that directory');
        self::assertContains(self::SLUG . '/uninstall.php', $entries);
        self::assertContains(self::SLUG . '/assets/admin/tmc-admin.css', $entries);
        self::assertContains(self::SLUG . '/src/Core/Autoloader.php', $entries);
    }

    public function testZipContainsNoForbiddenPaths(): void
    {
        $forbidden = [
            '#(^|/)\.#' => 'dot-file or dot-directory (.git, .env, ...)',
            '#\.docx$#i' => 'reference DOCX',
            '#(^|/)vendor/#' => 'dev vendor directory',
            '#(^|/)node_modules/#' => 'node modules',
            '#(^|/)tests?/#' => 'test code',
            '#(^|/)tools/#' => 'build tooling',
            '#(^|/)docs/#' => 'documentation',
            '#(^|/)dist/#' => 'nested distribution',
            '#composer\.(json|lock)$#' => 'composer manifest',
            '#package(-lock)?\.json$#' => 'npm manifest',
            '#phpunit#i' => 'phpunit config',
            '#(Fake|Stub|Mock)[A-Za-z]*\.php$#' => 'test double',
            '#\.log$#' => 'log file',
            '#(backup|\.bak|\.sql)$#i' => 'backup or dump',
        ];
        foreach ($this->entries() as $entry) {
            foreach ($forbidden as $pattern => $why) {
                self::assertDoesNotMatchRegularExpression($pattern, $entry, "{$entry} must not ship ({$why})");
            }
        }
    }

    public function testZipIsIntactAndEveryShippedClassLoadsFromItAlone(): void
    {
        exec('unzip -tqq ' . escapeshellarg(self::zip()) . ' 2>&1', $out, $code);
        self::assertSame(0, $code, 'unzip -t reported corruption: ' . implode("\n", $out));

        $dir = sys_get_temp_dir() . '/tmc-pkg-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o755, true);
        exec('unzip -qq ' . escapeshellarg(self::zip()) . ' -d ' . escapeshellarg($dir), $o2, $c2);
        self::assertSame(0, $c2);
        $base = $dir . '/' . self::SLUG;

        // Every shipped PHP file parses, and every class resolves through the
        // shipped autoloader alone — i.e. no file the ZIP forgot to include.
        $script = <<<'PHP'
        <?php
        $base = $argv[1];
        require $base . '/src/Core/Autoloader.php';
        Tecteb\Marketplace\Core\Autoloader::register($base . '/src');
        $missing = [];
        $count = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base . '/src'));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') continue;
            if (str_contains(str_replace('\\', '/', $f->getPathname()), '/Presentation/Views/')) continue;
            $rel = substr($f->getPathname(), strlen($base . '/src/'), -4);
            $fqcn = 'Tecteb\Marketplace\\' . str_replace('/', '\\', $rel);
            $count++;
            if (!class_exists($fqcn, true) && !interface_exists($fqcn, true) && !enum_exists($fqcn, true) && !trait_exists($fqcn, true)) {
                $missing[] = $fqcn;
            }
        }
        echo json_encode(['count' => $count, 'missing' => $missing]);
        PHP;
        $runner = $dir . '/runner.php';
        file_put_contents($runner, $script);
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' ' . escapeshellarg($base), $o3, $c3);
        self::assertSame(0, $c3, implode("\n", $o3));
        $result = json_decode(implode('', $o3), true);
        self::assertGreaterThan(80, $result['count'], 'sanity: classes were found');
        self::assertSame([], $result['missing'], 'every shipped class loads from the ZIP alone');

        exec('rm -rf ' . escapeshellarg($dir));
    }

    public function testBuildIsReproducible(): void
    {
        $before = hash_file('sha256', self::zip());
        exec('bash ' . escapeshellarg(self::root() . '/tools/build.sh') . ' 2>&1', $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
        self::assertSame($before, hash_file('sha256', self::zip()), 'two builds of the same source are byte-identical');
    }

    public function testChecksumsFileCoversBothArtefacts(): void
    {
        $sums = self::root() . '/dist/SHA256SUMS';
        self::assertFileExists($sums);
        $content = (string) file_get_contents($sums);
        self::assertStringContainsString(hash_file('sha256', self::zip()) . '  ' . self::SLUG . '.zip', $content);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}\s+' . preg_quote(self::SLUG, '/') . '-source-[0-9A-Za-z.\-+]+\.tar\.gz$/m', $content);
    }

    public function testSourceArchiveCarriesTestsDocsAndLockfile(): void
    {
        $archives = glob(self::root() . '/dist/' . self::SLUG . '-source-*.tar.gz');
        self::assertNotEmpty($archives, 'source archive missing');
        exec('tar -tzf ' . escapeshellarg($archives[0]), $out, $code);
        self::assertSame(0, $code);
        $listing = implode("\n", $out);
        foreach (['tests/', 'docs/', 'tools/build.sh', 'composer.lock', 'phpunit.xml.dist'] as $needle) {
            self::assertStringContainsString($needle, $listing, "source archive must carry {$needle}");
        }
    }

    /**
     * The source archive must carry source, not a vendored toolchain. An
     * earlier build fell back to a bare `tar .` whenever its file list was
     * unusable, which silently swept tools/browser/node_modules in and more
     * than doubled the archive. The build no longer has that fallback; this
     * asserts the result.
     */
    public function testSourceArchiveCarriesNoVendoredDependencies(): void
    {
        $archives = glob(self::root() . '/dist/' . self::SLUG . '-source-*.tar.gz');
        self::assertNotEmpty($archives);
        exec('tar -tzf ' . escapeshellarg($archives[0]), $out, $code);
        self::assertSame(0, $code);

        $forbidden = [
            '#(^|/)node_modules/#' => 'npm packages',
            '#^\./?vendor/#' => 'composer packages',
            '#^\./?dist/#' => 'built artefacts, including a copy of this archive',
            '#(^|/)\.git/#' => 'repository internals',
        ];
        foreach ($out as $entry) {
            foreach ($forbidden as $pattern => $why) {
                self::assertDoesNotMatchRegularExpression($pattern, $entry, "source archive must not carry {$why}: {$entry}");
            }
        }
        // A toolchain slipping in shows up as size long before anyone reads
        // the listing, so bound it too. The bound is a canary, not the rule —
        // the path assertions above are — and it has to leave room for what
        // the archive is SUPPOSED to carry: the acceptance evidence, including
        // full-page screenshots of real wp-admin. Raised from 6 MB when that
        // evidence was added; a vendored node_modules is an order of magnitude
        // larger than the gap.
        $bytes = filesize($archives[0]);
        self::assertLessThan(24 * 1024 * 1024, $bytes, 'source archive is unexpectedly large: ' . $bytes . ' bytes');

        // What actually makes it big must be evidence, not a toolchain: no
        // single file may dominate the archive.
        exec('tar -tzvf ' . escapeshellarg($archives[0]), $verbose, $vcode);
        self::assertSame(0, $vcode);
        $biggest = 0;
        $biggestName = '';
        foreach ($verbose as $line) {
            if (preg_match('/^\S+\s+\S+\s+(\d+)\s+\S+\s+\S+\s+(.+)$/', $line, $m) !== 1) {
                continue;
            }
            if ((int) $m[1] > $biggest) {
                $biggest = (int) $m[1];
                $biggestName = $m[2];
            }
        }
        self::assertLessThan(4 * 1024 * 1024, $biggest, "one file dominates the source archive: {$biggestName} ({$biggest} bytes)");
    }

    public function testUninstallFileDeletesNothing(): void
    {
        $src = (string) file_get_contents(self::root() . '/uninstall.php');
        self::assertStringContainsString("if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) )", $src, 'guard present');
        foreach (['delete_option', 'delete_metadata', 'DROP TABLE', 'DROP `', 'TRUNCATE', 'remove_cap', 'remove_role', '$wpdb', 'delete_user_meta', 'wp_delete'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $src, "uninstall.php must not contain {$forbidden}");
        }
    }

    public function testNoLoginBypassOrHardcodedCodeInShippedSource(): void
    {
        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/src'));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($f->getPathname());
            foreach ([
                '/wp_set_auth_cookie/' => 'sets an auth cookie',
                '/wp_set_current_user/' => 'switches the current user',
                '/wp_signon/' => 'logs a user in',
                '/\b(?:eval|assert)\s*\(/' => 'dynamic code execution',
                '/base64_decode\s*\(/' => 'obfuscation',
                '/\b(?:curl_exec|file_get_contents\s*\(\s*[\'"]https?:|wp_remote_(?:get|post|request))/' => 'outbound network call',
                '/[\'"]\d{4,8}[\'"]\s*===?\s*\$/' => 'hardcoded numeric code comparison',
            ] as $pattern => $why) {
                if (preg_match($pattern, $src) === 1) {
                    $offenders[] = str_replace(self::root() . '/', '', $f->getPathname()) . ': ' . $why;
                }
            }
        }
        self::assertSame([], $offenders);
    }

    public function testShippedSourceContainsNoTestDoubles(): void
    {
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/src'));
        foreach ($it as $f) {
            if ($f->isFile() && preg_match('/(Fake|Stub|Mock|Dummy)/i', $f->getFilename()) === 1) {
                $found[] = $f->getFilename();
            }
        }
        self::assertSame([], $found);
    }

    public function testMainFileHeaderDeclaresTheAgreedIdentity(): void
    {
        $header = (string) file_get_contents(self::root() . '/' . self::SLUG . '.php');
        self::assertMatchesRegularExpression('/^\s*\*\s*Plugin Name:\s*Tecteb Marketplace Core$/m', $header);
        self::assertMatchesRegularExpression('/^\s*\*\s*Text Domain:\s*tecteb-marketplace-core$/m', $header);
        self::assertMatchesRegularExpression('/^\s*\*\s*Requires PHP:\s*8\.1$/m', $header);
        self::assertMatchesRegularExpression('/^\s*\*\s*Version:\s*0\.1\.0-alpha\.1$/m', $header);
        // The header must keep saying where this package has and has NOT been
        // installed. It said "تأییدنشده" while no install gate had ever run;
        // after the acceptance run it says the gates passed on a disposable
        // site and that the owner's site is still untouched. What must never
        // disappear is the second half.
        self::assertStringContainsString('نصب نشده', $header, 'the header still says the plugin is not installed on the real site');
        self::assertStringContainsString('TODO(F-01)', $header, 'the version is marked provisional pending the lineage decision');
    }

    /**
     * The notice beside the package must state the status HONESTLY, which is
     * not the same thing as stating it pessimistically. Two claims have to
     * survive every rewrite: the owner's site has not been installed on, and
     * the gates that were NOT run are named rather than glossed over.
     */
    public function testDistNoticeStatesTheRealStatusAndWhatIsStillNotRun(): void
    {
        $notice = self::root() . '/dist/READ-ME-BEFORE-INSTALL.txt';
        self::assertFileExists($notice);
        $text = (string) file_get_contents($notice);
        self::assertStringContainsString('نصب روی سایت تک‌طب هنوز انجام نشده', $text);
        self::assertStringContainsString('Not Run', $text);
        self::assertStringContainsString('8.1.34', $text, 'the untested PHP of the owner site is named');
        self::assertStringContainsString('docs/evidence/acceptance/', $text, 'the notice points at the raw gate output');
    }
}
