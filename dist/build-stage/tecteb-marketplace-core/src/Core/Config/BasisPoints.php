<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Config;

/**
 * Formatting for the STORED representation. Pure integer arithmetic; no float.
 * Round-trip property (tested): parse(format(x)) === x for every 0..10000.
 */
final class BasisPoints
{
    /** 1234 → "12.34", 1200 → "12", 1230 → "12.3", 0 → "0" */
    public static function toPercentString(int $bp): string
    {
        if ($bp < 0) {
            throw new \InvalidArgumentException('Basis points cannot be negative.');
        }
        $whole = intdiv($bp, 100);
        $frac = $bp % 100;
        if ($frac === 0) {
            return (string) $whole;
        }
        $fracText = str_pad((string) $frac, 2, '0', STR_PAD_LEFT);
        return $whole . '.' . rtrim($fracText, '0');
    }
}
