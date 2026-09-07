<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts\Otp;

/**
 * Distinct outcomes (CORE-09). A provider MUST NOT collapse these.
 */
enum OtpStatus: string
{
    case Unavailable = 'unavailable';
    case Invalid = 'invalid';
    case Expired = 'expired';
    case RateLimited = 'rate_limited';
    case Success = 'success';
}
