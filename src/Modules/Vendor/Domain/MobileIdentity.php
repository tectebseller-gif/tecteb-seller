<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Domain;

/**
 * A mobile number on file, and whether anything ever PROVED it.
 *
 * There is no setter that marks it verified: the only constructor argument
 * that can do so is a provider's verification timestamp, and no provider in
 * this build returns one (NullOtpProvider). A number typed into a form is
 * `registered_unverified` and shows as that everywhere (plan §4.1).
 */
final class MobileIdentity
{
    private function __construct(
        public readonly string $number,
        public readonly ?string $verifiedAt,
        public readonly string $verifiedBy
    ) {
    }

    public static function registered(string $number): self
    {
        return new self($number, null, '');
    }

    /**
     * Only a real provider result may call this — see
     * Modules\Vendor\Application\MobileVerification, which refuses unless the
     * provider actually returned a verified challenge.
     */
    public static function verifiedByProvider(string $number, string $at, string $providerId): self
    {
        if (trim($at) === '' || trim($providerId) === '') {
            throw new VendorDomainException('a verified identity needs both a timestamp and the provider that issued it');
        }
        return new self($number, $at, $providerId);
    }

    public function isVerified(): bool
    {
        return $this->verifiedAt !== null;
    }
}
