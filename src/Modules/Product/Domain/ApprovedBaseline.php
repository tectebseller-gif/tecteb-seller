<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * The fields as they stood the last time both sides demonstrably agreed.
 *
 * This is the third value a three-way merge needs and the one this codebase
 * never had. The projection stamp answers «what did WE write»; the record
 * answers «what does the vendor want»; neither answers «what was the vendor's
 * edit based on». Without that, a manager's edit to a field the vendor never
 * touched is indistinguishable from a disagreement, and one vendor save put a
 * question on the manager's screen for every field of the product.
 *
 * **Absent is a real state and never guessed.** A product that has not been
 * through an approval since this existed has no baseline, `has()` says so,
 * and the rules fall back to the older, narrower ones rather than inventing a
 * value. A wrong baseline would license writing over text nobody has read.
 */
final class ApprovedBaseline
{
    /** @param array<string,string> $values field key => value at the last agreement */
    private function __construct(private readonly array $values)
    {
    }

    /** @param array<string,string> $values */
    public static function of(array $values): self
    {
        $clean = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && $key !== '') {
                $clean[$key] = (string) $value;
            }
        }
        return new self($clean);
    }

    /**
     * What the database holds, read back.
     *
     * A column that is NULL, empty, or not valid JSON gives `null` rather
     * than an empty baseline: «no record of agreement» and «we agreed that
     * every field is empty» are opposite statements, and the second one would
     * authorise overwriting the whole product.
     */
    public static function decode(?string $json): ?self
    {
        if ($json === null || trim($json) === '') {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        return self::of(array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $data));
    }

    public function encode(): string
    {
        return (string) json_encode($this->values, JSON_UNESCAPED_UNICODE);
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->values);
    }

    public function get(string $field): string
    {
        return $this->values[$field] ?? '';
    }

    /** @return array<string,string> */
    public function all(): array
    {
        return $this->values;
    }

    /** The same baseline with one field replaced — used as a decision lands. */
    public function with(string $field, string $value): self
    {
        $values = $this->values;
        $values[$field] = $value;
        return new self($values);
    }
}
