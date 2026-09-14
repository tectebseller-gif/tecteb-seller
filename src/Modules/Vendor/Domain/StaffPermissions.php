<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Domain;

/**
 * What one staff member may do, area by area.
 *
 * Deliberately a value object with no idea who the vendor is: the rule that
 * every permission is DERIVED from the parent vendor (Master Spec §3.1) is
 * enforced one layer up, in StaffAccess, where the vendor is known. Keeping
 * it out of here means a permission set can never be read as ownership.
 */
final class StaffPermissions
{
    /** @param array<string,StaffLevel> $levels keyed by StaffArea::value */
    private function __construct(private readonly array $levels)
    {
    }

    /** @param array<string,StaffLevel|string> $levels */
    public static function of(array $levels): self
    {
        $clean = [];
        foreach (StaffArea::all() as $area) {
            $value = $levels[$area->value] ?? StaffLevel::None;
            $clean[$area->value] = $value instanceof StaffLevel
                ? $value
                : (StaffLevel::tryFrom((string) $value) ?? StaffLevel::None);
        }
        return new self($clean);
    }

    public static function none(): self
    {
        return self::of([]);
    }

    public function level(StaffArea $area): StaffLevel
    {
        return $this->levels[$area->value] ?? StaffLevel::None;
    }

    public function allows(StaffArea $area, StaffLevel $needed): bool
    {
        return $this->level($area)->allows($needed);
    }

    /** True when this set grants nothing at all — a staff member who could do nothing. */
    public function isEmpty(): bool
    {
        foreach ($this->levels as $level) {
            if ($level !== StaffLevel::None) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string,string> for storage */
    public function toArray(): array
    {
        return array_map(static fn (StaffLevel $l): string => $l->value, $this->levels);
    }

    /** @param array<string,string> $stored */
    public static function fromArray(array $stored): self
    {
        return self::of($stored);
    }
}
