<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Contracts\FlashStoreInterface;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\TransientFlashStore;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorMessages;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorNotice;

/**
 * The three sentences that quote a server-side value, and the store that
 * carries those values across the redirect.
 *
 * The defect this guards against was visible only on a screenshot: the size
 * refusal printed «حداکثر ۰ مگابایت» because the context died in the
 * redirect while the code survived. Both halves are asserted here — the
 * value arrives when the flash is there, and no wrong number is printed when
 * it is not.
 */
final class VendorNoticeTest extends ContractTestCase
{
    public function testTheSizeRefusalQuotesTheManagersLimit(): void
    {
        $text = VendorMessages::notice('too_large', ['max_bytes' => 2 * 1024 * 1024]);

        self::assertStringContainsString('۲', $text);
        self::assertStringContainsString('مگابایت', $text);
    }

    public function testTheSizeRefusalNeverPrintsZeroWhenTheLimitIsMissing(): void
    {
        $text = VendorMessages::notice('too_large');

        self::assertStringNotContainsString('۰ مگابایت', $text);
        self::assertStringContainsString('بزرگ‌تر از حد مجاز', $text);
    }

    public function testTheFormatRefusalListsTheAllowedFormats(): void
    {
        $withList = VendorMessages::notice('mime_not_allowed', ['allowed' => 'application/pdf, image/png']);
        $without = VendorMessages::notice('mime_not_allowed');

        self::assertStringContainsString('application/pdf', $withList);
        self::assertStringNotContainsString('مجاز: ', $without);
        self::assertStringContainsString('پذیرفته نیست', $without);
    }

    public function testTheMissingDocumentsMessageNamesThem(): void
    {
        $named = VendorMessages::notice('missing_documents', ['types' => 'پروانه کسب']);
        $unnamed = VendorMessages::notice('missing_documents');

        self::assertStringContainsString('پروانه کسب', $named);
        self::assertStringNotContainsString('نشده‌اند: ', $unnamed);
    }

    public function testTheStorageRefusalSaysUploadsAreOffRatherThanRetryLater(): void
    {
        $text = VendorMessages::notice('storage_unavailable');

        self::assertStringContainsString('محل امن', $text);
        self::assertStringNotContainsString('دوباره تلاش', $text);
    }

    public function testAFlashIsReadOnceAndOnlyForItsOwnCode(): void
    {
        $store = new TransientFlashStore();
        $store->put('vendor_notice_7', ['code' => 'too_large', 'context' => ['max_bytes' => 4096]], 60);

        $first = $store->take('vendor_notice_7');
        $second = $store->take('vendor_notice_7');

        self::assertSame(['code' => 'too_large', 'context' => ['max_bytes' => 4096]], $first);
        self::assertNull($second, 'a flash belongs to the request after the write, and to no later one');

        $matching = VendorNotice::fromRequest('too_large', $first);
        $mismatched = VendorNotice::fromRequest('mime_not_allowed', $first);

        self::assertSame(['max_bytes' => 4096], $matching?->context);
        self::assertSame([], $mismatched?->context, 'a stale flash must not decorate a different message');
    }

    public function testTheContainerBindsTheFlashStore(): void
    {
        self::assertInstanceOf(TransientFlashStore::class, Bootstrap::container()->get(FlashStoreInterface::class));
    }
}
