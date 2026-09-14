<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Migration\Migrations\M0001CreateAuditTable;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables;
use Tecteb\Marketplace\Modules\Product\Presentation\Admin\ProductReviewPage;
use Tecteb\Marketplace\Modules\Product\Presentation\Admin\SpecTemplatesPage;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0002CreateVendorTables;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0003CreateStoreAndStaffTables;
use TmcWpStubs\State;

/**
 * The manager's two product screens, rendered against the real schema.
 *
 * They belong in this suite rather than the contract one because they read
 * the queue out of the database on every render — which is the point: an
 * empty queue has to be an empty queue, not a fabricated one.
 */
final class ProductAdminPagesTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = new WpDatabase($this->wpdb);
        foreach ([
            ...M0002CreateVendorTables::TABLES,
            ...M0003CreateStoreAndStaffTables::TABLES,
            ...M0004CreateFinanceTables::TABLES,
            ...M0005CreateProductTables::TABLES,
        ] as $suffix) {
            $this->wpdb->dropTable($this->wpdb->prefix . $suffix);
        }
        (new M0001CreateAuditTable())->up($db);
        (new M0002CreateVendorTables())->up($db);
        (new M0003CreateStoreAndStaffTables())->up($db);
        (new M0004CreateFinanceTables())->up($db);
        (new M0005CreateProductTables())->up($db);
    }

    public function testTheReviewPageSeparatesTheTwoQueuesAndTheTwoPermissions(): void
    {
        $this->bootPlugin(false);
        State::loginAs(9, ['read', 'tmc_review_products']);
        $out = $this->capture(fn () => (new ProductReviewPage(Bootstrap::container()))->render());

        self::assertStringContainsString('محصول‌های در انتظار انتشار', $out);
        self::assertStringContainsString('نسخه‌های پیشنهادی محصول‌های منتشرشده', $out);
        self::assertStringContainsString('صف خالی است.', $out, 'an empty queue says so instead of inventing rows');
        self::assertStringContainsString('مجوز انتشار مستقیم', $out);
        self::assertStringContainsString('این مجوز از «اجازه فروش» جداست', $out);
        self::assertStringContainsString('dir="rtl"', $out);
    }

    public function testTheTemplateBuilderSaysAKeyNeverChangesAndAFieldIsNeverDeleted(): void
    {
        $this->bootPlugin(false);
        State::loginAs(9, ['read', 'tmc_manage_spec_templates']);
        $out = $this->capture(fn () => (new SpecTemplatesPage(Bootstrap::container()))->render());

        self::assertStringContainsString('الگوهای مشخصات', $out);
        self::assertStringContainsString('فیلدهای پزشکی برای همه دسته‌ها نمایش داده نمی‌شوند', $out);
        self::assertStringContainsString('الزام قانونی نیست', $out, 'MED-01: no automatic medical claim');
        self::assertStringContainsString('ساخت الگو', $out);
        self::assertStringContainsString('هنوز الگویی ساخته نشده است.', $out);
    }
}
