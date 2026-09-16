<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

use Tecteb\Marketplace\Contracts\Files\UploadedFile;

/**
 * What may become a product picture.
 *
 * The MIME is the one the server read from the bytes; a `.jpg` that is really
 * a PHP file is refused here rather than discovered later. The list is short
 * because the media library has to be able to make thumbnails of whatever
 * gets through.
 */
final class ProductImagePolicy
{
    public const MAX_BYTES = 3145728;               // 3 MB

    /** @var list<string> */
    public const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    private readonly int $maxBytes;

    /**
     * @param int $platformMaxBytes the host's own upload ceiling, 0 when the
     *        caller does not know it. Infrastructure reads it (on WordPress,
     *        `wp_max_upload_size()`); this layer only takes the number.
     */
    public function __construct(int $platformMaxBytes = 0)
    {
        $this->maxBytes = self::effectiveMaxBytes($platformMaxBytes);
    }

    /**
     * The smallest cap that actually applies.
     *
     * `MAX_BYTES` is only the ceiling WE chose. `upload_max_filesize` and
     * `post_max_size` are ceilings we do not control and are often lower, and
     * PHP enforces them before a single line of this plugin runs. Telling a
     * vendor «حداکثر ۳ مگابایت» on a host that stops at ۲ is telling them to
     * retry the same file forever.
     */
    public static function effectiveMaxBytes(int $platformMaxBytes = 0): int
    {
        return $platformMaxBytes > 0 && $platformMaxBytes < self::MAX_BYTES
            ? $platformMaxBytes
            : self::MAX_BYTES;
    }

    /** The cap this instance enforces, for the form hint and the message. */
    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    /** @return string '' when the file is acceptable, otherwise a refusal code */
    public function refuse(UploadedFile $file): string
    {
        if ($file->errorCode === UPLOAD_ERR_INI_SIZE || $file->errorCode === UPLOAD_ERR_FORM_SIZE) {
            return 'image_too_large';
        }
        if ($file->errorCode === UPLOAD_ERR_NO_FILE) {
            return 'no_file';
        }
        if ($file->errorCode !== 0) {
            return 'transfer_failed';
        }
        if ($file->originalName === '' || $file->tempPath === '') {
            return 'no_file';
        }
        if ($file->sizeBytes <= 0) {
            return 'empty_file';
        }
        if ($file->sizeBytes > $this->maxBytes) {
            return 'image_too_large';
        }
        if (!in_array($file->detectedMime, self::ALLOWED_MIME, true)) {
            return 'image_mime_not_allowed';
        }
        return '';
    }
}
