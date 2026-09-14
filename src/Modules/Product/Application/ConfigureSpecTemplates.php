<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Product\Domain\SpecField;
use Tecteb\Marketplace\Modules\Product\Domain\SpecFieldType;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;

/**
 * The manager's medical form builder (§6.1, MED-01).
 *
 * What this class refuses to do is as important as what it does: there is no
 * "delete field", because the answers already given to a question must
 * survive it; and a field's KEY is fixed at creation, because renaming a key
 * is indistinguishable from losing every answer stored under the old one.
 */
final class ConfigureSpecTemplates
{
    /** Guards a pathological template rather than a real one. */
    public const MAX_FIELDS = 60;
    public const MAX_OPTIONS = 40;

    public function __construct(
        private readonly SpecTemplateRepositoryInterface $templates,
        private readonly AuditLogger $audit,
        private readonly CapabilityCheckerInterface $capabilities
    ) {
    }

    public function createTemplate(string $categoryKey, string $label): OperationResult
    {
        if (!$this->capabilities->can(Capabilities::MANAGE_SPEC_TEMPLATES)) {
            return OperationResult::failure('forbidden');
        }
        $categoryKey = $this->normaliseKey($categoryKey);
        if ($categoryKey === '' || trim($label) === '') {
            return OperationResult::failure('incomplete_template');
        }
        if ($this->templates->findByCategory($categoryKey) !== null) {
            return OperationResult::failure('template_exists', ['category' => $categoryKey]);
        }
        $id = $this->templates->createTemplate($categoryKey, trim($label));
        if ($id <= 0) {
            return OperationResult::failure('storage_failed');
        }
        $this->logChange('template_created', $categoryKey, '', 1);
        return OperationResult::success('template_created', ['template_id' => $id]);
    }

    public function renameTemplate(int $templateId, string $label): OperationResult
    {
        if (!$this->capabilities->can(Capabilities::MANAGE_SPEC_TEMPLATES)) {
            return OperationResult::failure('forbidden');
        }
        if (trim($label) === '') {
            return OperationResult::failure('incomplete_template');
        }
        $template = $this->templates->find($templateId);
        if ($template === null) {
            return OperationResult::failure('not_found');
        }
        if (!$this->templates->renameTemplate($templateId, trim($label))) {
            return OperationResult::failure('storage_failed');
        }
        $this->logChange('template_renamed', $template->categoryKey, '', $template->schemaVersion);
        return OperationResult::success('template_saved');
    }

    /** @param list<string> $options */
    public function addField(
        int $templateId,
        string $key,
        string $label,
        string $type,
        bool $required,
        string $unit = '',
        array $options = [],
        int $sort = 0
    ): OperationResult {
        if (!$this->capabilities->can(Capabilities::MANAGE_SPEC_TEMPLATES)) {
            return OperationResult::failure('forbidden');
        }
        $template = $this->templates->find($templateId);
        if ($template === null) {
            return OperationResult::failure('not_found');
        }
        if (count($template->allFields()) >= self::MAX_FIELDS) {
            return OperationResult::failure('too_many_fields', ['limit' => self::MAX_FIELDS]);
        }
        $key = $this->normaliseKey($key);
        if ($key === '' || trim($label) === '') {
            return OperationResult::failure('incomplete_field');
        }
        $fieldType = SpecFieldType::tryFrom($type);
        if ($fieldType === null) {
            return OperationResult::failure('bad_field_type');
        }
        if ($template->field($key) !== null) {
            return OperationResult::failure('field_key_taken', ['field' => $key]);
        }
        $options = $this->cleanOptions($options);
        if ($fieldType === SpecFieldType::Choice && $options === []) {
            return OperationResult::failure('choice_needs_options');
        }
        $field = new SpecField(0, $key, trim($label), $fieldType, $required, trim($unit), $options, $sort);
        $id = $this->templates->addField($templateId, $field);
        if ($id <= 0) {
            return OperationResult::failure('storage_failed');
        }
        $version = $this->templates->find($templateId)?->schemaVersion ?? 0;
        $this->logChange('field_added', $template->categoryKey, $key, $version);
        return OperationResult::success('field_added', ['field_id' => $id, 'schema_version' => $version]);
    }

    /**
     * The label, the unit, the options and whether the field is required. The
     * key and the type are absent on purpose: changing either would reinterpret
     * answers that were given under the old meaning.
     *
     * @param list<string> $options
     */
    public function updateField(
        int $templateId,
        int $fieldId,
        string $label,
        bool $required,
        string $unit = '',
        array $options = [],
        int $sort = 0
    ): OperationResult {
        if (!$this->capabilities->can(Capabilities::MANAGE_SPEC_TEMPLATES)) {
            return OperationResult::failure('forbidden');
        }
        $template = $this->templates->find($templateId);
        if ($template === null || trim($label) === '') {
            return OperationResult::failure($template === null ? 'not_found' : 'incomplete_field');
        }
        $field = $this->fieldById($templateId, $fieldId);
        if ($field === null) {
            return OperationResult::failure('not_found');
        }
        $options = $this->cleanOptions($options);
        if ($field->type === SpecFieldType::Choice && $options === []) {
            return OperationResult::failure('choice_needs_options');
        }
        if (!$this->templates->updateField($fieldId, trim($label), $required, trim($unit), $options, $sort)) {
            return OperationResult::failure('storage_failed');
        }
        $version = $this->templates->find($templateId)?->schemaVersion ?? 0;
        $this->logChange('field_updated', $template->categoryKey, $field->key, $version);
        // MED-01 asks for an impact report rather than a silent invalidation:
        // the caller shows how many products answered this field under an
        // older shape, and no product is touched here.
        return OperationResult::success('field_saved', [
            'schema_version' => $version,
            'required' => $required,
        ]);
    }

    public function deprecateField(int $templateId, int $fieldId): OperationResult
    {
        return $this->setDeprecated($templateId, $fieldId, true, 'field_deprecated');
    }

    public function restoreField(int $templateId, int $fieldId): OperationResult
    {
        return $this->setDeprecated($templateId, $fieldId, false, 'field_restored');
    }

    private function setDeprecated(int $templateId, int $fieldId, bool $deprecated, string $code): OperationResult
    {
        if (!$this->capabilities->can(Capabilities::MANAGE_SPEC_TEMPLATES)) {
            return OperationResult::failure('forbidden');
        }
        $template = $this->templates->find($templateId);
        $field = $this->fieldById($templateId, $fieldId);
        if ($template === null || $field === null) {
            return OperationResult::failure('not_found');
        }
        $ok = $deprecated
            ? $this->templates->deprecateField($fieldId)
            : $this->templates->restoreField($fieldId);
        if (!$ok) {
            return OperationResult::failure('storage_failed');
        }
        $version = $this->templates->find($templateId)?->schemaVersion ?? 0;
        $this->logChange($code, $template->categoryKey, $field->key, $version);
        return OperationResult::success($code, ['schema_version' => $version]);
    }

    private function fieldById(int $templateId, int $fieldId): ?SpecField
    {
        foreach ($this->templates->find($templateId)?->allFields() ?? [] as $field) {
            if ($field->id === $fieldId) {
                return $field;
            }
        }
        return null;
    }

    /** @param list<string> $options @return list<string> */
    private function cleanOptions(array $options): array
    {
        $clean = [];
        foreach ($options as $option) {
            $option = trim((string) $option);
            if ($option !== '' && !in_array($option, $clean, true)) {
                $clean[] = $option;
            }
        }
        return array_slice($clean, 0, self::MAX_OPTIONS);
    }

    /** Lowercase ASCII, digits, dash and underscore: a key must be stable and quotable. */
    private function normaliseKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = (string) preg_replace('/[^a-z0-9_\-]/', '-', $key);
        return substr(trim($key, '-'), 0, 64);
    }

    private function logChange(string $action, string $category, string $field, int $version): void
    {
        $this->audit->log(
            AuditEventCatalog::PRODUCT_TEMPLATE_CHANGED,
            $this->capabilities->currentUserId(),
            'spec_template',
            $category,
            ['action' => $action, 'category' => $category, 'field' => $field, 'schema_version' => $version]
        );
    }
}
