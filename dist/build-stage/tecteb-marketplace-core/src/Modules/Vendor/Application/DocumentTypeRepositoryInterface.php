<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Application;

use Tecteb\Marketplace\Modules\Vendor\Domain\RequirementSet;

/**
 * The manager's document definitions. The plugin never seeds any: an empty
 * repository is the honest default (plan §2).
 */
interface DocumentTypeRepositoryInterface
{
    public function requirementSet(): RequirementSet;

    /** @param list<string> $allowedMime */
    public function add(string $label, bool $required, array $allowedMime, int $maxBytes, string $instructions = ''): int;

    public function remove(int $typeId): bool;

    /** The manager's explicit "this needs no documents" switch. */
    public function setExplicitlyNone(bool $on): void;
}
