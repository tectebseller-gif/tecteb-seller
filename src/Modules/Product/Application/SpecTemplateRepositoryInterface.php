<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Modules\Product\Domain\SpecField;
use Tecteb\Marketplace\Modules\Product\Domain\SpecTemplate;

/**
 * The category templates of MED-01.
 *
 * There is no delete for a field on purpose: `deprecate()` is the only way a
 * question goes away, because the answers already given to it must survive.
 */
interface SpecTemplateRepositoryInterface
{
    public function findByCategory(string $categoryKey): ?SpecTemplate;

    public function find(int $templateId): ?SpecTemplate;

    /** @return list<SpecTemplate> */
    public function all(): array;

    /** @return int template id, or 0 on failure */
    public function createTemplate(string $categoryKey, string $label): int;

    public function renameTemplate(int $templateId, string $label): bool;

    /** @return int field id, or 0 when the key is already used or the insert failed */
    public function addField(int $templateId, SpecField $field): int;

    public function updateField(int $fieldId, string $label, bool $required, string $unit, array $options, int $sort): bool;

    /** Retires a field: it stops being asked, and every answer stays. */
    public function deprecateField(int $fieldId): bool;

    public function restoreField(int $fieldId): bool;

    /** Bumps the template's schema version; called whenever its shape changes. */
    public function bumpVersion(int $templateId): int;
}
