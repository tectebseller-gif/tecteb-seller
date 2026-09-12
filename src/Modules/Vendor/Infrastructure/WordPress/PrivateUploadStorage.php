<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\Files\PrivateFileStorageInterface;
use Tecteb\Marketplace\Contracts\Files\StoredFile;
use Tecteb\Marketplace\Contracts\Files\UploadedFile;

/**
 * Private document storage (plan §7).
 *
 * Three defences, in order of how much they can be trusted:
 *  1. the file name is 32 random hex characters, so it cannot be guessed;
 *  2. `.htaccess` / `web.config` deny direct access — helpful on Apache/IIS,
 *     and IGNORED by nginx, which is why it is not the real control;
 *  3. the only read path is read(), which the download route calls AFTER
 *     checking capability and ownership. That is the control that holds
 *     everywhere.
 *
 * Nothing here returns a URL, and the media library never learns the file
 * exists, so it can never appear in a public listing.
 */
final class PrivateUploadStorage implements PrivateFileStorageInterface
{
    private const DIR = 'tmc-private';

    /** Extension chosen from the DETECTED mime, never from the sent name. */
    private const EXTENSION = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    public function __construct(private readonly ?string $baseDirOverride = null)
    {
    }

    public function store(UploadedFile $file, string $scope): StoredFile
    {
        $ext = self::EXTENSION[$file->detectedMime] ?? null;
        if ($ext === null) {
            throw new \RuntimeException('unsupported mime for private storage');
        }
        $scopeDir = $this->safeScope($scope);
        $dir = $this->baseDir() . '/' . $scopeDir;
        $this->harden($this->baseDir());
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

    public function baseDir(): string
    {
        if ($this->baseDirOverride !== null) {
            return rtrim($this->baseDirOverride, '/');
        }
        $uploads = wp_upload_dir();
        return rtrim((string) ($uploads['basedir'] ?? ''), '/') . '/' . self::DIR;
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
        $base = realpath($this->baseDir());
        $path = realpath($this->baseDir() . '/' . $relativePath);
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

    /** Writes the deny files once; cheap enough to check on every store. */
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
