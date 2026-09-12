<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Domain;

/**
 * The document types in force, plus the manager's explicit "none needed"
 * switch. Answers the one question the application form keeps asking: may
 * this application be submitted yet, and if not, why not?
 */
final class RequirementSet
{
    /** @param list<DocumentType> $types */
    public function __construct(
        private readonly array $types,
        private readonly bool $explicitlyNone
    ) {
    }

    /** @return list<DocumentType> */
    public function types(): array
    {
        return $this->types;
    }

    public function mode(): RequirementMode
    {
        if ($this->types !== []) {
            return RequirementMode::Configured;
        }
        return $this->explicitlyNone ? RequirementMode::ExplicitlyNone : RequirementMode::Undefined;
    }

    public function typeBySlug(string $slug): ?DocumentType
    {
        foreach ($this->types as $type) {
            if ($type->slug === $slug) {
                return $type;
            }
        }
        return null;
    }

    /**
     * Required types with nothing uploaded against them.
     *
     * @param list<VendorDocument> $uploaded
     * @return list<DocumentType>
     */
    public function missingRequired(array $uploaded): array
    {
        $have = [];
        foreach ($uploaded as $doc) {
            $have[$doc->typeSlug] = true;
        }
        $missing = [];
        foreach ($this->types as $type) {
            if ($type->required && !isset($have[$type->slug])) {
                $missing[] = $type;
            }
        }
        return $missing;
    }

    /**
     * @param list<VendorDocument> $uploaded
     */
    public function blocksSubmission(array $uploaded): bool
    {
        return $this->mode() === RequirementMode::Undefined || $this->missingRequired($uploaded) !== [];
    }
}
