<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * The field types a medical spec template may use (MED-01).
 *
 * A short, closed list on purpose. MED-01 forbids eval, arbitrary PHP and
 * expensive regular expressions in field rules, and the cheapest way to keep
 * that promise is to have no mechanism that could express them.
 */
enum SpecFieldType: string
{
    case Text = 'text';
    case Number = 'number';
    case Boolean = 'boolean';
    case Choice = 'choice';
    case Date = 'date';

    /** @param list<string> $options */
    public function accepts(string $value, array $options = []): bool
    {
        if ($value === '') {
            return true;                     // emptiness is handled by `required`
        }
        return match ($this) {
            self::Text => mb_strlen($value) <= 500,
            self::Number => is_numeric($value),
            self::Boolean => in_array($value, ['0', '1'], true),
            self::Choice => in_array($value, $options, true),
            self::Date => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1,
        };
    }
}
