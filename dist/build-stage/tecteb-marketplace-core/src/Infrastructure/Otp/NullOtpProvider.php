<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Infrastructure\Otp;

use Tecteb\Marketplace\Contracts\Otp\OtpProviderInterface;
use Tecteb\Marketplace\Contracts\Otp\OtpSendRequest;
use Tecteb\Marketplace\Contracts\Otp\OtpSendResult;
use Tecteb\Marketplace\Contracts\Otp\OtpStatus;
use Tecteb\Marketplace\Contracts\Otp\OtpVerifyRequest;
use Tecteb\Marketplace\Contracts\Otp\OtpVerifyResult;

/**
 * The only provider shipped in the ZIP (CORE-09 / OTP-01): every call is
 * Unavailable. No network, no challenge, no session, no cookie, no code.
 */
final class NullOtpProvider implements OtpProviderInterface
{
    public function sendChallenge(OtpSendRequest $request): OtpSendResult
    {
        return new OtpSendResult(OtpStatus::Unavailable, null, null);
    }

    public function verifyChallenge(OtpVerifyRequest $request): OtpVerifyResult
    {
        return new OtpVerifyResult(OtpStatus::Unavailable);
    }
}
