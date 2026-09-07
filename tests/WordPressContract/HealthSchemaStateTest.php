<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Core\Migration\SchemaVersion;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Admin\Presentation\Messages;
use Tecteb\Marketplace\Modules\Health\Application\HealthReportBuilder;
use Tecteb\Marketplace\Modules\Health\Application\HealthStatus;
use TmcWpStubs\State;

/**
 * The health page and the recovery path must agree about a recorded migration
 * failure (CORE-01/06).
 *
 * A failure record whose schema has since reached the target describes a
 * failure that is OVER: the run that recorded it was superseded by one that
 * completed, and only the removal of the record failed. Treating that as a
 * broken schema was wrong twice over — it reported a problem that no longer
 * existed, and because no migration was pending any more, nothing would ever
 * clear it: the page stayed red forever with no way out.
 */
final class HealthSchemaStateTest extends ContractTestCase
{
    private const RECORD = ['step' => '0002_step', 'message' => 'DDL failed', 'at' => '2026-09-07T10:00:00+00:00'];

    /** @return array{status:HealthStatus,facts:array<string,mixed>} */
    private function schemaCheck(): array
    {
        $report = Bootstrap::container()->get(HealthReportBuilder::class)->build();
        foreach ($report->checks as $check) {
            if ($check->key === 'schema') {
                return ['status' => $check->status, 'facts' => $check->facts];
            }
        }
        self::fail('the health report has no schema check');
    }

    public function testARecordedFailureWithAnIncompleteSchemaIsActionRequired(): void
    {
        $this->bootPlugin(false);
        State::$options[SchemaVersion::OPTION] = 0;
        State::$options[SchemaVersion::LAST_ERROR_OPTION] = self::RECORD;

        $check = $this->schemaCheck();

        self::assertSame(HealthStatus::ActionRequired, $check['status']);
        self::assertFalse($check['facts']['last_error_stale'], 'the migration really is incomplete');
        self::assertStringContainsString('آخرین migration ناموفق بود', Messages::healthDescription('schema', $check['status'], $check['facts']));
    }

    public function testARecordedFailureWithTheSchemaAtTargetIsStaleNotBroken(): void
    {
        $this->bootPlugin(false);
        State::$options[SchemaVersion::OPTION] = SchemaVersion::TARGET;
        State::$options[SchemaVersion::LAST_ERROR_OPTION] = self::RECORD;

        $check = $this->schemaCheck();

        self::assertSame(HealthStatus::Healthy, $check['status'], 'the schema is complete; the record is history');
        self::assertTrue($check['facts']['last_error_stale']);
        $text = Messages::healthDescription('schema', $check['status'], $check['facts']);
        self::assertStringContainsString('ساختار داده کامل است', $text);
        self::assertStringContainsString('رکورد خطای قدیمی', $text);
        self::assertStringContainsString('فعال‌سازی دوباره', $text, 'the page names the recovery path');
        self::assertStringNotContainsString('آخرین migration ناموفق بود', $text, 'a finished failure is not reported as the current state');
    }

    public function testASchemaAheadOfThisBuildStaysActionRequired(): void
    {
        $this->bootPlugin(false);
        State::$options[SchemaVersion::OPTION] = SchemaVersion::TARGET + 5;

        $check = $this->schemaCheck();

        self::assertSame(HealthStatus::ActionRequired, $check['status']);
        self::assertTrue($check['facts']['ahead']);
    }
}
