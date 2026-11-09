<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Order\Application\OrderAdminUrlInterface;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\VendorOrderItem;
use Tecteb\Marketplace\Modules\Order\Presentation\Admin\OrderAuditPage;
use Tecteb\Marketplace\Modules\Order\Presentation\Admin\OrderLedgerPage;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;

/**
 * WHAT THIS PROVES: the report screens emit the markup the stylesheet needs —
 * measured on the RENDERED page, not on the source.
 *
 * ⛔ **WHY RENDERED AND NOT READ.** Two of the owner's six §3 defects are
 * invisible to a source test: `.tmc-table` only becomes a table inside a
 * `@container tmccard`, and the cells are labelled from `data-label`. A test
 * that found the right class names in a view file passed on `alpha.99` — the
 * classes were all there. What was missing was the CARD above them and the
 * LABEL on each cell, and only the output says whether they are present
 * (`alpha.96`: a registered slug is not a page in the right place).
 *
 * ⛔ **AND IT WRITES THE HTML OUT.** The files under
 * `docs/evidence/alpha100-reports/` are what the browser run then measures at
 * 1440, 1280, 768 and 390 pixels — the widths §3 names. This test is the only
 * place that HTML comes from, so the screenshots cannot be of a page that
 * differs from the one this suite checked.
 */
final class ReportLayoutEvidenceTest extends DatabaseTestCase
{
    private const VENDOR = 102;
    private const EVIDENCE = 'docs/evidence/alpha100-reports';

    private OrderItemRepositoryInterface $items;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPlugin(true, '11.0.1', true);
        $this->loginAdmin();
        Bootstrap::container()->get(MigrationRunner::class)->run();
        $db = new WpDatabase($this->wpdb);
        foreach ([
            M0006CatalogAndOrders::table($db, M0006CatalogAndOrders::ORDER_ITEMS),
            $db->prefix() . 'tmc_ledger_entries',
        ] as $table) {
            $this->wpdb->pdo()->exec('TRUNCATE TABLE `' . $table . '`');
        }
        Bootstrap::container()->get(VendorRepositoryInterface::class)
            ->upsertProfile(self::VENDOR, 'فروشگاه ۱۰۲', true, false);
        $this->items = Bootstrap::container()->get(OrderItemRepositoryInterface::class);
        // An order link in the HPOS shape — the owner's staging — so the
        // evidence shows the address a manager would actually click.
        Bootstrap::container()->bind(
            OrderAdminUrlInterface::class,
            static fn (): OrderAdminUrlInterface => new class implements OrderAdminUrlInterface {
                public function editUrl(int $wcOrderId): ?string
                {
                    return $wcOrderId > 0
                        ? 'https://staging.example.test/wp-admin/admin.php?page=wc-orders&action=edit&id='
                            . $wcOrderId
                        : null;
                }
            }
        );
    }

    /**
     * ⛔ THE CARD. Without an ancestor carrying `container-name: tmccard` the
     * container query can never match and the table stays a stack of blocks
     * at every width — `alpha.99`'s defect, exactly.
     */
    public function testThePerLineTableIsInsideACard(): void
    {
        $this->seed(6);
        $html = $this->render();
        $table = strpos($html, 'tmc-table');
        self::assertIsInt($table, 'the table must be rendered');
        $before = substr($html, 0, $table);
        // ⛔ THE CARD THAT ENCLOSES **THIS** TABLE, not one that happens to
        // appear earlier on the page. The first version of this looked for
        // `class="tmc-card"` anywhere before the table and the summary cards
        // above satisfied it — so removing the table's own card left the test
        // green while the container query had nothing to match. A needle the
        // page prints in several places says nothing about this place
        // (`alpha.88`). The last section to OPEN before the table must be the
        // card, and nothing may have closed in between.
        $lastOpen = strrpos($before, '<section');
        self::assertIsInt($lastOpen, 'the table must be inside a section');
        $enclosing = substr($before, $lastOpen);
        self::assertStringContainsString(
            'class="tmc-card"',
            substr($enclosing, 0, (int) strpos($enclosing, '>') + 1),
            'the section the table sits in must BE the card — the container query matches nothing otherwise'
        );
        self::assertStringNotContainsString(
            '</section>',
            $enclosing,
            'and that card must still be open where the table starts'
        );
        // And the scroll region is still there, so a wide table scrolls
        // instead of pushing the page.
        self::assertStringContainsString('class="tmc-scroll"', $html);
    }

    /**
     * ⛔ EVERY CELL CARRIES ITS LABEL. In the stacked layout a narrow screen
     * gets, `td::before { content: attr(data-label) ": " }` is the only thing
     * that says which figure is which — and `alpha.99` rendered thirteen
     * values each after a bare «: ».
     */
    public function testEveryCellOfTheRenderedTableCarriesALabel(): void
    {
        $this->seed(6);
        $html = $this->render();
        self::assertSame(
            0,
            preg_match_all('/<td(?![^>]*data-label)[^>]*>/', $html, $m),
            'unlabelled cells: ' . implode(' | ', $m[0] ?? [])
        );
        // And the labels are the headings: one list, two readers.
        foreach (['سفارش', 'سهم فروشنده', 'جزئیات حسابداری'] as $heading) {
            self::assertStringContainsString(
                'data-label="' . $heading . '"',
                $html,
                'the heading «' . $heading . '» must also be a cell label'
            );
        }
    }

    /**
     * ⛔ A STATE CELL HOLDS A WORD, AND THE SENTENCE IS IN THE FOLD.
     *
     * Found by LOOKING at the rendered table, not by reading the code: the
     * payment column printed «نامعلوم — از زمان نصب، وضعیتی برای این سفارش
     * اعلام نشده» inside a narrow column, which wrapped to one word per line
     * and made every such row four hundred pixels tall. Twenty-five of them
     * are ten thousand pixels of one sentence repeated.
     *
     * Both halves are asserted, because deleting the sentence would pass an
     * assertion about its length: the explanation must still be SOMEWHERE,
     * and that somewhere is the row's own disclosure.
     */
    public function testAStateCellIsAWordAndTheReasonIsFolded(): void
    {
        $this->seed(4);
        $html = $this->render();
        $found = preg_match_all('/<td[^>]*data-label="پرداخت"[^>]*>(.*?)<\/td>/su', $html, $cells);
        self::assertGreaterThan(0, $found, 'the payment column must be rendered at all');
        foreach ($cells[1] as $cell) {
            $text = trim(strip_tags($cell));
            self::assertLessThanOrEqual(
                30,
                mb_strlen($text),
                'a state cell must hold a word, not a sentence: ' . $text
            );
        }
        // …and the reason is not lost, only moved.
        self::assertStringContainsString('وضعیت پرداخت نامعلوم است:', $html);
        $fold = mb_strpos($html, 'وضعیت پرداخت نامعلوم است:');
        $summary = mb_strrpos(mb_substr($html, 0, $fold), '<summary>');
        self::assertNotFalse($summary, 'the reason must sit inside a disclosure');
    }

    /**
     * ⛔ THE HEAVY ACCOUNTING DETAIL IS ON THE SCREEN, FOLDED.
     *
     * «جزئیات سنگین حسابداری در ردیف قابل بازشدن یا صفحهٔ جزئیات نمایش داده
     * شود». Before this round the reversing document's key, the unit the
     * figures are in and the settlement moment were in the CSV and on NO
     * screen — and the reversing document is the one fact this release turns
     * on, because cancelling order 12300 leaves the accrual in place and
     * writes a `…:void` document beside it.
     *
     * Asserted on the RENDERED row, and the disclosure must be CLOSED: a
     * detail that opens by itself is a thirteenth column with extra steps.
     */
    public function testTheHeavyAccountingDetailIsFoldedIntoEachRow(): void
    {
        $this->seed(3);
        $html = $this->render();
        self::assertStringContainsString('<summary>جزئیات حسابداری</summary>', $html);
        self::assertSame(
            0,
            preg_match_all('/<details[^>]*\bopen\b[^>]*><summary>جزئیات حسابداری/', $html),
            'the per-row accounting detail must start folded'
        );
        // The facts that were nowhere, now by name. Each is a LINE rather than
        // a column, and each has a named absence — «سند معکوسی ثبت نشده است»
        // on a line that has none, which is the finding, not a blank.
        foreach (['کلید سند مالی', 'واحد پول', 'نرخ کمیسیون', 'مالیات', 'تسویه'] as $fact) {
            self::assertStringContainsString($fact, $html, "the detail must name «{$fact}»");
        }
        self::assertStringContainsString('سند معکوسی ثبت نشده است.', $html);
        self::assertStringContainsString('شناسهٔ قلم ووکامرس', $html);
    }

    /**
     * ⛔ THE ORDER LINK IS THE HPOS ONE, and `post.php` appears nowhere. This
     * is the assertion that would have failed on `alpha.99`.
     */
    public function testTheOrderLinkIsTheOneWooCommerceGave(): void
    {
        $this->seed(2);
        $html = $this->render();
        // Asserted in parts, because the ampersand's escaping is WordPress's
        // decision (`&#038;` in core, `&amp;` under the stub) and this test is
        // about the ADDRESS rather than about entity encoding.
        self::assertStringContainsString('page=wc-orders', $html);
        self::assertStringContainsString('action=edit', $html);
        self::assertStringContainsString('id=12299', $html);
        self::assertStringNotContainsString('post.php?post=', $html, 'the alpha.99 defect');
    }

    /** The filter bar is a grid of fields, with the advanced half folded. */
    public function testTheFilterBarIsAGridWithAFoldedHalf(): void
    {
        $this->seed(2);
        $html = $this->render();
        self::assertStringContainsString('class="tmc-filter-form"', $html);
        self::assertStringContainsString('class="tmc-filter-form__more"', $html);
        self::assertStringContainsString('class="tmc-filter-form__actions"', $html);
        // Closed when nothing advanced is set…
        self::assertStringNotContainsString('tmc-filter-form__more" open', $html);
    }

    /** …and open when one of its own filters narrowed the list. */
    public function testTheAdvancedHalfOpensWhenOneOfItsFiltersIsSet(): void
    {
        $this->seed(2);
        $html = $this->render(['product' => '5012299']);
        self::assertStringContainsString('tmc-filter-form__more" open', $html);
    }

    /** Both report screens print the one navigation strip. */
    public function testBothReportScreensPrintTheSameNavigation(): void
    {
        $this->seed(2);
        $ledger = $this->render();
        $audit = $this->capture(static fn () => (new OrderAuditPage(Bootstrap::container()))->render());
        foreach ([$ledger, $audit] as $html) {
            self::assertStringContainsString('class="tmc-reportnav"', $html);
            self::assertStringContainsString('tmc-reportnav__link', $html);
        }
        self::assertStringContainsString('is-current" href', $ledger, 'the current page is marked');
    }

    /** The export offers both scopes, with the cap on the screen. */
    public function testTheExportOffersBothScopes(): void
    {
        $this->seed(2);
        $html = $this->render();
        self::assertStringContainsString('name="scope" value="filter" checked', $html);
        self::assertStringContainsString('name="scope" value="page"', $html);
        self::assertStringContainsString('۵۰۰۰', $html, 'the cap, in Persian digits');
    }

    /**
     * The whole-set summary is above the page's own totals, and both say in
     * words which set they are about.
     */
    public function testTheWholeSetSummaryAndThePageTotalsAreBothLabelled(): void
    {
        $this->seed(6);
        $html = $this->render();
        $whole = strpos($html, 'جمع کل همهٔ ردیف‌ها');
        $page = strpos($html, 'جمع همین صفحه');
        self::assertIsInt($whole, 'the whole-set card must be printed');
        self::assertIsInt($page, 'the page card must still be printed');
        self::assertLessThan($page, $whole, 'the whole set comes first');
    }

    /** And filtering to one product names the product in the heading. */
    public function testFilteringToOneProductNamesItInTheSummaryHeading(): void
    {
        $this->seed(4);
        $html = $this->render(['product' => '5012299']);
        self::assertStringContainsString('گزارش محصول #', $html);
    }

    /**
     * ⛔ AND THE HTML IS WRITTEN OUT, for the browser run to measure. Three
     * documents: the report, the report filtered to one product, and the
     * reconciliation audit.
     */
    public function testTheEvidenceDocumentsAreWritten(): void
    {
        $this->seed(25);
        $dir = dirname(__DIR__, 2) . '/' . self::EVIDENCE;
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        $pages = [
            'order-ledger' => ['سفارش‌ها ردیف به ردیف', $this->render()],
            'order-ledger-product' => [
                'سفارش‌ها — گزارش یک محصول',
                $this->render(['product' => '5012299']),
            ],
            'order-audit' => [
                'تطبیق سفارش و سند',
                $this->capture(static fn () => (new OrderAuditPage(Bootstrap::container()))->render()),
            ],
        ];
        foreach ($pages as $name => [$title, $body]) {
            self::assertNotSame('', $body, $name . ' rendered nothing');
            $file = $dir . '/' . $name . '.html';
            self::assertNotFalse(
                file_put_contents($file, self::document($title, $body)),
                'the evidence document must be written'
            );
            self::assertFileExists($file);
        }
    }

    /**
     * A stand-in for the wp-admin frame — INCLUDING its 160px menu column.
     *
     * ⛔ The menu is not decoration here: the container query is about the
     * width the CARD gets, and the menu takes 160 pixels of it away.
     * Measuring this page at a bare 1440 would answer about a width no
     * manager ever sees. «همراه با منوی مدیریت وردپرس», as §3 asks.
     *
     * WHAT THIS IS: a sample of the interface. WHAT IT IS NOT: proof the
     * plugin works inside real wp-admin, which stays `Not Run`.
     */
    private static function document(string $title, string $body): string
    {
        $root = dirname(__DIR__, 2);
        return '<!DOCTYPE html><html dir="rtl" lang="fa"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES) . ' — نمونه رابط</title>'
            . '<link rel="stylesheet" href="file://' . $root . '/assets/admin/tmc-admin.css">'
            . '<style>'
            . 'html,body{margin:0;padding:0;background:#f0f0f1;'
            . 'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Noto Naskh Arabic",Tahoma,sans-serif}'
            . '#wpwrap{display:flex;min-height:100vh}'
            . '#adminmenuwrap{flex:0 0 160px;background:#1d2327;color:#f0f0f1;padding:12px 8px;font-size:13px}'
            . '#adminmenuwrap p{margin:0 0 10px}'
            . '#wpbody{flex:1 1 auto;min-width:0;padding:12px 20px 40px}'
            . '@media (max-width:782px){#adminmenuwrap{display:none}}'
            . '.harness-banner{background:#143C4D;color:#fff;padding:8px 12px;border-radius:8px;'
            . 'margin:0 0 16px;font-size:14px}'
            . '</style></head><body><div id="wpwrap">'
            . '<div id="adminmenuwrap" aria-hidden="true"><p>پیشخوان</p><p>فروشگاه‌ها</p><p>محصولات</p>'
            . '<p>سفارش‌ها</p><p>کمیسیون</p><p>درخواست‌ها</p><p>تنظیمات</p><p>سلامت</p></div>'
            . '<div id="wpbody"><p class="harness-banner">نمونه رابط — این تصویر اثبات کارکرد افزونه '
            . 'داخل wp-admin نیست.</p>'
            . $body
            . '</div></div><script src="file://' . $root . '/assets/admin/tmc-admin.js"></script>'
            . '</body></html>';
    }

    /** @param array<string,string> $query */
    private function render(array $query = []): string
    {
        // ⛔ THE METHOD IS SET, NOT INHERITED. `OrderLedgerPage::input()` reads
        // the filters from `$_POST` when the request is a POST, and the method
        // comes from `$_SERVER` — which the previous test in the suite leaves
        // behind. Alone, these tests passed; in the full run two of them read
        // an empty `$_POST` and reported a page with no filter on it. The
        // `alpha.33` rule, on the one global a fixture cannot see itself
        // inheriting: put the world in a known state, then give it back.
        $method = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = array_merge(['page' => OrderLedgerPage::SLUG], $query);
        $_POST = [];
        $_REQUEST = $_GET;
        $html = $this->capture(static fn () => (new OrderLedgerPage(Bootstrap::container()))->render());
        $_GET = [];
        $_REQUEST = [];
        if ($method === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $method;
        }
        return $html;
    }

    private function seed(int $count): void
    {
        $titles = [
            'چسب تزریق پزشکی استریل بسته ۱۰۰ عددی',
            'آمبوبگ بزرگسال سیلیکونی قابل استریل',
            'ماسک اکسیژن کودک با کیسه ذخیره',
            'دستکش معاینه نیتریل بدون پودر سایز متوسط',
            'سوند فولی دو راه سیلیکونی سایز ۱۶',
        ];
        for ($i = 0; $i < $count; $i++) {
            $orderId = 12299 + $i;
            $base = 450000 + ($i * 13000);
            $cancelled = $i % 7 === 3;
            $unrecorded = $i % 11 === 5;
            $commission = $unrecorded ? null : (int) round($base * 0.05);
            $this->items->record(new VendorOrderItem(
                0,
                $orderId,
                $orderId,
                5000 + $orderId,
                self::VENDOR,
                $titles[$i % 5],
                'TMC-' . str_pad((string) (100 + $i), 4, '0', STR_PAD_LEFT),
                1 + ($i % 3),
                $base,
                $base,
                (int) round($base * 0.09),
                $commission,
                $commission === null ? null : $base - $commission,
                $unrecorded ? null : ($i % 5 === 2 ? 0 : 500),
                'product',
                $unrecorded ? '' : 'order:' . $orderId . ':item:' . $orderId,
                $cancelled ? OrderItemStatus::Cancelled : OrderItemStatus::Placed,
                '',
                '',
                null,
                $i % 4 === 1 ? 9400 + $i : null,
                5000 + $orderId,
                '',
                'IRT',
                0
            ));
        }
    }
}
