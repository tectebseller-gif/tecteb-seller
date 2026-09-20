<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Contracts\OptionStoreInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffRolePreset;

/**
 * Which marketplace role a Dokan staff member's role becomes — **a decision,
 * stored; never a guess, computed.**
 *
 * The same reasoning as `CategoryMap`, with a sharper edge: a wrong category
 * makes a product wait, and a wrong role hands somebody the orders of a shop
 * that is not theirs. Dokan Pro's `vendor_staff` is one WordPress role
 * carrying a per-user capability list; this marketplace has five presets over
 * five areas (Master Spec §3.1). There is no arithmetic between those two
 * vocabularies — «مدیر فروشگاه» in one is not the store-manager preset here
 * unless somebody says it is.
 *
 * So an unmapped role answers `null`, and the caller grants **nothing**. Not a
 * view-only fallback, not a custom set with one permission, not a suspended
 * row that a later click would wake up with powers nobody chose. The standing
 * instruction is «هیچ دسترسی نامشخصی خودکار اعطا نشود», and the only way to
 * keep it is to have no default at all.
 *
 * What survives an unmapped role is the RECORD: the person stays in
 * `tmc_dokan_staff_history` with their Dokan role, so the report can name them
 * and say «this one is waiting for a decision» rather than losing them.
 */
final class StaffRoleMap
{
    public const SETTING = 'tmc_dokan_staff_role_map';

    /**
     * The one value that is not a preset: «this person gets nothing here».
     *
     * It exists so that «nobody has decided» and «somebody decided no» can be
     * told apart. Without it, declining a role looks exactly like never having
     * looked at it, and a migration report cannot say whether it is finished.
     */
    public const DECLINED = 'declined';

    public function __construct(private readonly OptionStoreInterface $options)
    {
    }

    /** @return array<string,string> dokan role slug → preset value or DECLINED */
    public function all(): array
    {
        $raw = $this->options->get(self::SETTING, []);
        if (!is_array($raw)) {
            return [];
        }
        $map = [];
        foreach ($raw as $role => $target) {
            $role = trim((string) $role);
            $target = trim((string) $target);
            if ($role !== '' && $target !== '' && self::isKnownTarget($target)) {
                $map[$role] = $target;
            }
        }
        return $map;
    }

    /**
     * The marketplace preset for a Dokan role, or null when nobody has said —
     * and null also when the decision was «no», because in both cases the
     * caller must grant nothing. `isDeclined()` tells the two apart for the
     * report.
     */
    public function presetFor(string $dokanRole): ?StaffRolePreset
    {
        $target = $this->all()[trim($dokanRole)] ?? '';
        return $target === '' || $target === self::DECLINED
            ? null
            : StaffRolePreset::tryFrom($target);
    }

    /** Whether somebody looked at this role and decided it gets nothing here. */
    public function isDeclined(string $dokanRole): bool
    {
        return ($this->all()[trim($dokanRole)] ?? '') === self::DECLINED;
    }

    /** Whether anybody has decided anything about this role at all. */
    public function isDecided(string $dokanRole): bool
    {
        return isset($this->all()[trim($dokanRole)]);
    }

    /** @param array<string,string> $map dokan role → preset value or DECLINED */
    public function save(array $map): bool
    {
        $clean = [];
        foreach ($map as $role => $target) {
            $role = trim((string) $role);
            $target = trim((string) $target);
            if ($role !== '' && $target !== '' && self::isKnownTarget($target)) {
                $clean[$role] = $target;
            }
        }
        return $this->options->set(self::SETTING, $clean);
    }

    /**
     * The Dokan roles nobody has decided about, and how many people each is
     * holding.
     *
     * @param list<array{dokan_role?:string}> $staffRows imported staff history
     * @return list<array{role:string, people:int}>
     */
    public function undecided(array $staffRows): array
    {
        $known = $this->all();
        $pending = [];
        foreach ($staffRows as $row) {
            $role = trim((string) ($row['dokan_role'] ?? ''));
            if (isset($known[$role]) && $role !== '') {
                continue;
            }
            $pending[$role] ??= ['role' => $role, 'people' => 0];
            $pending[$role]['people']++;
        }
        ksort($pending);
        return array_values($pending);
    }

    /**
     * Every target a person may choose, for the form that asks them.
     *
     * `Custom` is deliberately absent: a custom preset carries a permission
     * set that lives on the membership row, and a MAP has nowhere to keep one.
     * Offering it would mean mapping a Dokan role onto `StaffPermissions::none()`,
     * which reads as «granted» and grants nothing — the worst of both.
     *
     * @return list<string>
     */
    public static function targets(): array
    {
        $out = [self::DECLINED];
        foreach (StaffRolePreset::presets() as $preset) {
            $out[] = $preset->value;
        }
        return $out;
    }

    private static function isKnownTarget(string $target): bool
    {
        return $target === self::DECLINED
            || in_array($target, array_map(
                static fn (StaffRolePreset $p): string => $p->value,
                StaffRolePreset::presets()
            ), true);
    }
}
