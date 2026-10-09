<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Modules\Product\Application\SpecTemplateRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\SpecField;
use Tecteb\Marketplace\Modules\Product\Domain\SpecTemplate;

/**
 * A template repository that answers nothing, for the tests that need the
 * dependency SATISFIED rather than exercised.
 *
 * Written out in full rather than mocked because a mock that «returns null
 * for everything» is a mock somebody has to read the framework to understand
 * — and shared rather than copied into an anonymous class in each test,
 * because three copies of «nothing» is three places to update when the
 * interface grows a method.
 */
final class NoSpecTemplates implements SpecTemplateRepositoryInterface
{
    /**
     * One template, optionally — because the projector's facts block needs
     * the labels and the units, and a caller that wants them should not have
     * to subclass «nothing».
     */
    public function __construct(private readonly ?SpecTemplate $template = null)
    {
    }

    public function findByCategory(string $categoryKey): ?SpecTemplate
    {
        return $this->template;
    }

    public function find(int $templateId): ?SpecTemplate
    {
        return null;
    }

    /** @return list<SpecTemplate> */
    public function all(): array
    {
        return [];
    }

    public function createTemplate(string $categoryKey, string $label): int
    {
        return 0;
    }

    public function renameTemplate(int $templateId, string $label): bool
    {
        return false;
    }

    public function addField(int $templateId, SpecField $field): int
    {
        return 0;
    }

    public function updateField(int $fieldId, string $label, bool $required, string $unit, array $options, int $sort): bool
    {
        return false;
    }

    public function deprecateField(int $fieldId): bool
    {
        return false;
    }

    public function restoreField(int $fieldId): bool
    {
        return false;
    }

    public function bumpVersion(int $templateId): int
    {
        return 0;
    }
}
