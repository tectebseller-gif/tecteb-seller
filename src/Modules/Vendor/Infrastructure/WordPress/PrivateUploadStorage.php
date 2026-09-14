<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\Files\PrivateFileStorageInterface;
use Tecteb\Marketplace\Contracts\Files\PrivateStorageUnavailable;
use Tecteb\Marketplace\Contracts\Files\StoredFile;
use Tecteb\Marketplace\Contracts\Files\UploadedFile;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Vendor\Domain\PrivateStoragePlacement;

/**
 * Private document storage (plan §7).
 *
 * The bytes live OUTSIDE every directory the web server maps to a URL, and
 * that is the control. The rest — 32 random hex characters for a name, the
 * `.htaccess`/`web.config` deny files, and a download route that checks nonce,
 * capability and ownership before the first byte — is defence in depth on top
 * of it, not a substitute for it: nginx never reads `.htaccess`, and a name
 * stops being unguessable the moment it appears in a log or a backup listing.
 * PrivateStoragePlacement explains the reasoning in full.
 *
 * Where it looks, in order:
 *   1. `TMC_PRIVATE_UPLOADS_DIR`, when the site defines it in wp-config.php
 *   2. a sibling of the WordPress directory
 *   3. a sibling of the document root
 * and if none of those is both outside the web roots and writable, storing
 * throws. Nothing falls back to `uploads/`.
 *
 * Nothing here returns a URL, and the media library never learns the file
 * exists, so it cannot appear in a public listing.
 */
final class PrivateUploadStorage implements PrivateFileStorageInterface
{
    public const DIR = 'tecteb-private';

    /** Where the first build put documents; still read for relocation. */
    public const LEGACY_DIR = 'tmc-private';

    /** Extension chosen from the DETECTED mime, never from the sent name. */
    private const EXTENSION = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    private ?PrivateStoragePlacement $placement = null;

    public function __construct(private readonly ?string $baseDirOverride = null)
    {
    }

    public function store(UploadedFile $file, string $scope): StoredFile
    {
        $ext = self::EXTENSION[$file->detectedMime] ?? null;
        if ($ext === null) {
            throw new \RuntimeException('unsupported mime for private storage');
        }
        $base = $this->baseDir();
        if ($base === null) {
            throw new PrivateStorageUnavailable($this->placement()->reason());
        }
        $scopeDir = $this->safeScope($scope);
        $dir = $base . '/' . $scopeDir;
        $this->harden($base);
        if (!is_dir($dir) && !mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create private directory');
        }
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $target = $dir . '/' . $name;

        $moved = is_uploaded_file($file->tempPath)
            ? move_uploaded_file($file->tempPath, $target)
            : rename($file->tempPath, $target);
        if (!$moved) {
            throw new \RuntimeException('cannot move uploaded file');
        }
        @chmod($target, 0o600);

        return new StoredFile($scopeDir . '/' . $name, (int) filesize($target), $file->detectedMime);
    }

    public function read(string $relativePath): ?string
    {
        $path = $this->resolve($relativePath);
        if ($path === null || !is_readable($path)) {
            return null;
        }
        $bytes = file_get_contents($path);
        return $bytes === false ? null : $bytes;
    }

    public function exists(string $relativePath): bool
    {
        return $this->resolve($relativePath) !== null;
    }

    public function delete(string $relativePath): bool
    {
        $path = $this->resolve($relativePath);
        return $path !== null && @unlink($path);
    }

    /** Null when nothing may be stored; the reason is on placement(). */
    public function baseDir(): ?string
    {
        return $this->placement()->path();
    }

    public function unavailableReason(): ?string
    {
        $placement = $this->placement();
        return $placement->isUsable() ? null : $placement->reason();
    }

    public function placement(): PrivateStoragePlacement
    {
        return $this->placement ??= $this->locate();
    }

    /** The old in-uploads directory, whether or not anything is left in it. */
    public static function legacyBaseDir(): string
    {
        $uploads = wp_upload_dir();
        return rtrim((string) ($uploads['basedir'] ?? ''), '/') . '/' . self::LEGACY_DIR;
    }

    /**
     * An explicit override still faces the web-root test. A development
     * setting that could quietly re-create the very exposure this class
     * exists to remove would be worse than no override at all.
     */
    private function locate(): PrivateStoragePlacement
    {
        $candidates = $this->baseDirOverride !== null
            ? [rtrim($this->baseDirOverride, '/')]
            : $this->candidates();
        return PrivateStoragePlacement::choose($candidates, $this->webRoots(), $this->probe());
    }

    /** @return list<string> */
    private function candidates(): array
    {
        $candidates = [];
        if (defined('TMC_PRIVATE_UPLOADS_DIR') && is_string(constant('TMC_PRIVATE_UPLOADS_DIR'))) {
            $candidates[] = (string) constant('TMC_PRIVATE_UPLOADS_DIR');
        }
        // Above EVERYTHING web-served, not merely above WordPress. On a site
        // installed at public_html/staging/ the parent of ABSPATH is still
        // inside public_html, and the parent site would serve it.
        $roots = $this->webRoots();
        foreach ([defined('ABSPATH') ? (string) constant('ABSPATH') : '', Request::documentRoot()] as $start) {
            if ($start === '') {
                continue;
            }
            $above = PrivateStoragePlacement::firstDirectoryAboveWebRoots($start, $roots);
            if ($above !== null) {
                $candidates[] = $above . '/' . self::DIR;
            }
        }
        return array_values(array_unique($candidates));
    }

    /** Every directory this server may hand out over HTTP. @return list<string> */
    private function webRoots(): array
    {
        $roots = [];
        if (defined('ABSPATH')) {
            $roots[] = (string) constant('ABSPATH');
        }
        if (defined('WP_CONTENT_DIR')) {
            $roots[] = (string) constant('WP_CONTENT_DIR');
        }
        $uploads = wp_upload_dir();
        if (is_array($uploads) && ($uploads['basedir'] ?? '') !== '') {
            $roots[] = (string) $uploads['basedir'];
        }
        $docRoot = Request::documentRoot();
        if ($docRoot !== '') {
            $roots[] = $docRoot;
        }
        // A `public_html` above this install is somebody else's document root.
        return PrivateStoragePlacement::expandWebRoots(array_values(array_unique($roots)));
    }

    /**
     * Creating the directory IS the probe: is_writable() on a path that does
     * not exist yet answers a different question, and a parent that looks
     * writable can still refuse (open_basedir, a read-only mount, a quota).
     */
    private function probe(): callable
    {
        return static function (string $path): bool {
            if (!is_dir($path) && !mkdir($path, 0o700, true) && !is_dir($path)) {
                return false;
            }
            return is_writable($path);
        };
    }

    /**
     * Turns a stored path back into a real one, refusing anything that tries
     * to leave the directory. `realpath` is compared against the base, so a
     * symlink or a `..` that survives the pattern still cannot escape.
     */
    private function resolve(string $relativePath): ?string
    {
        if (preg_match('#^[a-z0-9\-]{1,64}/[a-f0-9]{32}\.[a-z0-9]{1,5}$#', $relativePath) !== 1) {
            return null;
        }
        $baseDir = $this->baseDir();
        if ($baseDir === null) {
            return null;
        }
        $base = realpath($baseDir);
        $path = realpath($baseDir . '/' . $relativePath);
        if ($base === false || $path === false || !str_starts_with($path, $base . '/')) {
            return null;
        }
        return $path;
    }

    private function safeScope(string $scope): string
    {
        $scope = strtolower(preg_replace('/[^A-Za-z0-9\-]/', '-', $scope) ?? '');
        return substr(trim($scope, '-'), 0, 64) ?: 'misc';
    }

    /**
     * Deny files and an index, written once. Outside the web root they should
     * never be consulted — which is exactly why they are cheap insurance if a
     * host ever maps this directory by accident.
     */
    private function harden(string $base): void
    {
        if (!is_dir($base) && !mkdir($base, 0o700, true) && !is_dir($base)) {
            throw new \RuntimeException('cannot create private base directory');
        }
        $files = [
            '.htaccess' => "# Tecteb Marketplace Core — private vendor documents.\n"
                . "# Apache 2.4\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "# Apache 2.2\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n",
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization>"
                . "<deny users=\"*\" /></authorization></system.webServer></configuration>\n",
            'index.php' => "<?php\n// Silence is golden.\n",
        ];
        foreach ($files as $name => $contents) {
            $path = $base . '/' . $name;
            if (!file_exists($path)) {
                file_put_contents($path, $contents);
                @chmod($path, 0o600);
            }
        }
    }
}
