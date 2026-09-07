<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Core\Autoloader;

/** ADR-001 tests T1–T4 (T5/T6 live in the architecture suite). */
final class AutoloaderTest extends TestCase
{
    private function loader(): Autoloader
    {
        return Autoloader::register(dirname(__DIR__, 3) . '/src');
    }

    public function testT1MapsOwnClassToPsr4Path(): void
    {
        $expected = realpath(dirname(__DIR__, 3) . '/src') . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Container.php';
        self::assertSame($expected, realpath($this->loader()->resolve('Tecteb\\Marketplace\\Core\\Container')));
        self::assertStringEndsWith('Modules/Health/HealthModule.php', str_replace('\\', '/', $this->loader()->resolve('Tecteb\\Marketplace\\Modules\\Health\\HealthModule')));
    }

    public function testT2ForeignPrefixesAreIgnored(): void
    {
        foreach (['Composer\\Autoload\\ClassLoader', 'WC_Order', 'WeDevs\\Dokan\\Foo', 'Tecteb\\Other\\Thing', 'TectebX\\Marketplace\\Y'] as $class) {
            self::assertNull($this->loader()->resolve($class), $class);
        }
    }

    public function testT3MissingOwnClassDoesNotError(): void
    {
        self::assertFalse(class_exists('Tecteb\\Marketplace\\Nope\\Missing', true));
        self::assertNull($this->loader()->resolve('Tecteb\\Marketplace\\..\\evil'));
    }

    public function testT4RegistrationIsIdempotentAndAppended(): void
    {
        $a = Autoloader::register('/tmp/ignored-on-second-call');
        $b = Autoloader::register('/tmp/ignored-again');
        self::assertSame($a, $b);
        $ours = array_filter(spl_autoload_functions(), static fn ($f) => is_array($f) && $f[0] instanceof Autoloader);
        self::assertCount(1, $ours, 'exactly one registration of the plugin autoloader');
        self::assertNotNull($a->resolve('Tecteb\\Marketplace\\Core\\Kernel'), 'base dir of the first registration is kept');
    }
}
