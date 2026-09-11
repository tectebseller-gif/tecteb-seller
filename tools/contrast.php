<?php
/**
 * WCAG 2.2 contrast ratios for the brand palette (development tool).
 * The brand colours are fixed by the Master Spec (A.6) and the UX spec §1.1;
 * what we choose is the TEXT colour placed on each one.
 */
declare(strict_types=1);

function rel(string $hex): float
{
    $hex = ltrim($hex, '#');
    $c = [];
    foreach ([0, 2, 4] as $i) {
        $v = hexdec(substr($hex, $i, 2)) / 255;
        $c[] = $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}
function ratio(string $a, string $b): float
{
    $la = rel($a);
    $lb = rel($b);
    return round((max($la, $lb) + 0.05) / (min($la, $lb) + 0.05), 2);
}

$pairs = [
    ['متن سفید روی سرمه‌ای اصلی (دکمه اصلی، ناوبری فعال)', '#FFFFFF', '#143C4D', 4.5],
    ['متن سرمه‌ای روی آبی هدر', '#143C4D', '#6ABFE7', 4.5],
    ['متن سرمه‌ای روی سبز موفقیت', '#143C4D', '#21D483', 4.5],
    ['متن سرمه‌ای روی زرد هشدار', '#143C4D', '#FFC658', 4.5],
    ['متن سفید روی قرمز خطا', '#FFFFFF', '#B3261E', 4.5],
    ['متن اصلی روی پس‌زمینه صفحه', '#1F2A30', '#F7F9FA', 4.5],
    ['متن اصلی روی سطح کارت', '#1F2A30', '#FFFFFF', 4.5],
    ['متن کم‌رنگ روی سطح کارت', '#4F6570', '#FFFFFF', 4.5],
    ['متن کم‌رنگ روی پس‌زمینه صفحه', '#4F6570', '#F7F9FA', 4.5],
    ['متن سرمه‌ای روی خاکستری خنثی', '#143C4D', '#E4EAEE', 4.5],
    ['متن سرمه‌ای روی کارت muted', '#143C4D', '#F1F5F7', 4.5],
    ['حلقه focus سرمه‌ای روی پس‌زمینه صفحه (غیرمتنی ۳:۱)', '#143C4D', '#F7F9FA', 3.0],
    ['حاشیه ورودی روی سطح کارت (غیرمتنی ۳:۱)', '#4F6570', '#FFFFFF', 3.0],
    // Added with the redesign: identifiers/versions now sit in a chip, and the
    // health table header uses the same tint.
    ['شناسه و نسخه روی زمینه chip فنی', '#143C4D', '#EEF4F7', 4.5],
    ['سرستون جدول سلامت روی زمینه chip فنی', '#143C4D', '#EEF4F7', 4.5],
];

$fail = 0;
$rows = [];
printf("%-58s %-9s %-9s %6s %6s %s\n", 'ترکیب', 'متن', 'زمینه', 'نسبت', 'حداقل', 'نتیجه');
echo str_repeat('-', 108), "\n";
foreach ($pairs as [$label, $fg, $bg, $min]) {
    $r = ratio($fg, $bg);
    $ok = $r >= $min;
    $fail += $ok ? 0 : 1;
    $rows[] = ['pair' => $label, 'fg' => $fg, 'bg' => $bg, 'ratio' => $r, 'required' => $min, 'pass' => $ok];
    printf("%-58s %-9s %-9s %6.2f %6.1f %s\n", $label, $fg, $bg, $r, $min, $ok ? 'PASS' : 'FAIL');
}
file_put_contents(__DIR__ . '/../docs/evidence/contrast.json', json_encode([
    'standard' => 'WCAG 2.2 — 1.4.3 (text 4.5:1) and 1.4.11 (non-text 3:1)',
    'note' => 'Brand colours are fixed by the Master Spec; the text colour on each is the choice being verified.',
    'results' => $rows,
    'failures' => $fail,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nfailures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
