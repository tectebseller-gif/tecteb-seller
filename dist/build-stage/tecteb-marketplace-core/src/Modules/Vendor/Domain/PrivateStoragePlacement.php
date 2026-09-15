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

    /**
     * Directory names that mean "the web server serves this".
     *
     * Needed because a WordPress installed INSIDE the account's public
     * directory — `public_html/staging/`, which is how the owner's staging
     * site is laid out — has a parent that is still served, by the parent
     * site, at a URL this install knows nothing about. Comparing only against
     * this site's own roots would happily choose `public_html/tecteb-private`
     * and publish every licence scan at `https://the-main-site/tecteb-private/…`.
     *
     * A name is weaker evidence than DOCUMENT_ROOT, so it is used only to
     * EXCLUDE, never to permit.
     */
    public const WEB_DIRECTORY_NAMES = ['public_html', 'public', 'www', 'wwwroot', 'htdocs', 'httpdocs', 'httpsdocs', 'web'];

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

    /**
     * Every directory that must be treated as web-served: the ones the site
     * declared, plus any ancestor of them whose NAME says so.
     *
     * @param list<string> $declared
     * @param list<string> $names
     * @return list<string>
     */
    public static function expandWebRoots(array $declared, array $names = self::WEB_DIRECTORY_NAMES): array
    {
        $roots = [];
        foreach ($declared as $root) {
            $path = FilesystemPath::normalize($root);
            if ($path === '' || $path === '/') {
                continue;
            }
            $roots[$path] = true;
            foreach (self::ancestors($path) as $ancestor) {
                if (in_array(basename($ancestor), $names, true)) {
                    $roots[$ancestor] = true;
                }
            }
        }
        return array_keys($roots);
    }

    /**
     * The first directory ABOVE everything web-served on the way up from
     * $start — where a private directory can be created without sitting
     * under somebody's document root.
     *
     * @param list<string> $webRoots already expanded
     */
    public static function firstDirectoryAboveWebRoots(string $start, array $webRoots): ?string
    {
        $path = FilesystemPath::normalize($start);
        $outermost = null;
        foreach ([$path, ...self::ancestors($path)] as $candidate) {
            if (FilesystemPath::isInsideAny($candidate, $webRoots)) {
                $outermost = $candidate;
            }
        }
        if ($outermost === null) {
            return dirname($path) === $path ? null : dirname($path);
        }
        $above = dirname($outermost);
        return ($above === $outermost || $above === '/' || $above === '.') ? null : $above;
    }

    /** @return list<string> from the immediate parent upwards, excluding '/' */
    private static function ancestors(string $path): array
    {
        $out = [];
        $current = FilesystemPath::normalize($path);
        while (true) {
            $parent = dirname($current);
            if ($parent === $current || $parent === '/' || $parent === '.') {
                break;
            }
            $out[] = $parent;
            $current = $parent;
        }
        return $out;
    }
}
