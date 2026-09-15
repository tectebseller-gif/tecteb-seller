<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts\Otp;

final class OtpVerifyResult
{
    public function __construct(public readonly OtpStatus $status)
    {
    }

    public function isSuccess(): bool
    {
        return $this->status === OtpStatus::Success;
    }
}
