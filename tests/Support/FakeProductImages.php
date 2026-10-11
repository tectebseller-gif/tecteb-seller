<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Contracts\Files\UploadedFile;
use Tecteb\Marketplace\Modules\Product\Application\ProductImageLibraryInterface;

/**
 * A media library without WordPress.
 *
 * It keeps the one property the real one is trusted for — an attachment
 * belongs to exactly one shop — so the ownership rule can be tested without a
 * media library, and a test that hands one shop another's id fails here for
 * the same reason it would in production.
 */
final class FakeProductImages implements ProductImageLibraryInterface
{
    /** @var array<int,int> media id => owner */
    private array $owners = [];
    private int $nextId = 500;

    /** @var list<int> every attachment a discard removed, in order */
    private array $discarded = [];

    /** @var list<int> every attachment `store()` minted, in order */
    private array $stored = [];

    public function store(UploadedFile $file, int $vendorUserId, string $title): array
    {
        $id = $this->nextId++;
        $this->owners[$id] = $vendorUserId;
        $this->stored[] = $id;
        return ['ok' => true, 'code' => 'image_uploaded', 'media_id' => $id];
    }

    /** Copies into a new id this vendor owns, mirroring the real adapter. */
    public function duplicateForVendor(int $mediaId, int $vendorUserId): int
    {
        if ($mediaId <= 0 || $vendorUserId <= 0 || !isset($this->owners[$mediaId])) {
            return 0;
        }
        $newId = max(array_keys($this->owners)) + 1;
        $this->owners[$newId] = $vendorUserId;
        return $newId;
    }

    public function ownedBy(int $mediaId, int $vendorUserId): bool
    {
        return ($this->owners[$mediaId] ?? 0) === $vendorUserId;
    }

    public function discard(int $mediaId, int $vendorUserId): bool
    {
        if (!$this->ownedBy($mediaId, $vendorUserId)) {
            return false;
        }
        unset($this->owners[$mediaId]);
        $this->discarded[] = $mediaId;
        return true;
    }

    /** @return list<int> what `discard()` was actually allowed to delete */
    public function discarded(): array
    {
        return $this->discarded;
    }

    public function thumbnailUrl(int $mediaId): string
    {
        return 'https://example.test/wp-content/uploads/' . $mediaId . '.jpg';
    }

    /** @return list<int> every attachment this library minted, in order */
    public function stored(): array
    {
        return $this->stored;
    }

    /** Test helper: pretend this shop already uploaded this attachment. */
    public function give(int $mediaId, int $vendorUserId): void
    {
        $this->owners[$mediaId] = $vendorUserId;
        $this->nextId = max($this->nextId, $mediaId + 1);
    }
}
