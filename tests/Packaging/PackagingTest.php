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

    /** The delivered package carries its version in the filename, so two
     *  deliveries can never be confused for one another. */
    private static function version(): string
    {
        $header = (string) file_get_contents(self::root() . '/' . self::SLUG . '.php');
        preg_match('/^\s*\*\s*Version:\s*([0-9A-Za-z.\-+]+)/m', $header, $m);
        return $m[1] ?? '';
    }

    private static function zipName(): string
    {
        return self::SLUG . '-' . self::version() . '.zip';
    }

    private static function zip(): string
    {
        return self::root() . '/dist/' . self::zipName();
    }

    public static function setUpBeforeClass(): void
    {
        if (!is_file(self::zip())) {
            throw new \RuntimeException('dist/' . self::zipName() . ' is missing — run tools/build.sh first.');
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

    /**
     * The development directories are banned WHERE THEY LIVE — at the payload
     * root — not by name at any depth.
     *
     * The looser rule cost a release: `assets/vendor/` matched the pattern
     * meant for Composer's `vendor/`, the build deleted it, and this test
     * agreed with the build, so 0.1.0-alpha.3 shipped the vendor area with no
     * stylesheet and every suite stayed green. A name is not a reason; a
     * location is. testEveryAssetInTheWorkingTreeIsPackaged is the other half.
     */
    public function testZipContainsNoForbiddenPaths(): void
    {
        $root = preg_quote(self::SLUG, '#');
        $forbidden = [
            '#(^|/)\.#' => 'dot-file or dot-directory (.git, .env, ...)',
            '#\.docx$#i' => 'reference DOCX',
            "#^{$root}/vendor/#" => 'dev vendor directory',
            "#^{$root}/node_modules/#" => 'node modules',
            "#^{$root}/tests?/#" => 'test code',
            "#^{$root}/tools/#" => 'build tooling',
            "#^{$root}/docs/#" => 'documentation',
            "#^{$root}/dist/#" => 'nested distribution',
            '#(^|/)vendor/autoload\.php$#' => 'a Composer install at any depth',
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

    /**
     * Every byte the pages ask the browser for must be in the package.
     *
     * A missing stylesheet does not fail any PHP test: the plugin activates,
     * the routes answer, the HTML is correct, and the page is unreadable.
     * Nothing short of comparing the working tree with the ZIP catches that.
     */
    public function testEveryAssetInTheWorkingTreeIsPackaged(): void
    {
        $root = dirname(__DIR__, 2) . '/assets';
        $entries = $this->entries();
        $found = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = 'assets/' . str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            self::assertContains(self::SLUG . '/' . $relative, $entries, "{$relative} is used by a page but is not in the package");
            $found++;
        }
        self::assertGreaterThanOrEqual(3, $found, 'the working tree should have at least the admin and vendor assets');
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

    /**
     * Two builds of the same source produce byte-identical packages.
     *
     * Built into a TEMPORARY directory, not over `dist/`. The source archive
     * contains `docs/`, and a full suite run rewrites the evidence logs in
     * there as it goes — so building over `dist/` left the delivered source
     * archive different from the hash the ledger had just recorded for it,
     * every single run. A check must not mutate the thing it is checking.
     */
    public function testBuildIsReproducible(): void
    {
        // Every file dist/ holds, before the throwaway build runs. The
        // assertion at the end is not decoration: `tools/reviewable-bundle.sh`
        // ignored TMC_DIST when it was added in `alpha.22`, so THIS test wrote
        // its rebuilt bundle over the delivered one while its own SHA256SUMS
        // went to the temp directory and was deleted. The archive that was
        // handed over and the checksum file that was handed over then
        // disagreed, and nothing here noticed — the docblock above said a
        // check must not mutate what it checks, and only said it.
        $distBefore = self::distFingerprint();
        $before = hash_file('sha256', self::zip());
        $dir = sys_get_temp_dir() . '/tmc-build-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);

        exec(
            'TMC_DIST=' . escapeshellarg($dir) . ' TMC_REBUILD=1 bash '
            . escapeshellarg(self::root() . '/tools/build.sh') . ' 2>&1',
            $out,
            $code
        );
        $rebuilt = $dir . '/' . self::zipName();
        $ok = $code === 0 && is_file($rebuilt);
        $again = $ok ? hash_file('sha256', $rebuilt) : '';
        exec('rm -rf ' . escapeshellarg($dir));

        self::assertTrue($ok, implode("\n", $out));
        self::assertSame($before, $again, 'two builds of the same source are byte-identical');
        self::assertSame(
            $distBefore,
            self::distFingerprint(),
            'the reproducibility build wrote into the real dist/: a check must not mutate what it checks'
        );
    }

    /**
     * Name, content hash AND mtime of everything in dist/.
     *
     * The mtime is the half that makes this bite. Content alone would pass
     * whenever the tree had not changed since the delivered build — which is
     * most runs, and was NOT the run where this went wrong: the bundle was
     * rewritten after a doc edit, so its bytes moved too. A rebuild that
     * lands identical bytes over a delivered file is still a write onto a
     * delivered file, and the only reason it looked harmless is that nothing
     * was measuring it.
     *
     * @return array<string,string>
     */
    private static function distFingerprint(): array
    {
        $out = [];
        foreach (glob(self::root() . '/dist/*') ?: [] as $path) {
            if (is_file($path)) {
                $out[basename($path)] = hash_file('sha256', $path) . '@' . filemtime($path);
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Every package the owner was actually GIVEN still has the bytes they
     * were given.
     *
     * `dist/SHA256SUMS` cannot answer this. It is regenerated from whatever
     * is on disk, so a wrong overwrite is copied into it and the file then
     * agrees with itself — which is exactly what happened: `alpha.14` was
     * rebuilt over the delivered package before the version was bumped, and
     * `SHA256SUMS` was rewritten to match. Nothing contradicted anything.
     *
     * `dist/DELIVERED.txt` is written by hand and never generated. It records
     * what was handed over, and this test is the thing that makes the record
     * binding. A name listed there may never mean different bytes again; new
     * content gets a new version number.
     */
    public function testEveryDeliveredPackageStillHasTheBytesItWasDeliveredWith(): void
    {
        $ledger = self::root() . '/dist/DELIVERED.txt';
        self::assertFileExists($ledger, 'the delivery ledger is the record; it may not go missing');

        $checked = 0;
        foreach (explode("\n", (string) file_get_contents($ledger)) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            self::assertMatchesRegularExpression(
                '/^[0-9a-f]{64}\s+\S+\s+\d{4}-\d{2}-\d{2}$/',
                $line,
                'every ledger line is «<sha256>  <file>  <date>»: ' . $line
            );
            [$hash, $name] = preg_split('/\s+/', $line);
            $path = self::root() . '/dist/' . $name;
            self::assertFileExists($path, $name . ' was delivered and is no longer in dist/');
            self::assertSame(
                $hash,
                hash_file('sha256', $path),
                $name . ' no longer has the bytes it was delivered with. A delivered name never'
                    . ' changes content — if the content must change, the version must.'
            );
            $checked++;
        }
        self::assertGreaterThan(3, $checked, 'sanity: the ledger has entries');
    }

    /**
     * Every DELIVERED package still has the bytes git has for it — asked of
     * git, which is the one witness the ledger cannot contradict.
     *
     * A delivered ZIP is quoted by hash and may already be installed
     * somewhere, so its name must never mean two different things. A rebuild
     * run before the Version header was bumped once replaced the delivered
     * `alpha.14` package and rewrote `SHA256SUMS` to agree; the file then
     * matched its own recorded hash and nothing anywhere looked wrong.
     * `DELIVERED.txt` closes that, and this closes the next one down: the
     * ledger is hand-written, so an edit to the package AND an edit to the
     * ledger in one commit would still read as consistent. Git would not.
     *
     * **Only delivered packages.** An earlier version of this froze every
     * committed package, which is a different and wrong rule: a version that
     * nobody has received is still being built, and committing a mid-phase
     * build must not burn its version number. That mistake cost `alpha.15`.
     *
     * **Ordering:** a new ledger line and the bytes it names belong in the
     * SAME commit. Adding the line first makes this fail until the commit
     * lands, which is the correct direction to fail in — the alternative is a
     * ledger that vouches for bytes git has never seen.
     */
    public function testEveryDeliveredPackageMatchesTheBytesGitHasForIt(): void
    {
        $root = self::root();
        exec('git -C ' . escapeshellarg($root) . ' rev-parse --git-dir 2>/dev/null', $o, $c);
        if ($c !== 0) {
            self::markTestSkipped('not a git checkout');
        }

        exec('git -C ' . escapeshellarg($root) . ' ls-tree --name-only HEAD dist/', $tracked, $c);
        self::assertSame(0, $c, 'could not list the committed packages');

        $delivered = self::deliveredNames();
        self::assertNotEmpty($delivered, 'sanity: the ledger names delivered packages');

        $checked = 0;
        foreach ($tracked as $path) {
            if (!preg_match('/\.(zip|tar\.gz)$/', $path)) {
                continue;
            }
            if (!in_array(basename($path), $delivered, true)) {
                continue;               // built and committed, never handed over
            }
            $onDisk = $root . '/' . $path;
            self::assertFileExists($onDisk, $path . ' was delivered and is now missing');

            exec(
                'git -C ' . escapeshellarg($root) . ' show ' . escapeshellarg('HEAD:' . $path) . ' | sha256sum',
                $out,
                $c
            );
            self::assertSame(0, $c, 'could not read ' . $path . ' from HEAD');
            $committed = substr((string) array_pop($out), 0, 64);
            $out = [];

            self::assertSame(
                $committed,
                hash_file('sha256', $onDisk),
                $path . ' differs from the version committed under that name'
            );
            $checked++;
        }

        // The archives a delivery carried are no longer committed from
        // `alpha.22` on: images and video moved to attachments, and git keeps
        // the hashes instead of the bytes. So the rule is not «every ledger
        // name is a committed file» any more — it is «every ledger name is
        // vouched for by something committed», which for those is their line
        // in `dist/SHA256SUMS`, itself in git and covered by the checks above.
        //
        // The installable ZIP is deliberately NOT allowed to take this route:
        // it stays tracked, and the byte-for-byte comparison with HEAD above
        // is what stops a rebuild from replacing a package somebody installed.
        $sums = @file_get_contents($root . '/dist/SHA256SUMS') ?: '';
        $vouched = $checked;
        foreach ($delivered as $name) {
            if (in_array('dist/' . $name, $tracked, true)) {
                continue;               // already compared against HEAD
            }
            self::assertStringEndsWith(
                '.tar.gz',
                $name,
                $name . ' is a delivered ZIP and must stay committed: a hash alone cannot stop a rebuild replacing it'
            );
            self::assertStringContainsString(
                '  ' . $name,
                $sums,
                $name . ' was delivered, is not in git, and has no line in dist/SHA256SUMS — nothing vouches for it'
            );
            $vouched++;
        }
        self::assertSame(
            count($delivered),
            $vouched,
            'every name in the ledger must be vouched for: by its committed bytes, or by a committed hash'
        );
    }

    /**
     * The file names `dist/DELIVERED.txt` records — the hand-written list of
     * what was actually handed over, as opposed to what happens to be built.
     *
     * @return list<string>
     */
    private static function deliveredNames(): array
    {
        $ledger = self::root() . '/dist/DELIVERED.txt';
        if (!is_file($ledger)) {
            return [];
        }
        $names = [];
        foreach (file($ledger, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (str_starts_with(trim($line), '#')) {
                continue;
            }
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            if (count($parts) >= 2 && preg_match('/^[0-9a-f]{64}$/', $parts[0]) === 1) {
                $names[] = $parts[1];
            }
        }
        return $names;
    }

    public function testChecksumsFileCoversBothArtefacts(): void
    {
        $sums = self::root() . '/dist/SHA256SUMS';
        self::assertFileExists($sums);
        $content = (string) file_get_contents($sums);
        self::assertStringContainsString(hash_file('sha256', self::zip()) . '  ' . self::zipName(), $content);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}\s+' . preg_quote(self::SLUG, '/') . '-source-[0-9A-Za-z.\-+]+\.tar\.gz$/m', $content);
    }

    /**
     * Every abbreviated hash a document quotes is a real hash of a real file.
     *
     * The phase-12 delivery note quoted `78d7bc79…` for the source archive and
     * it was wrong: the number was copied by hand from the output of an EARLIER
     * build, and the rebuild after it produced a different archive — the
     * evidence run in between had rewritten text files that live inside that
     * archive. Nothing caught it, because a hash in prose is just prose.
     *
     * So the prose is checked against `dist/SHA256SUMS`, which is generated.
     * A document may abbreviate («ba4af307…577b43»); it may not invent.
     */
    public function testEveryHashQuotedInTheDocsMatchesAPackageWeActuallyBuilt(): void
    {
        $sums = (string) file_get_contents(self::root() . '/dist/SHA256SUMS');
        preg_match_all('/^([0-9a-f]{64})\s+(\S+)$/m', $sums, $rows, PREG_SET_ORDER);
        $known = [];
        foreach ($rows as $row) {
            $known[] = $row[1];
        }
        self::assertNotEmpty($known, 'dist/SHA256SUMS has no entries to check against');

        // «<8 hex>…<6 hex>» — the shape every delivery note in this repo uses.
        $quoted = 0;
        $wrong = [];
        foreach (glob(self::root() . '/docs/*.md') ?: [] as $doc) {
            $text = (string) file_get_contents($doc);
            preg_match_all('/`([0-9a-f]{8})…([0-9a-f]{6,8})`/u', $text, $found, PREG_SET_ORDER);
            foreach ($found as $hit) {
                $quoted++;
                $matches = false;
                foreach ($known as $full) {
                    if (str_starts_with($full, $hit[1]) && str_ends_with($full, $hit[2])) {
                        $matches = true;
                        break;
                    }
                }
                // A hash from a package this dist/ no longer holds cannot be
                // checked — and must not fail the build for a reviewer who
                // pruned old archives. Only a hash whose PREFIX is known and
                // whose SUFFIX is not is a real contradiction.
                if (!$matches) {
                    foreach ($known as $full) {
                        if (str_starts_with($full, $hit[1])) {
                            $wrong[] = basename($doc) . ': ' . $hit[1] . '…' . $hit[2]
                                . ' but that package is ' . substr($full, 0, 8) . '…' . substr($full, -6);
                        }
                    }
                }
            }
        }
        self::assertGreaterThan(0, $quoted, 'no abbreviated hashes found — has the format changed?');
        self::assertSame([], $wrong, "a document quotes a hash that contradicts dist/SHA256SUMS:\n" . implode("\n", $wrong));
    }

    /**
     * The font ships, and its licence ships with it.
     *
     * Vazirmatn is under SIL OFL 1.1, which permits redistribution on the
     * condition that the copyright notice and the licence travel with the
     * font. A package carrying the `.woff2` and not `OFL.txt` is not a missing
     * file — it is a licence violation, which is why this is asserted rather
     * than trusted to a `cp -r`.
     *
     * The size is checked too. The variable build is ~109 KB; if a future
     * change ever swapped in the full static family, every visitor to every
     * shop page would pay for it and nothing would have said so.
     */
    public function testTheBundledFontTravelsWithItsLicence(): void
    {
        $entries = $this->entries();
        $font = self::SLUG . '/assets/fonts/vazirmatn-variable.woff2';
        $licence = self::SLUG . '/assets/fonts/Vazirmatn-OFL.txt';

        self::assertContains($font, $entries, 'the font the pages are designed in is not in the package');
        self::assertContains($licence, $entries, 'a font without its licence must not be distributed');

        $text = (string) file_get_contents(self::root() . '/assets/fonts/Vazirmatn-OFL.txt');
        self::assertStringContainsString('SIL Open Font License', $text);
        self::assertStringContainsString('Copyright', $text, 'OFL 1.1 requires the copyright notice to travel too');

        $bytes = (int) filesize(self::root() . '/assets/fonts/vazirmatn-variable.woff2');
        self::assertLessThan(200 * 1024, $bytes, 'one variable file, not a static family');
        self::assertGreaterThan(50 * 1024, $bytes, 'and a real font rather than a placeholder');
    }

    /**
     * Demo pictures and sample rows belong to the demo environment.
     *
     * The walkthrough needs products with photographs to look like a shop; a
     * plugin that shipped those photographs would be installing somebody's
     * catalogue onto their site. The seed scripts live in `tools/` — which is
     * not in the installable package at all — and this asserts that stays
     * true rather than assuming it.
     */
    public function testNoDemoContentIsInTheInstallablePackage(): void
    {
        foreach ($this->entries() as $entry) {
            self::assertStringNotContainsString('/demo/', $entry, "demo content in the package: {$entry}");
            self::assertStringNotContainsString('/fixtures/', $entry, "fixture content in the package: {$entry}");
            self::assertDoesNotMatchRegularExpression(
                '#/tools/#',
                $entry,
                "seed tooling in the package: {$entry}"
            );
            // The one picture kind a plugin legitimately ships is an icon or a
            // UI asset. A JPEG is a photograph, and a photograph here is
            // somebody's product.
            self::assertDoesNotMatchRegularExpression('#\.jpe?g$#i', $entry, "a photograph in the package: {$entry}");
        }
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
        // the listing, so bound it too — but bound the part that CAN be
        // bounded. A flat limit on the whole archive was the first attempt and
        // it aged badly: the archive is supposed to carry the acceptance
        // evidence, that evidence grows with every delivery, and by the tenth
        // the canary was firing at legitimate screenshots. Raising the number
        // each time would have made it a formality rather than a check.
        //
        // So: everything that is NOT evidence is what gets a hard ceiling,
        // because that is where a vendored node_modules or a stray build
        // output would land. Evidence is bounded by the per-file rule below
        // instead, which is the one that actually distinguishes «a page
        // screenshot» from «a toolchain».
        exec('tar -tzvf ' . escapeshellarg($archives[0]), $verbose, $vcode);
        self::assertSame(0, $vcode);
        $sizes = [];
        foreach ($verbose as $line) {
            if (preg_match('/^\S+\s+\S+\s+(\d+)\s+\S+\s+\S+\s+(.+)$/', $line, $m) === 1) {
                $sizes[$m[2]] = (int) $m[1];
            }
        }
        self::assertNotEmpty($sizes, 'the archive listing could not be read');

        $evidence = 0;
        $rest = 0;
        foreach ($sizes as $name => $size) {
            if (str_contains($name, 'docs/evidence/')) {
                $evidence += $size;
                continue;
            }
            $rest += $size;
        }
        self::assertLessThan(
            24 * 1024 * 1024,
            $rest,
            'the source archive carries ' . $rest . ' bytes that are not acceptance evidence'
        );

        // What makes it big must be evidence, not a toolchain: no single file
        // may dominate.
        $biggestName = (string) array_key_first($sizes);
        $biggest = 0;
        foreach ($sizes as $name => $size) {
            if ($size > $biggest) {
                $biggest = $size;
                $biggestName = (string) $name;
            }
        }
        self::assertLessThan(4 * 1024 * 1024, $biggest, "one file dominates the source archive: {$biggestName} ({$biggest} bytes)");
        self::assertGreaterThan($rest, $evidence, 'the source archive should be mostly acceptance evidence');
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
            // Comments are stripped first: a file that EXPLAINS it never signs
            // anyone in would otherwise be flagged for naming the function it
            // refuses to call. Strings stay, since a call built from a string
            // is exactly the kind of bypass this looks for.
            $src = self::withoutComments((string) file_get_contents($f->getPathname()));
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

    private static function withoutComments(string $php): string
    {
        $out = '';
        foreach (token_get_all($php) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
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
        // Pinning one literal version here meant editing this test on every
        // delivery, which tests nothing. What must hold is that the version is
        // still in the provisional 0.1.0-alpha line (F-01 is open) and that
        // every place that declares it agrees — a package whose header, its
        // own constant and readme.txt disagree is not deliverable.
        self::assertMatchesRegularExpression('/^\s*\*\s*Version:\s*0\.1\.0-alpha\.\d+$/m', $header);
        $version = self::version();
        self::assertStringContainsString("define( 'TMC_PLUGIN_VERSION', '{$version}' );", $header);
        self::assertStringContainsString("Stable tag: {$version}", (string) file_get_contents(self::root() . '/readme.txt'));
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
        // The owner installed the PREVIOUS version on staging themselves. The
        // notice has to say both true things: this package is installed
        // nowhere, and that staging report is the owner's evidence, not a
        // test we ran.
        self::assertStringContainsString('روی هیچ سایت واقعی نصب نشده', $text);
        self::assertStringContainsString('شاهدِ ارائه‌شده توسط مالک', $text);
        self::assertStringContainsString('هیچ آزمونی از سوی ما روی آن سایت اجرا نشده', $text);
        self::assertStringContainsString('Not Run', $text);
        self::assertStringContainsString('8.1.34', $text, 'the untested PHP of the owner site is named');
        self::assertStringContainsString('docs/evidence/acceptance/', $text, 'the notice points at the raw gate output');
    }

    /**
     * The source archive ships no credentials — not even the disposable ones.
     *
     * `.env.testing` names a throwaway MariaDB with synthetic data and it is
     * still excluded, because an archive that ships a DSN, a user and a
     * password teaches whoever reads it that this is a normal thing to find in
     * a source drop. The database suite refuses to run without those variables,
     * so a reader is told what to set rather than handed somebody else's.
     */
    public function testTheSourceArchiveCarriesNoCredentials(): void
    {
        $entries = self::sourceArchiveEntries();
        self::assertNotSame([], $entries, 'the source archive must exist and be readable');
        foreach ($entries as $entry) {
            self::assertDoesNotMatchRegularExpression(
                '#(^|/)\.env($|\.)#',
                $entry,
                'no environment file may be shipped: ' . $entry
            );
            self::assertStringNotContainsString('.pem', $entry);
        }
        // And the lockfiles a reader DOES need are there.
        self::assertContains('composer.lock', $entries);
        self::assertContains('tools/browser/package-lock.json', $entries);
    }

    /** @return list<string> */
    private static function sourceArchiveEntries(): array
    {
        $matches = glob(dirname(__DIR__, 2) . '/dist/*-source-*.tar.gz');
        if ($matches === false || $matches === []) {
            return [];
        }
        usort($matches, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $out = [];
        $archive = new \PharData($matches[0]);
        foreach (new \RecursiveIteratorIterator($archive) as $file) {
            $out[] = ltrim(str_replace('phar://' . $matches[0], '', $file->getPathname()), '/');
        }
        return $out;
    }
}
