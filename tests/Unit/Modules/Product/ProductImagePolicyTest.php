<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Product;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Contracts\Files\UploadedFile;
use Tecteb\Marketplace\Modules\Product\Domain\ProductImagePolicy;

/**
 * What may become a product picture, and — the part this suite is really
 * about — what the vendor is TOLD when it may not.
 *
 * A refusal that does not distinguish «too large» from «interrupted» buys a
 * second identical attempt, so each error the platform can raise is checked
 * for the code it maps to, not merely for being refused.
 */
final class ProductImagePolicyTest extends TestCase
{
    private function file(
        int $size = 1000,
        string $mime = 'image/jpeg',
        int $error = UPLOAD_ERR_OK,
        string $temp = '/tmp/php1234',
        string $name = 'photo.jpg'
    ): UploadedFile {
        return new UploadedFile($name, $temp, $size, $mime, $error);
    }

    public function testAnAcceptableFileIsNotRefused(): void
    {
        self::assertSame('', (new ProductImagePolicy())->refuse($this->file()));
    }

    public function testTheHostsSmallerCeilingWinsOverOurs(): void
    {
        // 2 MB host, 3 MB us. Promising 3 on this server would send the vendor
        // back with the same file until they gave up.
        self::assertSame(2097152, ProductImagePolicy::effectiveMaxBytes(2097152));
    }

    public function testOurCeilingStandsWhenTheHostAllowsMoreOrSaysNothing(): void
    {
        self::assertSame(ProductImagePolicy::MAX_BYTES, ProductImagePolicy::effectiveMaxBytes(20971520));
        self::assertSame(ProductImagePolicy::MAX_BYTES, ProductImagePolicy::effectiveMaxBytes(0));
        self::assertSame(ProductImagePolicy::MAX_BYTES, ProductImagePolicy::effectiveMaxBytes(-1));
    }

    public function testAFileUnderOurLimitButOverTheHostsIsStillRefused(): void
    {
        $twoMegabytes = 2097152;
        $file = $this->file(size: 2621440);           // 2.5 MB: under our 3, over the host's 2

        self::assertSame('', (new ProductImagePolicy())->refuse($file), 'our own ceiling would take it');
        self::assertSame('image_too_large', (new ProductImagePolicy($twoMegabytes))->refuse($file));
    }

    /**
     * The one that made a catch-all code look necessary. When PHP stops the
     * upload at `upload_max_filesize` there is no temp file at all, so a check
     * that looked at the path first would answer «no file» — and a vendor told
     * «فایلی انتخاب نشده بود» about a file they can see on their desktop tries
     * the same file again.
     */
    public function testPhpsOwnSizeRefusalIsNamedTooLargeEvenWithNoTempFile(): void
    {
        $policy = new ProductImagePolicy();
        self::assertSame('image_too_large', $policy->refuse(
            $this->file(size: 0, mime: '', error: UPLOAD_ERR_INI_SIZE, temp: '', name: 'huge.jpg')
        ));
        self::assertSame('image_too_large', $policy->refuse(
            $this->file(size: 0, mime: '', error: UPLOAD_ERR_FORM_SIZE, temp: '', name: 'huge.jpg')
        ));
    }

    public function testAHalfArrivedUploadIsNotCalledTooLarge(): void
    {
        // «دوباره بفرست» and «کوچکش کن» are different instructions, and giving
        // the wrong one costs the vendor an attempt.
        self::assertSame('transfer_failed', (new ProductImagePolicy())->refuse(
            $this->file(size: 0, mime: '', error: UPLOAD_ERR_PARTIAL, temp: '')
        ));
        self::assertSame('transfer_failed', (new ProductImagePolicy())->refuse(
            $this->file(size: 0, mime: '', error: UPLOAD_ERR_NO_TMP_DIR, temp: '')
        ));
    }

    public function testAnAbsentFileIsNamedAsSuch(): void
    {
        self::assertSame('no_file', (new ProductImagePolicy())->refuse(
            new UploadedFile('', '', 0, '', UPLOAD_ERR_NO_FILE)
        ));
    }

    public function testAZeroByteFileIsItsOwnAnswer(): void
    {
        self::assertSame('empty_file', (new ProductImagePolicy())->refuse($this->file(size: 0)));
    }

    public function testTheExtensionIsNotBelieved(): void
    {
        // A .jpg whose bytes are PHP. The browser's claim never reaches here:
        // `detectedMime` is what the server read.
        self::assertSame('image_mime_not_allowed', (new ProductImagePolicy())->refuse(
            $this->file(mime: 'text/x-php', name: 'photo.jpg')
        ));
    }

    public function testTheThreeAcceptedTypesAreTheThreeTheMediaLibraryCanThumbnail(): void
    {
        self::assertSame(['image/jpeg', 'image/png', 'image/webp'], ProductImagePolicy::ALLOWED_MIME);
        foreach (ProductImagePolicy::ALLOWED_MIME as $mime) {
            self::assertSame('', (new ProductImagePolicy())->refuse($this->file(mime: $mime)));
        }
    }
}
