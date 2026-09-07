<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Support;

/**
 * Turns exceptions into short, single-line, trace-free summaries suitable
 * for the health page (UX-01: "traceback خام به کاربر نمایش داده نشود").
 */
final class TextSanitizer
{
    public static function exceptionSummary(\Throwable $e, int $max = 160): string
    {
        $class = (new \ReflectionClass($e))->getShortName();
        return self::singleLine($class . ': ' . $e->getMessage(), $max);
    }

    public static function singleLine(string $text, int $max = 160): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if (mb_strlen($text) > $max) {
            $text = mb_substr($text, 0, $max - 1) . '…';
        }
        return $text;
    }
}
