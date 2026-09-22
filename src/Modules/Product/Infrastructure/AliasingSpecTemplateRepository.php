<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure;

use Tecteb\Marketplace\Modules\Product\Application\ProductCategoryDirectoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\SpecTemplateRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\SpecField;
use Tecteb\Marketplace\Modules\Product\Domain\SpecTemplate;

/**
 * The bridge across the day `category_key` changed meaning.
 *
 * Before `alpha.24` a template was keyed by a word the manager typed
 * (`diagnostics`) and a product stored that same word. From `alpha.24` both
 * are the WooCommerce term id (`2140`). Nothing is rewritten in the database
 * — a migration that renamed keys would have to guess which term each word
 * meant, and a wrong guess silently attaches the wrong medical questions to
 * somebody's product.
 *
 * So the lookup asks twice instead. A term id that finds no template is tried
 * again as the legacy key of the SAME term, and vice versa. Both directions
 * are needed: an old template with a new product, and an old product with a
 * template the manager has since re-attached.
 *
 * Everything else passes straight through. This class resolves names; it does
 * not own any data.
 */
final class AliasingSpecTemplateRepository implements SpecTemplateRepositoryInterface
{
    /** @var callable():ProductCategoryDirectoryInterface */
    private $directory;

    /** @param callable():ProductCategoryDirectoryInterface $directory resolved late, so the two can reference each other */
    public function __construct(
        private readonly SpecTemplateRepositoryInterface $inner,
        callable $directory
    ) {
        $this->directory = $directory;
    }

    public function findByCategory(string $categoryKey): ?SpecTemplate
    {
        $categoryKey = trim($categoryKey);
        if ($categoryKey === '') {
            return null;
        }
        $direct = $this->inner->findByCategory($categoryKey);
        if ($direct !== null) {
            return $direct;
        }
        foreach ($this->aliases($categoryKey) as $alias) {
            $found = $this->inner->findByCategory($alias);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }

    /**
     * Every other string that names the same category.
     *
     * @return list<string>
     */
    private function aliases(string $categoryKey): array
    {
        $category = ($this->directory)()->find($categoryKey);
        if ($category === null) {
            return [];
        }
        $out = [];
        if ((string) $category->id !== $categoryKey) {
            $out[] = (string) $category->id;
        }
        // The other direction: the stored value IS the term id, and the
        // template still carries whatever word used to name it. Every
        // template whose key resolves to this same term is a match.
        foreach ($this->inner->all() as $template) {
            if ($template->categoryKey === $categoryKey) {
                continue;
            }
            $resolved = ($this->directory)()->find($template->categoryKey);
            if ($resolved !== null && $resolved->id === $category->id) {
                $out[] = $template->categoryKey;
            }
        }
        return $out;
    }

    public function find(int $templateId): ?SpecTemplate
    {
        return $this->inner->find($templateId);
    }

    public function all(): array
    {
        return $this->inner->all();
    }

    public function createTemplate(string $categoryKey, string $label): int
    {
        return $this->inner->createTemplate($categoryKey, $label);
    }

    public function renameTemplate(int $templateId, string $label): bool
    {
        return $this->inner->renameTemplate($templateId, $label);
    }

    public function addField(int $templateId, SpecField $field): int
    {
        return $this->inner->addField($templateId, $field);
    }

    public function updateField(int $fieldId, string $label, bool $required, string $unit, array $options, int $sort): bool
    {
        return $this->inner->updateField($fieldId, $label, $required, $unit, $options, $sort);
    }

    public function deprecateField(int $fieldId): bool
    {
        return $this->inner->deprecateField($fieldId);
    }

    public function restoreField(int $fieldId): bool
    {
        return $this->inner->restoreField($fieldId);
    }

    public function bumpVersion(int $templateId): int
    {
        return $this->inner->bumpVersion($templateId);
    }
}
