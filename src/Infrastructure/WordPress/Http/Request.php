<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Infrastructure\WordPress\Http;

use Tecteb\Marketplace\Contracts\Files\UploadedFile;

/**
 * The ONE place in shipped code that touches a superglobal.
 *
 * Phase 1 needed no such place: its only input arrived through the Settings
 * API, which WordPress hands over already nonce- and capability-checked. The
 * vendor area has its own front controller and its own forms, so raw input
 * exists — and the rule that kept it honest ("no superglobals anywhere")
 * becomes "superglobals in exactly one audited file, which sanitises on the
 * way out". tests/Architecture/SecurityRulesTest enforces precisely that.
 *
 * Every accessor returns a sanitised value of a known type. Nothing here
 * decides permission: callers still check the nonce and the capability.
 */
final class Request
{
    private function __construct(
        private readonly array $get,
        private readonly array $post,
        private readonly array $files,
        private readonly string $method
    ) {
    }

    public static function capture(): self
    {
        return new self(
            $_GET ?? [],
            $_POST ?? [],
            $_FILES ?? [],
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))
        );
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function hasQuery(string $key): bool
    {
        return isset($this->get[$key]);
    }

    public function queryKey(string $key): string
    {
        return isset($this->get[$key]) ? sanitize_key((string) $this->get[$key]) : '';
    }

    public function queryText(string $key): string
    {
        return isset($this->get[$key]) ? sanitize_text_field(wp_unslash((string) $this->get[$key])) : '';
    }

    public function queryInt(string $key): int
    {
        return isset($this->get[$key]) ? (int) $this->get[$key] : 0;
    }

    public function hasPost(string $key): bool
    {
        return isset($this->post[$key]);
    }

    public function postKey(string $key): string
    {
        return isset($this->post[$key]) ? sanitize_key((string) $this->post[$key]) : '';
    }

    public function postText(string $key): string
    {
        return isset($this->post[$key]) ? sanitize_text_field(wp_unslash((string) $this->post[$key])) : '';
    }

    public function postEmail(string $key): string
    {
        return sanitize_email($this->postText($key));
    }

    public function postTextarea(string $key): string
    {
        return isset($this->post[$key]) ? sanitize_textarea_field(wp_unslash((string) $this->post[$key])) : '';
    }

    public function postInt(string $key): int
    {
        return isset($this->post[$key]) ? (int) $this->post[$key] : 0;
    }

    public function postChecked(string $key): bool
    {
        return isset($this->post[$key]);
    }

    /** @return list<string> */
    public function postTextList(string $key): array
    {
        $raw = $this->post[$key] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        return array_values(array_map(static fn ($v): string => sanitize_text_field(wp_unslash((string) $v)), $raw));
    }

    /**
     * One uploaded file, with the MIME read from the BYTES. The browser's
     * Content-Type and the file name are attacker-controlled and never
     * decide anything.
     */
    public function file(string $key): UploadedFile
    {
        $file = $this->files[$key] ?? null;
        if (!is_array($file) || !isset($file['tmp_name'])) {
            return new UploadedFile('', '', 0, '', UPLOAD_ERR_NO_FILE);
        }
        $tmp = (string) $file['tmp_name'];
        $mime = '';
        if ($tmp !== '' && is_readable($tmp) && function_exists('finfo_open')) {
            // No finfo_close(): it is deprecated from PHP 8.5 and has been
            // unnecessary since 8.1, where finfo_open() returns an object
            // that is released with the variable.
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = (string) finfo_file($finfo, $tmp);
            }
        }
        return new UploadedFile(
            sanitize_file_name((string) ($file['name'] ?? '')),
            $tmp,
            (int) ($file['size'] ?? 0),
            $mime,
            (int) ($file['error'] ?? UPLOAD_ERR_OK)
        );
    }

    /** Verifies a nonce that arrived in a POST field. */
    public function nonceOk(string $field, string $action): bool
    {
        return $this->hasPost($field) && (bool) wp_verify_nonce($this->postText($field), $action);
    }

    /** Verifies the `_wpnonce` query argument of a GET link. */
    public function queryNonceOk(string $action): bool
    {
        return (bool) wp_verify_nonce($this->queryText('_wpnonce'), $action);
    }
}
