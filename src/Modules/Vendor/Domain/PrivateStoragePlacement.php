<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Domain;

use Tecteb\Marketplace\Core\Support\FilesystemPath;

/**
 * Where private documents may be kept — decided without touching WordPress,
 * so the rule can be tested directly.
 *
 * ## Why a directory outside the web roots is the requirement
 *
 * The first build stored documents under `wp-content/uploads`, guarded by an
 * unguessable name, a `.htaccess`/`web.config` deny, and a download route
 * that checks capability and ownership. Two of those three are worth having
 * and none of them is the control that matters: nginx ignores `.htaccess`
 * entirely, `web.config` is IIS-only, and a name is only unguessable until it
 * is copied into a support ticket, a log, a backup listing or a CDN. A file
 * the web server is willing to serve is a file one misconfiguration away from
 * being served. Keeping the bytes outside every directory the server maps to
 * a URL removes the question instead of answering it.
 *
 * ## Why an unusable location refuses the upload
 *
 * The tempting fallback — "no safe directory, so keep using uploads" — turns
 * a visible failure into an invisible one: the applicant succeeds, and nobody
 * learns that a licence scan is now web-reachable. Refusing the upload with a
 * sentence the site owner can act on is the honest outcome, and the only one
 * this class can produce.
 */
final class PrivateStoragePlacement
{
    public const OK = 'ok';
    /** Every candidate sat inside a directory the web server can serve. */
    public const INSIDE_WEB_ROOT = 'inside_web_root';
    /** A candidate was outside the web roots but could not be written to. */
    public const NOT_WRITABLE = 'not_writable';
    /** Nothing to try: the host gave us no usable paths at all. */
    public const NO_CANDIDATE = 'no_candidate';

    private function __construct(
        private readonly ?string $path,
        private readonly string $reason
    ) {
    }

    /**
     * First candidate that is both outside every web root and usable wins.
     *
     * @param list<string> $candidates in order of preference
     * @param list<string> $webRoots   directories the web server may serve
     * @param callable(string):bool $usable creates/probes the directory
     */
    public static function choose(array $candidates, array $webRoots, callable $usable): self
    {
        $roots = [];
        foreach ($webRoots as $root) {
            $root = FilesystemPath::normalize($root);
            if ($root !== '' && $root !== '/') {
                $roots[] = $root;
            }
        }

        $reason = self::NO_CANDIDATE;
        foreach ($candidates as $candidate) {
            $path = FilesystemPath::normalize($candidate);
            if ($path === '' || $path === '/') {
                continue;
            }
            if (FilesystemPath::isInsideAny($path, $roots)) {
                $reason = self::INSIDE_WEB_ROOT;
                continue;
            }
            if (!$usable($path)) {
                $reason = self::NOT_WRITABLE;
                continue;
            }
            return new self($path, self::OK);
        }
        return new self(null, $reason);
    }

    public function path(): ?string
    {
        return $this->path;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function isUsable(): bool
    {
        return $this->path !== null;
    }
}
