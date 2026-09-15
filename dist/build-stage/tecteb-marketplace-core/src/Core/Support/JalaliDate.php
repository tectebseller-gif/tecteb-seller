<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Support;

/**
 * Gregorian → Jalali (Solar Hijri) conversion for DISPLAY only; storage is
 * always ISO/UTC (UX §24). Pure arithmetic; no locale, no WordPress.
 */
final class JalaliDate
{
    /** @return array{0:int,1:int,2:int} [year, month, day] */
    public static function fromGregorian(int $gy, int $gm, int $gd): array
    {
        $gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $jy = ($gy <= 1600) ? 0 : 979;
        $gy -= ($gy <= 1600) ? 621 : 1600;
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) - 80 + $gd + $gdm[$gm - 1];
        $jy += 33 * intdiv($days, 12053);
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        $jm = ($days < 186) ? 1 + intdiv($days, 31) : 7 + intdiv($days - 186, 30);
        $jd = 1 + (($days < 186) ? ($days % 31) : (($days - 186) % 30));
        return [$jy, $jm, $jd];
    }

    /** "۱۴۰۵/۰۶/۱۵ ۰۶:۳۰" in the instant's own timezone (callers pass UTC). */
    public static function format(\DateTimeImmutable $instant): string
    {
        [$y, $m, $d] = self::fromGregorian((int) $instant->format('Y'), (int) $instant->format('n'), (int) $instant->format('j'));
        $text = sprintf('%04d/%02d/%02d %s', $y, $m, $d, $instant->format('H:i'));
        return PersianDigits::toPersian($text);
    }
}
