<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\Files\UploadedFile;
use Tecteb\Marketplace\Modules\Product\Application\ProductImageLibraryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ProductImagePolicy;

/**
 * Product pictures in the WordPress media library.
 *
 * The vendor does NOT get `upload_files`: granting it would let them into the
 * media library for the whole site. Instead this class does the upload on
 * their behalf, after the policy has approved the bytes, and stamps two things
 * on the attachment — its author, and a meta flag saying the marketplace put
 * it there. `ownedBy()` demands both, so an attachment that came from anywhere
 * else can never be attached to a product, whatever id is posted.
 */
final class WpProductImages implements ProductImageLibraryInterface
{
    public const OWNER_META = '_tmc_product_image';

    public function __construct(private readonly ProductImagePolicy $policy)
    {
    }

    public function store(UploadedFile $file, int $vendorUserId, string $title): array
    {
        $refusal = $this->policy->refuse($file);
        if ($refusal !== '') {
            return ['ok' => false, 'code' => $refusal, 'media_id' => 0];
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        // Two handlers, one rule. `wp_handle_upload` refuses a file that did
        // not arrive through PHP's own upload machinery, and that check is
        // exactly what a web request wants, so it stays on: only the "is this
        // a form I know" test is relaxed, because this handler IS the form.
        // A file that is NOT an upload — a CLI import, a seeding script —
        // goes through `wp_handle_sideload`, which applies the same MIME
        // allowlist and the same sanitised filename; it is the absence of an
        // HTTP upload that differs, not the strictness.
        //
        // Both take their first argument BY REFERENCE, so it must be a
        // variable: passing the method call directly raises a notice on every
        // upload and hands the function a value it cannot write back to.
        // Found by running this on real WordPress, not by reading the source.
        $incoming = $this->fileArray($file);
        $overrides = [
            'test_form' => false,
            'mimes' => [
                'jpg|jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'webp' => 'image/webp',
            ],
        ];
        $handled = is_uploaded_file($file->tempPath)
            ? wp_handle_upload($incoming, $overrides)
            : wp_handle_sideload($incoming, $overrides);
        if (!is_array($handled) || isset($handled['error']) || !isset($handled['file'])) {
            return ['ok' => false, 'code' => 'storage_failed', 'media_id' => 0];
        }

        $attachmentId = wp_insert_attachment([
            'post_mime_type' => (string) ($handled['type'] ?? $file->detectedMime),
            'post_title' => $title !== '' ? $title : $file->originalName,
            'post_content' => '',
            'post_status' => 'inherit',
            'post_author' => $vendorUserId,
        ], (string) $handled['file']);
        if (is_wp_error($attachmentId) || (int) $attachmentId <= 0) {
            @unlink((string) $handled['file']);
            return ['ok' => false, 'code' => 'storage_failed', 'media_id' => 0];
        }
        $attachmentId = (int) $attachmentId;
        wp_update_attachment_metadata($attachmentId, wp_generate_attachment_metadata($attachmentId, (string) $handled['file']));
        update_post_meta($attachmentId, self::OWNER_META, $vendorUserId);

        return ['ok' => true, 'code' => 'image_uploaded', 'media_id' => $attachmentId];
    }

    public function ownedBy(int $mediaId, int $vendorUserId): bool
    {
        if ($mediaId <= 0 || $vendorUserId <= 0) {
            return false;
        }
        $post = get_post($mediaId);
        if ($post === null || $post->post_type !== 'attachment') {
            return false;
        }
        return (int) $post->post_author === $vendorUserId
            && (int) get_post_meta($mediaId, self::OWNER_META, true) === $vendorUserId;
    }

    public function thumbnailUrl(int $mediaId): string
    {
        $src = wp_get_attachment_image_src($mediaId, 'thumbnail');
        if (is_array($src) && isset($src[0])) {
            return (string) $src[0];
        }
        $url = wp_get_attachment_url($mediaId);
        return is_string($url) ? $url : '';
    }

    /** @return array{name:string, type:string, tmp_name:string, error:int, size:int} */
    private function fileArray(UploadedFile $file): array
    {
        return [
            'name' => $file->originalName,
            'type' => $file->detectedMime,
            'tmp_name' => $file->tempPath,
            'error' => $file->errorCode,
            'size' => $file->sizeBytes,
        ];
    }
}
