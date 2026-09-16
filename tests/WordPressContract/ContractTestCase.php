<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Contracts\AuditRepositoryInterface;
use Tecteb\Marketplace\Contracts\DependencyProbeInterface;
use Tecteb\Marketplace\Core\Modules\LoadReport;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Tests\Support\FakeDependencyProbe;
use Tecteb\Marketplace\Tests\Support\RecordingAuditRepository;
use TmcWpStubs\State;

/**
 * Base class for WP-STUB contract tests. Loads the stubs itself so the
 * suite works with any bootstrap, resets stub state and the plugin kernel
 * before every test, and always swaps the dependency probe for a fake so
 * "WooCommerce absent/present" never depends on the process's class table.
 */
abstract class ContractTestCase extends TestCase
{
    public const MAIN_FILE = '/srv/www/wp-content/plugins/tecteb-marketplace-core/tecteb-marketplace-core.php';
    public const VERSION = '0.1.0-alpha.15';

    protected RecordingAuditRepository $audit;
    protected FakeDependencyProbe $probe;

    public static function setUpBeforeClass(): void
    {
        if (!class_exists(State::class, false)) {
            require_once dirname(__DIR__, 2) . '/tools/wp-stubs/load.php';
        }
    }

    protected function setUp(): void
    {
        State::reset();
        Bootstrap::reset();
        $this->audit = new RecordingAuditRepository();
        $this->probe = new FakeDependencyProbe(false, null, null, PHP_VERSION, 'stub');
    }

    /** Wires the plugin exactly as the main file does, then fires plugins_loaded. */
    protected function bootPlugin(bool $wooCommerce = false, ?string $wcVersion = null, ?bool $hpos = null): LoadReport
    {
        Bootstrap::init(self::MAIN_FILE, self::VERSION);
        $this->probe->wc = $wooCommerce;
        $this->probe->wcVersion = $wcVersion;
        $this->probe->hpos = $hpos;
        $c = Bootstrap::container();
        $probe = $this->probe;
        $audit = $this->audit;
        $c->bind(DependencyProbeInterface::class, static fn () => $probe);
        $c->bind(AuditRepositoryInterface::class, static fn () => $audit);
        do_action('plugins_loaded');
        $report = Bootstrap::report();
        self::assertNotNull($report);
        return $report;
    }

    /**
     * An administrator with everything this build actually grants.
     *
     * The list comes from `Capabilities::all()` rather than being written out
     * here, and that is not tidiness: a hand-written list goes stale the
     * moment a capability is added, and the symptom is a new admin page
     * refusing to render inside the test harness while working perfectly on a
     * real site. Activation grants the whole list to this role, so the fixture
     * should too.
     */
    protected function loginAdmin(): void
    {
        State::loginAs(1, array_merge(
            ['manage_options', 'activate_plugins'],
            \Tecteb\Marketplace\Core\Lifecycle\Capabilities::all()
        ));
    }

    /** @return string captured output */
    protected function capture(callable $fn): string
    {
        ob_start();
        try {
            $fn();
        } finally {
            $out = (string) ob_get_clean();
        }
        return $out;
    }
}
