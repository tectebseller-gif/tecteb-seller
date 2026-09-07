<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Infrastructure\Otp;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Contracts\Otp\OtpSendRequest;
use Tecteb\Marketplace\Contracts\Otp\OtpStatus;
use Tecteb\Marketplace\Contracts\Otp\OtpVerifyRequest;
use Tecteb\Marketplace\Core\Support\FixedClock;
use Tecteb\Marketplace\Infrastructure\Otp\NullOtpProvider;
use Tecteb\Marketplace\Tests\Support\FakeOtpProvider;

/** CORE-09: NullProvider never succeeds; FakeProvider is test-only with synthetic data and a fixed clock. */
final class OtpProvidersTest extends TestCase
{
    public function testNullProviderIsUnavailableForEveryInput(): void
    {
        $p = new NullOtpProvider();
        foreach (['login', 'staff_invite', ''] as $purpose) {
            foreach (['synthetic:1', 'user:42', '', '0000'] as $subject) {
                $send = $p->sendChallenge(new OtpSendRequest($purpose, $subject));
                self::assertSame(OtpStatus::Unavailable, $send->status);
                self::assertNull($send->challengeId);
                self::assertFalse($send->isSuccess());
            }
        }
        foreach (['', '000000', 'anything', 'chal-0'] as $code) {
            $verify = $p->verifyChallenge(new OtpVerifyRequest('chal-0', $code));
            self::assertSame(OtpStatus::Unavailable, $verify->status);
            self::assertFalse($verify->isSuccess());
        }
        self::assertSame([], $_COOKIE, 'no cookie side effects');
    }

    public function testStatusesAreDistinct(): void
    {
        self::assertCount(5, array_unique(array_map(static fn (OtpStatus $s) => $s->value, OtpStatus::cases())));
    }

    public function testFakeProviderOnlyAcceptsSyntheticSubjectsAndHonoursClock(): void
    {
        $clock = new FixedClock();
        $fake = new FakeOtpProvider($clock);
        self::assertSame(OtpStatus::Unavailable, $fake->sendChallenge(new OtpSendRequest('login', 'user:1'))->status);
        $send = $fake->sendChallenge(new OtpSendRequest('login', 'synthetic:1'));
        self::assertSame(OtpStatus::Success, $send->status);
        self::assertSame(OtpStatus::Invalid, $fake->verifyChallenge(new OtpVerifyRequest($send->challengeId, '999999'))->status);
        $clock->advance(FakeOtpProvider::TTL_SECONDS + 1);
        self::assertSame(OtpStatus::Expired, $fake->verifyChallenge(new OtpVerifyRequest($send->challengeId, '000000'))->status);
        $fake->sendChallenge(new OtpSendRequest('login', 'synthetic:1'));
        $fake->sendChallenge(new OtpSendRequest('login', 'synthetic:1'));
        self::assertSame(OtpStatus::RateLimited, $fake->sendChallenge(new OtpSendRequest('login', 'synthetic:1'))->status);
    }

    public function testFakeProviderIsNotPartOfTheShippedSource(): void
    {
        $src = dirname(__DIR__, 4) . '/src';
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src));
        foreach ($it as $file) {
            if ($file->isFile() && preg_match('/Fake|Stub|Mock/i', $file->getFilename()) === 1) {
                $found[] = $file->getPathname();
            }
        }
        self::assertSame([], $found, 'no fake/stub/mock provider under src/');
    }
}
