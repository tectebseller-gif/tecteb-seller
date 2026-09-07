<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts\Otp;

final class OtpVerifyRequest
{
    public function __construct(
        public readonly string $challengeId,
        public readonly string $code
    ) {
    }
}
