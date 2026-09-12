<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Application;

use Tecteb\Marketplace\Contracts\Otp\OtpProviderInterface;
use Tecteb\Marketplace\Contracts\Otp\OtpSendRequest;
use Tecteb\Marketplace\Contracts\Otp\OtpStatus;
use Tecteb\Marketplace\Modules\Vendor\Domain\MobileIdentity;

/**
 * The only door to a verified mobile number — and in this build it does not
 * open.
 *
 * `NullOtpProvider` answers `unavailable` to everything, so `available()` is
 * false, the UI shows «ثبت‌شده، تأییدنشده», and nothing in the codebase can
 * produce a verified identity. When a real gateway arrives (owner's
 * tecteb-gateway-reference.zip), only the provider binding changes; this
 * class and its rule stay as they are.
 */
final class MobileVerification
{
    public function __construct(private readonly OtpProviderInterface $provider)
    {
    }

    /** Whether a challenge could even be sent right now. */
    public function available(): bool
    {
        $result = $this->provider->sendChallenge(new OtpSendRequest('vendor_identity', 'probe'));
        return $result->status !== OtpStatus::Unavailable;
    }

    /**
     * Reads the stored number as an identity. Storage never carries a
     * verification timestamp, because nothing can write one yet.
     */
    public function identityFor(string $number): MobileIdentity
    {
        return MobileIdentity::registered($number);
    }

    public function reasonUnavailable(): string
    {
        return 'no_provider';
    }
}
