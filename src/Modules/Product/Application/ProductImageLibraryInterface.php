<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Contracts\Files\UploadedFile;

/**
 * Product pictures, which are PUBLIC files — the opposite of vendor documents.
 *
 * They live in the media library so WordPress makes the thumbnails and a theme
 * can render them, and `ownedBy()` is what keeps that from becoming a hole: a
 * gallery may only contain attachments this shop uploaded, so a guessed
 * attachment id cannot pull another shop's (or a customer's) file onto a
 * product page.
 */
interface ProductImageLibraryInterface
{
    /** @return array{ok:bool, code:string, media_id:int} */
    public function store(UploadedFile $file, int $vendorUserId, string $title): array;

    public function ownedBy(int $mediaId, int $vendorUserId): bool;

    /** A thumbnail URL, or '' when the attachment is gone. */
    public function thumbnailUrl(int $mediaId): string;
}
