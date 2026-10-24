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
    /**
     * @param ?\Closure(string): void $step called with a machine key for each
     *        named sub-step as it STARTS, so a worker cut off inside one
     *        leaves its name behind. The copy and the metadata are reported
     *        apart because they are not comparable: measured on the
     *        disposable environment the copy is under a millisecond and the
     *        metadata is three to seven seconds for a large source — but on
     *        network-backed storage the copy could be the slow one, and
     *        assuming otherwise is how a diagnosis goes wrong.
     */
    public function duplicateForVendor(int $mediaId, int $vendorUserId, ?\Closure $step = null): int;

    /** A thumbnail URL, or '' when the attachment is gone. */
    public function thumbnailUrl(int $mediaId): string;

    /**
     * Deletes one attachment, and only one this shop uploaded.
     *
     * `ownedBy()` again, for the same reason it guards a gallery: an id is
     * just a number, and a delete is worse than a read. Whether anything
     * USES the attachment is not asked here — the caller has to have
     * established that, because this layer cannot see a product row.
     */
    public function discard(int $mediaId, int $vendorUserId): bool;
}
