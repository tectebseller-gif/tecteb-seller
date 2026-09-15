<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts\Otp;

final class OtpSendResult
{
    public function __construct(
        public readonly OtpStatus $status,
        public readonly ?string $challengeId = null,
        public readonly ?int $retryAfterSeconds = null
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->status === OtpStatus::Success;
    }
}
