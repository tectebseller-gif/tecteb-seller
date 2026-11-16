<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: falling back to the shop's picture cannot touch a file, an
 * attachment or a gallery.
 *
 * The owner's constraint is flat: «هیچ فایل رسانه‌ای، شناسه attachment یا
 * گالری قبلی را تغییر نده و تصاویر تکراری نساز». A behaviour test shows that
 * one render wrote nothing — `StorefrontImageFallbackTest` counts the rows
 * before and after — and it cannot show that the NEXT line added to this
 * reader would not. «There is no road from here to there» is a property of
 * the source, so it is asserted here (`alpha.77`).
 *
 * ⛔ Comments are stripped first: this file's own docblock names the very
 * functions it forbids, and a rule checked against raw source is satisfied by
 * the sentence describing it (`alpha.22`).
 *
 * ⛔ And every needle carries its opening parenthesis, because
 * `update_post_meta` is a substring of `update_post_metadata` and that
 * near-miss has cost this repository a round already (`alpha.33`).
 */
final class StorefrontImageReaderIsReadOnlyTest extends TestCase
{
    private const READER = 'src/Modules/Product/Infrastructure/WooCommerce/WcStorefrontImages.php';
    private const NULL_READER = 'src/Modules/Product/Infrastructure/WooCommerce/NullStorefrontImages.php';
    private const PORT = 'src/Modules/Product/Application/StorefrontImageReaderInterface.php';

    /** Every verb that could create, move, re-stamp or delete media. */
    private const MEDIA_WRITERS = [
        'wp_insert_attachment(',
        'wp_update_post(',
        'wp_insert_post(',
        'wp_delete_attachment(',
        'wp_delete_post(',
        'update_post_meta(',
        'delete_post_meta(',
        'add_post_meta(',
        'update_attached_file(',
        'wp_update_attachment_metadata(',
        'wp_generate_attachment_metadata(',
        'set_post_thumbnail(',
        'delete_post_thumbnail(',
        'set_image_id(',
        'set_gallery_image_ids(',
        'copy(',
        'rename(',
        'unlink(',
        'file_put_contents(',
    ];

    private const SQL_WRITERS = ['INSERT ', 'UPDATE ', 'DELETE ', 'REPLACE ', 'TRUNCATE ', 'ALTER ', 'DROP '];

    private function code(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        self::assertFileExists($path);
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    public function testNeitherReaderNorPortNamesAMediaWriter(): void
    {
        foreach ([self::READER, self::NULL_READER, self::PORT] as $file) {
            $code = $this->code($file);
            foreach (self::MEDIA_WRITERS as $needle) {
                self::assertStringNotContainsString($needle, $code, $file . ' must not be able to write media: ' . $needle);
            }
        }
    }

    public function testTheReaderRunsNoWritingStatement(): void
    {
        $code = strtoupper($this->code(self::READER));
        foreach (self::SQL_WRITERS as $needle) {
            self::assertStringNotContainsString($needle, $code, 'the image reader must only SELECT: ' . trim($needle));
        }
        self::assertStringContainsString('SELECT', $code, 'and it does read');
    }

    /**
     * The port offers ONE method and it returns URLs.
     *
     * Not tidiness: an `attachmentIdFor()` beside it is the method whose
     * answer somebody puts into a gallery, and an attachment this shop does
     * not own is dropped by the very next save — so the vendor's gallery
     * would appear to accept the shop's picture and then lose it. The absence
     * is the guard.
     */
    public function testThePortOffersOnlyUrls(): void
    {
        $code = $this->code(self::PORT);
        self::assertSame(
            1,
            substr_count($code, 'public function'),
            'one method on the port: two readers of one picture is how two screens come to disagree'
        );
        self::assertStringContainsString('thumbnailUrls(', $code);
        foreach (['attachmentId', 'mediaId', 'imageId'] as $idish) {
            self::assertStringNotContainsString($idish, $code, 'the port hands out no attachment id: ' . $idish);
        }
    }
}
