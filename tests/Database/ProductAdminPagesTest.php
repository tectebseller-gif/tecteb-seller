<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Migration\Migrations\M0001CreateAuditTable;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0010LinkOwnership;
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
        $this->resetSchema($db);
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
        // WooCommerce ON: categories are ITS taxonomy, so the directory only
        // exists when it does. Booting without it and then seeding terms
        // tested nothing — the page correctly answered «no categories».
        $this->bootPlugin(true);
        State::loginAs(9, ['read', 'tmc_manage_spec_templates']);
        // A template attaches to a category that exists, so one has to exist.
        State::$terms = ['product_cat' => [
            42 => ['name' => 'بیهوشی و تنفسی', 'slug' => 'anesthesia', 'parent' => 0, 'count' => 0],
        ]];
        $out = $this->capture(fn () => (new SpecTemplatesPage(Bootstrap::container()))->render());
        State::$terms = [];

        self::assertStringContainsString('الگوهای مشخصات', $out);
        self::assertStringContainsString('فیلدهای پزشکی برای همه دسته‌ها نمایش داده نمی‌شوند', $out);
        self::assertStringContainsString('الزام قانونی نیست', $out, 'MED-01: no automatic medical claim');
        self::assertStringContainsString('ساخت الگو', $out);
        self::assertStringContainsString('هنوز الگویی ساخته نشده است.', $out);
        self::assertStringContainsString(
            'name="category_key" value="42"',
            $out,
            'the template is attached to a real product_cat term, not to a word typed here'
        );
        self::assertStringNotContainsString(
            'کلید دسته (انگلیسی)',
            $out,
            'the manager no longer INVENTS a category on this page'
        );
    }

    /**
     * No categories at all is a different sentence from no templates.
     *
     * Before `alpha.25` this page could not tell them apart, because it did
     * not read the taxonomy: it offered a key field, the manager typed a
     * word, and a category nobody could see came into being.
     */
    public function testWithNoWooCommerceCategoriesThePageSaysWhereToMakeThem(): void
    {
        $this->bootPlugin(false);
        State::loginAs(9, ['read', 'tmc_manage_spec_templates']);
        State::$terms = ['product_cat' => []];
        $out = $this->capture(fn () => (new SpecTemplatesPage(Bootstrap::container()))->render());
        State::$terms = [];

        self::assertStringContainsString('محصولات ← دسته‌بندی‌ها', $out);
        self::assertStringNotContainsString('ساخت الگو', $out, 'nothing to attach a template to yet');
    }
}
