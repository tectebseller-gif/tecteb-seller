<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * A category's medical template: its fields, and the version of its shape.
 *
 * The version is bumped whenever a field is added, retired or made required,
 * so a product can record WHICH shape it was filled against (MED-01:
 * «محصول/تنوع و الگو باید version snapshot قابل مقایسه داشته باشند»). Without
 * that, tightening a rule silently re-judges every product ever saved.
 */
final class SpecTemplate
{
    /** @param list<SpecField> $fields */
    public function __construct(
        public readonly int $id,
        public readonly string $categoryKey,
        public readonly string $label,
        public readonly int $schemaVersion,
        private readonly array $fields = []
    ) {
    }

    /** @return list<SpecField> only the fields still being asked */
    public function askedFields(): array
    {
        return array_values(array_filter($this->fields, static fn (SpecField $f): bool => $f->isAsked()));
    }

    /** @return list<SpecField> everything, including retired fields */
    public function allFields(): array
    {
        return $this->fields;
    }

    public function field(string $key): ?SpecField
    {
        foreach ($this->fields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }
        return null;
    }

    public function isEmpty(): bool
    {
        return $this->askedFields() === [];
    }

    /**
     * Validates one product's answers.
     *
     * Retired fields are never demanded and never rejected — a product may
     * still carry an answer to a question nobody asks any more, and throwing
     * it away is exactly what MED-01 forbids.
     *
     * @param array<string,string> $values
     * @return array{missing:list<string>, invalid:list<string>}
     */
    public function validate(array $values): array
    {
        $missing = [];
        $invalid = [];
        foreach ($this->askedFields() as $field) {
            $value = trim($values[$field->key] ?? '');
            if ($field->required && $value === '') {
                $missing[] = $field->label;
                continue;
            }
            if (!$field->type->accepts($value, $field->options)) {
                $invalid[] = $field->label;
            }
        }
        return ['missing' => $missing, 'invalid' => $invalid];
    }
}
