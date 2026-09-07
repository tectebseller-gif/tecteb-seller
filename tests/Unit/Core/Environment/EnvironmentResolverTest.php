<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Core\Environment;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Core\Environment\EnvironmentResolver;
use Tecteb\Marketplace\Core\Environment\EnvironmentType;
use Tecteb\Marketplace\Core\Environment\OutboundPolicy;
use Tecteb\Marketplace\Core\Environment\ResolvedEnvironment;
use Tecteb\Marketplace\Core\Exceptions\OutboundBlockedException;
use Tecteb\Marketplace\Tests\Support\FakeEnvironmentProbe;

/** CORE-04: constant → option → platform; unknown stays unknown; outbound never opens. */
final class EnvironmentResolverTest extends TestCase
{
    private function resolve(?string $constant, ?string $option, ?string $platform, ?string $host = null): ResolvedEnvironment
    {
        return (new EnvironmentResolver(new FakeEnvironmentProbe($constant, $option, $platform, $host)))->resolve();
    }

    public function testValidConstantWins(): void
    {
        $r = $this->resolve('staging', 'production', 'production');
        self::assertSame(EnvironmentType::Staging, $r->type);
        self::assertSame('constant', $r->source);
        self::assertSame(['resolved' => 'staging', 'source' => 'constant'], $r->toArray());
    }

    public function testInvalidConstantIsWarnedAndSkipped(): void
    {
        $r = $this->resolve('prod-ish', 'staging', 'production');
        self::assertSame(EnvironmentType::Staging, $r->type);
        self::assertSame('option', $r->source);
        self::assertContains('constant_invalid', $r->warnings);
    }

    public function testProductionOptionOnStagingPlatformAndViceVersa(): void
    {
        $a = $this->resolve(null, 'production', 'staging');
        self::assertSame(EnvironmentType::Production, $a->type);
        self::assertSame('option', $a->source);
        $b = $this->resolve(null, 'staging', 'production');
        self::assertSame(EnvironmentType::Staging, $b->type);
        // Neither resolution unlocks anything:
        self::assertFalse(OutboundPolicy::isTmcOutboundAllowed($a));
        self::assertFalse(OutboundPolicy::isTmcOutboundAllowed($b));
    }

    public function testAutoOptionFallsThroughToPlatform(): void
    {
        $r = $this->resolve(null, 'auto', 'development');
        self::assertSame(EnvironmentType::Development, $r->type);
        self::assertSame('platform', $r->source);
        self::assertSame([], $r->warnings);
    }

    public function testInvalidOptionIsWarnedAndSkipped(): void
    {
        $r = $this->resolve(null, 'local', 'production'); // option may only be staging/production
        self::assertSame(EnvironmentType::Production, $r->type);
        self::assertSame('platform', $r->source);
        self::assertContains('option_invalid', $r->warnings);
    }

    public function testUnknownPlatformValueStaysUnknown(): void
    {
        $r = $this->resolve(null, null, 'weird');
        self::assertSame(EnvironmentType::Unknown, $r->type);
        self::assertSame('platform', $r->source);
        self::assertContains('platform_unknown', $r->warnings);
    }

    public function testNoSignalsAtAllIsUnknownNone(): void
    {
        $r = $this->resolve(null, null, null);
        self::assertSame(EnvironmentType::Unknown, $r->type);
        self::assertSame('none', $r->source);
    }

    public function testHostnameIsOnlyAHint(): void
    {
        $r = $this->resolve(null, null, 'production', 'staging.example.test');
        self::assertSame(EnvironmentType::Production, $r->type, 'hostname never changes the resolution');
        self::assertSame('looks_non_production', $r->hostnameHint);
        self::assertSame('no_signal', $this->resolve(null, null, 'production', 'shop.example')->hostnameHint);
    }

    public function testOutboundIsBlockedForEveryEnvironmentAndAssertThrows(): void
    {
        foreach (EnvironmentType::cases() as $type) {
            $env = new ResolvedEnvironment($type, 'constant');
            self::assertFalse(OutboundPolicy::isTmcOutboundAllowed($env), $type->value);
        }
        self::assertSame(['tmc' => 'blocked', 'other_plugins' => 'unknown'], OutboundPolicy::toArray());
        $this->expectException(OutboundBlockedException::class);
        OutboundPolicy::assertAllowed(new ResolvedEnvironment(EnvironmentType::Production, 'option'), 'sms');
    }
}
