<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts\Otp;

/**
 * Independent OTP contract (CORE-09 / OTP-01). Gateway adapters may only be
 * written against a documented public API; none exists in phase 1.
 */
interface OtpProviderInterface
{
    public function sendChallenge(OtpSendRequest $request): OtpSendResult;

    public function verifyChallenge(OtpVerifyRequest $request): OtpVerifyResult;
}
