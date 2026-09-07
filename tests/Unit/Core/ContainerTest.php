<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Core\Container;
use Tecteb\Marketplace\Core\Exceptions\ContainerException;

final class ContainerTest extends TestCase
{
    public function testBindIsSharedAndLazy(): void
    {
        $c = new Container();
        $calls = 0;
        $c->bind('svc', static function () use (&$calls) {
            $calls++;
            return new \stdClass();
        });
        self::assertSame(0, $calls);
        self::assertSame($c->get('svc'), $c->get('svc'));
        self::assertSame(1, $calls);
        self::assertTrue($c->has('svc'));
    }

    public function testInstanceAndUnknown(): void
    {
        $c = new Container();
        $o = new \stdClass();
        $c->instance('o', $o);
        self::assertSame($o, $c->get('o'));
        $this->expectException(ContainerException::class);
        $c->get('missing');
    }

    public function testCircularFactoryIsDetected(): void
    {
        $c = new Container();
        $c->bind('a', static fn (Container $c) => $c->get('b'));
        $c->bind('b', static fn (Container $c) => $c->get('a'));
        $this->expectException(ContainerException::class);
        $c->get('a');
    }
}
