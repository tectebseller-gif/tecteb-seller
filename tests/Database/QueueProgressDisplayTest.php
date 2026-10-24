<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Contracts\JobRepositoryInterface;
use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Migration\Application\DokanRecordImportJob;
use Tecteb\Marketplace\Modules\Operations\Presentation\Admin\QueuePage;

/**
 * §3: the record import's phase has a name while it runs, and a full bar on a
 * live job does not read as a finished one.
 *
 * ⛔ **Both defects were on the owner's screen at once.** The `dokan_records`
 * job showed «مرحلهٔ نامعلوم» on every batch — its cursor named the phase, and
 * the queue page's `match` simply did not know the record import's three keys
 * — while the counter sat at a hundred per cent with two kinds still to walk,
 * because `Job::progress()` clamps and the declared ceiling counts one kind.
 *
 * Rendered, not asserted on the message helper: «رندر کردن یک صفحه دربارهٔ
 * تصمیم‌هایش هیچ نمی‌گوید» cuts the other way too — a helper that returns the
 * right word proves nothing about the page that was supposed to print it.
 */
final class QueueProgressDisplayTest extends DatabaseTestCase
{
    private const MANAGER = 1;
    private const TOTAL = 4;

    private JobRepositoryInterface $jobs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPlugin(true);
        $this->loginAdmin();
        $this->resetSchema(new WpDatabase($this->wpdb));
        Bootstrap::container()->get(MigrationRunner::class)->run();
        /** @var JobRepositoryInterface $jobs */
        $jobs = Bootstrap::container()->get(JobRepositoryInterface::class);
        $this->jobs = $jobs;
    }

    /**
     * A live record-import job, mid-walk, with its counter already at the
     * declared ceiling.
     *
     * The state is WRITTEN rather than produced by running the import,
     * because the import would have to be made to stop in exactly this
     * position and a fixture that does work to reach a state gives that state
     * back (`alpha.72`). Here the subject is the display, and the row is the
     * display's whole input.
     */
    private function seedLiveJobAtTheCeiling(string $phase): void
    {
        $queued = $this->jobs->enqueue(
            DokanRecordImportJob::TYPE,
            ['run_id' => 'queue-display', 'total' => self::TOTAL],
            DokanRecordImportJob::TYPE . ':queue-display',
            self::MANAGER
        );
        $id = (int) ($queued['id'] ?? 0);
        self::assertGreaterThan(0, $id, 'the job must be queued');

        $token = bin2hex(random_bytes(8));
        $job = $this->jobs->claim([DokanRecordImportJob::TYPE], $token, 300);
        self::assertNotNull($job, 'the fixture must hold a live lease');

        // Counted up to the ceiling, and still inside the second kind: the
        // exact pair the owner saw.
        self::assertTrue($this->jobs->checkpoint(
            $id,
            $token,
            ['phase' => $phase, 'after' => 7, 'tries' => 0],
            self::TOTAL,
            0,
            self::TOTAL,
            300
        ), 'the checkpoint must land');

        $after = $this->jobs->find($id);
        self::assertNotNull($after);
        self::assertTrue($after->status->isLive(), 'the job under test is still running');
        self::assertSame(100, $after->progress(), 'the bar is full while the work is not');
    }

    private function render(): string
    {
        $_GET['page'] = QueuePage::SLUG;
        return $this->capture(static function (): void {
            (new QueuePage(Bootstrap::container()))->render();
        });
    }

    /** The phase is named, and «نامعلوم» is not what the row says. */
    public function testTheRecordImportPhaseIsNamedWhileItRuns(): void
    {
        $this->seedLiveJobAtTheCeiling(DokanRecordImportJob::PHASE_BALANCE);

        $html = $this->render();

        self::assertStringContainsString('مرحلهٔ دفتر مانده', $html, 'the balance phase has a name');
        // ⛔ The needle is the whole phrase, not the bare word: «نامعلوم» is a
        // `match` default that other helpers on this page share, so asserting
        // the word's absence would be asserting something about them.
        self::assertStringNotContainsString('مرحلهٔ نامعلوم', $html);
    }

    /** @dataProvider recordPhases */
    public function testEachRecordPhaseIsNamedOnThePage(string $phase, string $label): void
    {
        $this->seedLiveJobAtTheCeiling($phase);

        self::assertStringContainsString('مرحلهٔ ' . $label, $this->render());
    }

    /** @return array<string, array{0:string, 1:string}> */
    public static function recordPhases(): array
    {
        return [
            'staff' => [DokanRecordImportJob::PHASE_STAFF, 'پرسنل فروشگاه'],
            'balance' => [DokanRecordImportJob::PHASE_BALANCE, 'دفتر مانده'],
            'withdrawals' => [DokanRecordImportJob::PHASE_WITHDRAWALS, 'درخواست‌های برداشت'],
        ];
    }

    /**
     * A full bar on a live job says the ceiling was reached, not that the job
     * is over.
     *
     * The ceiling is `vendors + products` from the day it was written and the
     * counter counts all three kinds, so «۱۰۰٪» is a statement about a number
     * the queue declared — never about the phases left to walk.
     */
    public function testAFullBarOnALiveJobDoesNotReadAsFinished(): void
    {
        $this->seedLiveJobAtTheCeiling(DokanRecordImportJob::PHASE_BALANCE);

        $html = $this->render();

        self::assertStringContainsString('به سقف اعلام‌شده رسید، ولی کار هنوز در جریان است', $html);
        self::assertStringContainsString('سقف پایانِ مرحله‌ها را نمی‌گوید', $html);
    }
}
