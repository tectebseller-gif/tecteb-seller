<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\Otp\OtpProviderInterface;
use Tecteb\Marketplace\Contracts\Otp\OtpSendRequest;
use Tecteb\Marketplace\Contracts\Otp\OtpSendResult;
use Tecteb\Marketplace\Contracts\Otp\OtpStatus;
use Tecteb\Marketplace\Contracts\Otp\OtpVerifyRequest;
use Tecteb\Marketplace\Contracts\Otp\OtpVerifyResult;

/**
 * TEST-ONLY provider (CORE-09): synthetic subjects, injected clock, no
 * network. Lives under tests/ and is asserted absent from the install ZIP
 * (tests/Database/../tools/build.sh check). Never register it at runtime.
 */
final class FakeOtpProvider implements OtpProviderInterface
{
    public const SYNTHETIC_PREFIX = 'synthetic:';
    public const TTL_SECONDS = 120;
    public const MAX_SENDS = 3;

    /** @var array<string, array{code:string, expires:int}> */
    private array $challenges = [];
    /** @var array<string,int> */
    private array $sends = [];

    public function __construct(private ClockInterface $clock)
    {
    }

    public function sendChallenge(OtpSendRequest $request): OtpSendResult
    {
        if (!str_starts_with($request->subjectRef, self::SYNTHETIC_PREFIX)) {
            return new OtpSendResult(OtpStatus::Unavailable);
        }
        $count = ($this->sends[$request->subjectRef] ?? 0) + 1;
        $this->sends[$request->subjectRef] = $count;
        if ($count > self::MAX_SENDS) {
            return new OtpSendResult(OtpStatus::RateLimited, null, 60);
        }
        $id = 'chal-' . count($this->challenges);
        $this->challenges[$id] = [
            'code' => '000000', // synthetic; never a real secret
            'expires' => $this->clock->now()->getTimestamp() + self::TTL_SECONDS,
        ];
        return new OtpSendResult(OtpStatus::Success, $id);
    }

    public function verifyChallenge(OtpVerifyRequest $request): OtpVerifyResult
    {
        $c = $this->challenges[$request->challengeId] ?? null;
        if ($c === null) {
            return new OtpVerifyResult(OtpStatus::Invalid);
        }
        if ($this->clock->now()->getTimestamp() > $c['expires']) {
            return new OtpVerifyResult(OtpStatus::Expired);
        }
        return new OtpVerifyResult(hash_equals($c['code'], $request->code) ? OtpStatus::Success : OtpStatus::Invalid);
    }
}
