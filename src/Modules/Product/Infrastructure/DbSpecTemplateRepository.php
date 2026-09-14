<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Product\Application\SpecTemplateRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\SpecField;
use Tecteb\Marketplace\Modules\Product\Domain\SpecFieldType;
use Tecteb\Marketplace\Modules\Product\Domain\SpecTemplate;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables as T;

/**
 * Category templates and their fields.
 *
 * `deprecateField()` sets a flag; there is no DELETE anywhere in this class,
 * because a field's answers outlive the question (MED-01).
 */
final class DbSpecTemplateRepository implements SpecTemplateRepositoryInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock
    ) {
    }

    public function findByCategory(string $categoryKey): ?SpecTemplate
    {
        $row = $this->db->getRow(
            'SELECT * FROM `' . $this->templates() . '` WHERE category_key = %s',
            [$categoryKey]
        );
        return $row === null ? null : $this->hydrate($row);
    }

    public function find(int $templateId): ?SpecTemplate
    {
        $row = $this->db->getRow('SELECT * FROM `' . $this->templates() . '` WHERE id = %d', [$templateId]);
        return $row === null ? null : $this->hydrate($row);
    }

    public function all(): array
    {
        $rows = $this->db->getResults('SELECT * FROM `' . $this->templates() . '` ORDER BY label ASC, id ASC');
        return array_map([$this, 'hydrate'], $rows);
    }

    public function createTemplate(string $categoryKey, string $label): int
    {
        $now = $this->now();
        $ok = $this->db->execute(
            'INSERT INTO `' . $this->templates() . '` (category_key, label, schema_version, created_at, updated_at)
             VALUES (%s, %s, 1, %s, %s)',
            [$categoryKey, $label, $now, $now]
        );
        if ($ok === null) {
            return 0;
        }
        return (int) $this->db->getVar(
            'SELECT id FROM `' . $this->templates() . '` WHERE category_key = %s',
            [$categoryKey]
        );
    }

    public function renameTemplate(int $templateId, string $label): bool
    {
        return $this->db->execute(
            'UPDATE `' . $this->templates() . '` SET label = %s, updated_at = %s WHERE id = %d',
            [$label, $this->now(), $templateId]
        ) !== null;
    }

    public function addField(int $templateId, SpecField $field): int
    {
        $taken = (int) $this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->fields() . '` WHERE template_id = %d AND field_key = %s',
            [$templateId, $field->key]
        );
        if ($taken > 0) {
            return 0;
        }
        $version = $this->bumpVersion($templateId);
        $ok = $this->db->execute(
            'INSERT INTO `' . $this->fields() . '`
             (template_id, field_key, label, type, required, unit, options, sort_order, deprecated, schema_version, created_at)
             VALUES (%d, %s, %s, %s, %d, %s, %s, %d, 0, %d, %s)',
            [
                $templateId, $field->key, $field->label, $field->type->value, $field->required ? 1 : 0,
                $field->unit, json_encode(array_values($field->options), JSON_UNESCAPED_UNICODE),
                $field->sort, $version, $this->now(),
            ]
        );
        if ($ok === null) {
            return 0;
        }
        return (int) $this->db->getVar(
            'SELECT id FROM `' . $this->fields() . '` WHERE template_id = %d AND field_key = %s',
            [$templateId, $field->key]
        );
    }

    public function updateField(int $fieldId, string $label, bool $required, string $unit, array $options, int $sort): bool
    {
        // Making a field required tightens the shape, so the version moves and
        // products keep the version they were judged under.
        $templateId = (int) $this->db->getVar('SELECT template_id FROM `' . $this->fields() . '` WHERE id = %d', [$fieldId]);
        if ($templateId > 0) {
            $this->bumpVersion($templateId);
        }
        return $this->db->execute(
            'UPDATE `' . $this->fields() . '` SET label = %s, required = %d, unit = %s, options = %s, sort_order = %d WHERE id = %d',
            [$label, $required ? 1 : 0, $unit, json_encode(array_values($options), JSON_UNESCAPED_UNICODE), $sort, $fieldId]
        ) !== null;
    }

    public function deprecateField(int $fieldId): bool
    {
        return $this->setDeprecated($fieldId, true);
    }

    public function restoreField(int $fieldId): bool
    {
        return $this->setDeprecated($fieldId, false);
    }

    public function bumpVersion(int $templateId): int
    {
        $this->db->execute(
            'UPDATE `' . $this->templates() . '` SET schema_version = schema_version + 1, updated_at = %s WHERE id = %d',
            [$this->now(), $templateId]
        );
        return (int) $this->db->getVar('SELECT schema_version FROM `' . $this->templates() . '` WHERE id = %d', [$templateId]);
    }

    private function setDeprecated(int $fieldId, bool $deprecated): bool
    {
        $templateId = (int) $this->db->getVar('SELECT template_id FROM `' . $this->fields() . '` WHERE id = %d', [$fieldId]);
        if ($templateId <= 0) {
            return false;
        }
        $this->bumpVersion($templateId);
        return $this->db->execute(
            'UPDATE `' . $this->fields() . '` SET deprecated = %d WHERE id = %d',
            [$deprecated ? 1 : 0, $fieldId]
        ) !== null;
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): SpecTemplate
    {
        $templateId = (int) $row['id'];
        $fieldRows = $this->db->getResults(
            'SELECT * FROM `' . $this->fields() . '` WHERE template_id = %d ORDER BY sort_order ASC, id ASC',
            [$templateId]
        );
        $fields = [];
        foreach ($fieldRows as $fieldRow) {
            $options = json_decode((string) ($fieldRow['options'] ?? ''), true);
            $fields[] = new SpecField(
                (int) $fieldRow['id'],
                (string) $fieldRow['field_key'],
                (string) $fieldRow['label'],
                SpecFieldType::from((string) $fieldRow['type']),
                (bool) (int) $fieldRow['required'],
                (string) $fieldRow['unit'],
                is_array($options) ? array_values(array_map('strval', $options)) : [],
                (int) $fieldRow['sort_order'],
                (bool) (int) $fieldRow['deprecated'],
                (int) $fieldRow['schema_version']
            );
        }
        return new SpecTemplate(
            $templateId,
            (string) $row['category_key'],
            (string) $row['label'],
            (int) $row['schema_version'],
            $fields
        );
    }

    private function templates(): string
    {
        return T::table($this->db, T::SPEC_TEMPLATES);
    }

    private function fields(): string
    {
        return T::table($this->db, T::SPEC_FIELDS);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
