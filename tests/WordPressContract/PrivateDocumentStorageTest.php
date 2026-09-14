<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Contracts\Files\PrivateStorageUnavailable;
use Tecteb\Marketplace\Contracts\Files\UploadedFile;
use Tecteb\Marketplace\Core\Support\FilesystemPath;
use Tecteb\Marketplace\Modules\Vendor\Domain\PrivateStoragePlacement;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\LegacyPrivateDocuments;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\PrivateUploadStorage;
use TmcWpStubs\State;

/**
 * Where the bytes actually land, against the WordPress stubs' idea of a site.
 *
 * The property under test is not "the name is unguessable" or "the deny file
 * is written" — both were already true when documents sat in uploads/. It is
 * that no document is ever written to a path the web server is willing to
 * serve, and that when no other path exists the upload fails loudly.
 */
final class PrivateDocumentStorageTest extends ContractTestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/tmc-storage-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/public_html/wp-content/uploads', 0o700, true);
        State::$uploadBaseDir = $this->root . '/public_html/wp-content/uploads';
    }

    protected function tearDown(): void
    {
        State::$uploadBaseDir = null;
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testAStoredDocumentIsNotInsideTheUploadsDirectory(): void
    {
        $storage = new PrivateUploadStorage($this->root . '/tecteb-private');

        $stored = $storage->store($this->pdf(), 'application-12');

        $onDisk = $this->root . '/tecteb-private/' . $stored->relativePath;
        self::assertFileExists($onDisk);
        self::assertFalse(
            FilesystemPath::isInside($onDisk, (string) State::$uploadBaseDir),
            'a document under uploads/ is one misconfiguration away from being served'
        );
        self::assertSame('%PDF-1.4 sample', $storage->read($stored->relativePath));
    }

    public function testStoringIntoTheUploadsDirectoryIsRefusedEvenWhenAskedExplicitly(): void
    {
        $storage = new PrivateUploadStorage(State::$uploadBaseDir . '/tmc-private');

        self::assertSame(PrivateStoragePlacement::INSIDE_WEB_ROOT, $storage->unavailableReason());

        $this->expectException(PrivateStorageUnavailable::class);
        $storage->store($this->pdf(), 'application-12');
    }

    public function testNothingIsReadableWhileStorageIsUnavailable(): void
    {
        $storage = new PrivateUploadStorage(State::$uploadBaseDir . '/tmc-private');

        self::assertNull($storage->read('application-12/' . str_repeat('a', 32) . '.pdf'));
        self::assertFalse($storage->exists('application-12/' . str_repeat('a', 32) . '.pdf'));
    }

    public function testAPathThatClimbsBackIntoAWebRootIsRefused(): void
    {
        // Spelled as a climb rather than as the plain path, because that is
        // how such a setting reaches a config file: someone writes a relative
        // hop from a directory they trust and lands back inside uploads/.
        $storage = new PrivateUploadStorage($this->root . '/tecteb-private/../public_html/wp-content/uploads/secrets');

        self::assertSame(PrivateStoragePlacement::INSIDE_WEB_ROOT, $storage->unavailableReason());
    }

    public function testDocumentsLeftInTheOldDirectoryAreMovedOutOfIt(): void
    {
        $legacy = PrivateUploadStorage::legacyBaseDir();
        mkdir($legacy . '/application-7', 0o700, true);
        $name = str_repeat('ab', 16) . '.pdf';
        file_put_contents($legacy . '/application-7/' . $name, 'old licence bytes');

        $storage = new PrivateUploadStorage($this->root . '/tecteb-private');
        $result = LegacyPrivateDocuments::relocate($storage);

        self::assertSame(1, $result['moved']);
        self::assertSame(0, $result['failed']);
        self::assertFileDoesNotExist($legacy . '/application-7/' . $name);
        self::assertSame('old licence bytes', $storage->read('application-7/' . $name), 'the stored path must still resolve');
    }

    public function testRelocationLeavesFilesAloneWhenThereIsNowhereSafeToPutThem(): void
    {
        $legacy = PrivateUploadStorage::legacyBaseDir();
        mkdir($legacy . '/application-7', 0o700, true);
        $name = str_repeat('cd', 16) . '.pdf';
        file_put_contents($legacy . '/application-7/' . $name, 'old licence bytes');

        $result = LegacyPrivateDocuments::relocate(new PrivateUploadStorage(State::$uploadBaseDir . '/tmc-private'));

        self::assertTrue($result['skipped']);
        self::assertSame('no_safe_directory', $result['reason']);
        self::assertFileExists($legacy . '/application-7/' . $name, 'a file we cannot move safely is never deleted');
    }

    private function pdf(): UploadedFile
    {
        $tmp = $this->root . '/incoming-' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($tmp, '%PDF-1.4 sample');
        return new UploadedFile('licence.pdf', $tmp, (int) filesize($tmp), 'application/pdf');
    }

    private function removeTree(string $path): void
    {
        if ($path === '' || !is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeTree($full) : @unlink($full);
        }
        @rmdir($path);
    }
}
