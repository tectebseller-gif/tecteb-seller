<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Finance;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Finance\Application\VendorWithdrawalPolicy;

/**
 * WHAT THIS PROVES: new withdrawal requests are closed in THIS build, and the
 * decision is not something a setting or a trial switch can move.
 *
 * §7: «فعلاً درخواست برداشت جدید برای فروشنده بسته شود.» The value is a
 * constant on purpose — a setting would be a second place the answer lives —
 * so this test is the thing that notices if it changes by accident. When the
 * owner reopens requests, this test changes in the same commit as the
 * constant, with a delivery note, which is exactly the visibility wanted.
 */
final class VendorWithdrawalPolicyTest extends TestCase
{
    public function testNewRequestsAreClosedInThisBuild(): void
    {
        self::assertFalse((new VendorWithdrawalPolicy())->requestsAreOpen());
    }

    /**
     * WHAT THIS PROVES: a vendor cannot release an open request either.
     *
     * Cancelling frees the reserved lines, and «درخواست‌های قبلی حذف، لغو یا
     * آزاد نشوند؛ مدیر بررسی کند» puts that in the manager's hands. The
     * manager's own path does not consult this class at all, which is what
     * keeps «مدیر بررسی کند» possible.
     */
    public function testAVendorCannotReleaseAnOpenRequestEither(): void
    {
        self::assertFalse((new VendorWithdrawalPolicy())->cancelIsOpen());
    }

    /**
     * WHAT THIS PROVES: the policy has no writer and no setting to read.
     *
     * Read off the file, because that is the only way to say «nothing can
     * flip this at runtime» about a class rather than about one method:
     * `get_option`, `update_option` and a settings service would each be a
     * second answer to a question the owner answered once.
     */
    public function testThePolicyReadsNoSettingAndWritesNothing(): void
    {
        $source = (string) file_get_contents(
            __DIR__ . '/../../../../src/Modules/Finance/Application/VendorWithdrawalPolicy.php'
        );
        foreach (['get_option', 'update_option', 'SettingsService', 'OrderTrialInterface'] as $needle) {
            self::assertStringNotContainsString($needle, $source, $needle);
        }
    }
}
