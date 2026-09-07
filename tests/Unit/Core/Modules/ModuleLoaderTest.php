<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Core\Modules;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Contracts\ModuleKind;
use Tecteb\Marketplace\Contracts\ModuleManifest;
use Tecteb\Marketplace\Contracts\ModuleStatus;
use Tecteb\Marketplace\Core\Container;
use Tecteb\Marketplace\Core\Modules\ModuleLoader;
use Tecteb\Marketplace\Core\Modules\ModuleRegistry;
use Tecteb\Marketplace\Tests\Support\CallLog;
use Tecteb\Marketplace\Tests\Support\SpyModule;

/** CORE-02 plus owner correction 2 (failure containment with recorded reasons). */
final class ModuleLoaderTest extends TestCase
{
    private CallLog $log;
    private ModuleRegistry $registry;

    protected function setUp(): void
    {
        $this->log = new CallLog();
        $this->registry = new ModuleRegistry();
    }

    private function spy(string $id, array $deps = [], ?\Throwable $reg = null, ?\Throwable $boot = null, bool $wc = false): SpyModule
    {
        $m = new SpyModule($id, $deps, $this->log, $reg, $boot, $wc);
        $this->registry->add($m);
        return $m;
    }

    private function load(bool $wc = true)
    {
        return (new ModuleLoader($this->registry))->load(new Container(), $wc);
    }

    public function testRegistersAllThenBootsAllInDependencyOrder(): void
    {
        $this->spy('c', ['b']);
        $this->spy('a');
        $this->spy('b', ['a']);
        $report = $this->load();
        self::assertSame(['register:a', 'register:b', 'register:c', 'boot:a', 'boot:b', 'boot:c'], $this->log->entries);
        foreach (['a', 'b', 'c'] as $id) {
            self::assertSame(ModuleStatus::Active, $report->status($id));
        }
        self::assertFalse($report->hasProblems());
    }

    public function testCycleIsBlockedWithPathWhileIndependentModuleRuns(): void
    {
        $this->spy('a', ['b']);
        $this->spy('b', ['a']);
        $x = $this->spy('x');
        $report = $this->load();
        self::assertSame(ModuleStatus::Blocked, $report->status('a'));
        self::assertSame(ModuleLoader::REASON_CYCLE, $report->state('a')->reasonCode);
        self::assertStringContainsString('→', (string) $report->state('a')->reasonDetail);
        self::assertSame(ModuleStatus::Active, $report->status('x'));
        self::assertSame(1, $x->bootCalls);
        self::assertSame(['register:x', 'boot:x'], $this->log->entries);
    }

    public function testMissingDependencyIsBlockedAndNamed(): void
    {
        $b = $this->spy('b', ['ghost']);
        $report = $this->load();
        self::assertSame(ModuleStatus::Blocked, $report->status('b'));
        self::assertSame(ModuleLoader::REASON_MISSING_DEPENDENCY, $report->state('b')->reasonCode);
        self::assertSame('ghost', $report->state('b')->reasonDetail);
        self::assertSame(0, $b->registerCalls);
    }

    public function testDuplicateIdIsRejectedAndRecorded(): void
    {
        $first = $this->spy('dup');
        $second = new SpyModule('dup', [], $this->log);
        self::assertFalse($this->registry->add($second));
        $report = $this->load();
        self::assertSame([['id' => 'dup', 'reason' => 'duplicate_id']], $report->registryErrors());
        self::assertSame(1, $first->bootCalls);
        self::assertSame(0, $second->bootCalls);
        self::assertTrue($report->hasProblems());
    }

    public function testSecondLoadNeverBootsAgain(): void
    {
        $a = $this->spy('a');
        $loader = new ModuleLoader($this->registry);
        $c = new Container();
        $r1 = $loader->load($c, true);
        $r2 = $loader->load($c, true);
        self::assertSame($r1, $r2);
        self::assertSame(1, $a->registerCalls);
        self::assertSame(1, $a->bootCalls);
    }

    public function testRegisterExceptionDegradesModuleAndBlocksDependents(): void
    {
        $this->spy('a', [], new \RuntimeException("boom\nwith /secret/path/file.php:12"));
        $b = $this->spy('b', ['a']);
        $c = $this->spy('c');
        $report = $this->load();

        self::assertSame(ModuleStatus::Degraded, $report->status('a'));
        self::assertSame('register', $report->state('a')->phase);
        self::assertSame('RuntimeException: boom with /secret/path/file.php:12', $report->state('a')->reasonDetail);
        self::assertStringNotContainsString("\n", (string) $report->state('a')->reasonDetail);

        self::assertSame(ModuleStatus::Blocked, $report->status('b'));
        self::assertSame(ModuleLoader::REASON_DEPENDENCY_FAILED, $report->state('b')->reasonCode);
        self::assertSame('a:degraded (register)', $report->state('b')->reasonDetail);
        self::assertSame(0, $b->registerCalls, 'dependent must not run');
        self::assertSame(0, $b->bootCalls);

        self::assertSame(ModuleStatus::Active, $report->status('c'), 'independent module keeps running');
        self::assertSame(1, $c->bootCalls);
    }

    public function testBootExceptionBlocksDependentsAtBootPhase(): void
    {
        $this->spy('a', [], null, new \LogicException('boot failed'));
        $b = $this->spy('b', ['a']);
        $c = $this->spy('c');
        $report = $this->load();

        self::assertSame(ModuleStatus::Degraded, $report->status('a'));
        self::assertSame('boot', $report->state('a')->phase);
        self::assertSame(ModuleStatus::Blocked, $report->status('b'));
        self::assertSame('boot', $report->state('b')->phase);
        self::assertSame('a:degraded (boot)', $report->state('b')->reasonDetail);
        self::assertSame(1, $b->registerCalls, 'register ran before the dependency failed at boot');
        self::assertSame(0, $b->bootCalls, 'boot must not run');
        self::assertSame(1, $c->bootCalls);
    }

    public function testBlockingIsTransitive(): void
    {
        $this->spy('a', [], new \RuntimeException('x'));
        $this->spy('b', ['a']);
        $c = $this->spy('c', ['b']);
        $report = $this->load();
        self::assertSame(ModuleStatus::Blocked, $report->status('c'));
        self::assertSame('b:blocked (register)', $report->state('c')->reasonDetail);
        self::assertSame(0, $c->registerCalls);
    }

    public function testModuleRequiringWooCommerceIsBlockedWhenAbsentAndRunsWhenPresent(): void
    {
        $m = $this->spy('needs-wc', [], null, null, true);
        $report = $this->load(false);
        self::assertSame(ModuleStatus::Blocked, $report->status('needs-wc'));
        self::assertSame(ModuleLoader::REASON_REQUIRES_WOOCOMMERCE, $report->state('needs-wc')->reasonCode);
        self::assertSame(0, $m->registerCalls);

        $this->registry = new ModuleRegistry();
        $this->log = new CallLog();
        $m2 = $this->spy('needs-wc', [], null, null, true);
        $report2 = $this->load(true);
        self::assertSame(ModuleStatus::Active, $report2->status('needs-wc'));
        self::assertSame(1, $m2->bootCalls);
    }

    public function testPlannedManifestsAreNeverRunAndCannotCarryCode(): void
    {
        $planned = new ModuleManifest('vendor', '0.0.0', 'فروشندگان', ModuleKind::Planned, [], true);
        self::assertTrue($this->registry->addPlanned($planned));
        $codeAsPlanned = new SpyModule('product', [], $this->log, null, null, false, ModuleKind::Planned);
        self::assertFalse($this->registry->add($codeAsPlanned), 'code cannot hide behind a planned status');
        $dependsOnPlanned = $this->spy('order', ['vendor']);
        $report = $this->load();
        self::assertSame(ModuleStatus::Planned, $report->status('vendor'));
        self::assertSame(ModuleStatus::Blocked, $report->status('order'));
        self::assertSame(ModuleLoader::REASON_MISSING_DEPENDENCY, $report->state('order')->reasonCode);
        self::assertSame(0, $dependsOnPlanned->bootCalls);
        self::assertSame([], $this->log->entries);
        self::assertContains(['id' => 'product', 'reason' => 'planned_with_code'], $report->registryErrors());
    }

    public function testHealthShapeIsIdAndStatusOnly(): void
    {
        $this->spy('a');
        $this->registry->addPlanned(new ModuleManifest('vendor', '0', 'v', ModuleKind::Planned));
        $health = $this->load()->forHealth();
        self::assertSame([['id' => 'a', 'status' => 'active'], ['id' => 'vendor', 'status' => 'planned']], $health);
    }
}
