<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Domain;

/**
 * What the applicant typed. Plain data: sanitising belongs to the caller and
 * escaping to the view, so this object is safe to store and to compare.
 */
final class ApplicantDetails
{
    public function __construct(
        public readonly string $storeName = '',
        public readonly string $legalName = '',
        public readonly string $contactEmail = '',
        public readonly string $contactMobile = '',
        public readonly string $address = '',
        public readonly bool $termsAccepted = false
    ) {
    }

    /**
     * Fields the form needs before it can be submitted. Documents are NOT
     * here: they have their own rule set (RequirementSet).
     *
     * @return list<string> field keys that are still empty
     */
    public function missingFields(): array
    {
        $missing = [];
        foreach ([
            'store_name' => $this->storeName,
            'legal_name' => $this->legalName,
            'contact_email' => $this->contactEmail,
            'contact_mobile' => $this->contactMobile,
            'address' => $this->address,
        ] as $key => $value) {
            if (trim($value) === '') {
                $missing[] = $key;
            }
        }
        if (!$this->termsAccepted) {
            $missing[] = 'terms';
        }
        return $missing;
    }

    public function isComplete(): bool
    {
        return $this->missingFields() === [];
    }

    /** @return array<string,scalar> */
    public function toRow(): array
    {
        return [
            'store_name' => $this->storeName,
            'legal_name' => $this->legalName,
            'contact_email' => $this->contactEmail,
            'contact_mobile' => $this->contactMobile,
            'address' => $this->address,
            'terms_accepted' => $this->termsAccepted ? 1 : 0,
        ];
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) ($row['store_name'] ?? ''),
            (string) ($row['legal_name'] ?? ''),
            (string) ($row['contact_email'] ?? ''),
            (string) ($row['contact_mobile'] ?? ''),
            (string) ($row['address'] ?? ''),
            (bool) ($row['terms_accepted'] ?? false)
        );
    }
}
