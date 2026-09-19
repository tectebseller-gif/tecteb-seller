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

    /**
     * Copies an existing attachment into a NEW one owned by this vendor, and
     * returns its id — or 0 when there is nothing to copy.
     *
     * A COPY, not a re-parenting. A migrated product's photograph belongs to
     * whoever uploaded it in the old system, and that same attachment is
     * still hanging on the old system's product while both are installed.
     * Changing its owner would edit a record another plugin is using, which
     * this project does not do; so the file is duplicated and the original is
     * left exactly as it was.
     *
     * The cost is a second copy of the file, and it is the right cost: the
     * alternative is a migrated catalogue whose pictures vanish the first
     * time a vendor saves one of its products, because the gallery only keeps
     * media the vendor owns.
     */
    public function duplicateForVendor(int $mediaId, int $vendorUserId): int;

    /** A thumbnail URL, or '' when the attachment is gone. */
    public function thumbnailUrl(int $mediaId): string;
}
