<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts\Otp;

final class OtpSendRequest
{
    /**
     * @param string $purpose   e.g. "login", "staff_invite" (no behaviour attached in phase 1)
     * @param string $subjectRef pseudonymous account reference (never a raw phone number in logs)
     */
    public function __construct(
        public readonly string $purpose,
        public readonly string $subjectRef
    ) {
    }
}
