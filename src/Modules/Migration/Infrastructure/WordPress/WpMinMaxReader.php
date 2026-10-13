<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Migration\Application\MinMaxReaderInterface;
use Tecteb\Marketplace\Modules\Migration\Domain\MinMaxRule;

/**
 * «Min Max Control» 8.1.0, read from the database it writes.
 *
 * Read from the DATABASE and not through the plugin's own classes, for two
 * reasons that both matter here. The plugin may be deactivated — which is the
 * state this whole round is working towards — and its `Min_Max_Controller` is
 * a singleton that memoises the resolved rule per request, so asking it twice
 * about two products answers about the first. Its `WC_MMQ::getOptions()`
 * memoises the option array for the whole request too.
 *
 * **The mode is derived the plugin's way, in the plugin's order.** From
 * `wcmmq.php:49-61`:
 *
 *     $wcmmp_is_old     = get_option('wcmmq_s_universal_minmaxstep') ? true : false;
 *     $wcmmp_is_old_pro = get_option('wcmmq_universal_minmaxstep')   ? true : false;
 *     if ($wcmmp_is_old_pro)  { PREFIX = '_wcmmq_';   KEY = 'wcmmq_universal_minmaxstep'; }
 *     elseif ($wcmmp_is_old)  { PREFIX = '_wcmmq_s_'; KEY = 'wcmmq_s_universal_minmaxstep'; }
 *     else                    { PREFIX = '';          KEY = 'wcmmq_minmaxstep'; }
 *
 * Two things in that are easy to get wrong and are got right here:
 *
 *  - **the test is TRUTHINESS, not existence.** An option row that exists and
 *    holds `''`, `0` or `array()` is falsy, so the plugin falls through to the
 *    next branch. «The option is there» and «that branch is taken» are
 *    different facts, and reading the first as the second would have this
 *    reader looking at `_wcmmq_*` rows while the shop enforces unprefixed
 *    ones — an exit report about rules nobody is applying.
 *  - **the PRO key wins even though it is read second.** The variable names
 *    suggest otherwise; the `if` does not.
 *
 * **In the plain mode the meta keys carry NO prefix** — `min_quantity`,
 * `max_quantity`, `product_step`, `default_quantity` — which are generic
 * enough that another plugin may own rows of the same name. The reader cannot
 * tell those apart and says so rather than guessing; the preview reports the
 * mode so a manager can see which namespace is being read.
 */
final class WpMinMaxReader implements MinMaxReaderInterface
{
    public const UNIVERSAL = 'universal';
    public const SINGLE = 'single';
    public const PLAIN = 'plain';

    public const FIELDS = ['min_quantity', 'max_quantity', 'product_step', 'default_quantity'];

    /** @var array{mode:string, prefix:string, option:string, premium:bool}|null */
    private ?array $mode = null;

    /** @var array<string,mixed>|null */
    private ?array $options = null;

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * Installed, by the only evidence that does not need it to be running.
     *
     * The constant is asked first because it is conclusive when present, and
     * the option is the fallback for a deactivated plugin whose rows are all
     * still there — which is exactly the state an exit test produces.
     */
    public function isAvailable(): bool
    {
        if (defined('WC_MMQ_VERSION')) {
            return true;
        }
        foreach (['wcmmq_universal_minmaxstep', 'wcmmq_s_universal_minmaxstep', 'wcmmq_minmaxstep'] as $key) {
            if ($this->option($key) !== false) {
                return true;
            }
        }
        return false;
    }

    public function mode(): array
    {
        if ($this->mode !== null) {
            return $this->mode;
        }
        $pro = $this->truthy($this->option('wcmmq_universal_minmaxstep'));
        $single = $this->truthy($this->option('wcmmq_s_universal_minmaxstep'));
        if ($pro) {
            $this->mode = ['mode' => self::UNIVERSAL, 'prefix' => '_wcmmq_', 'option' => 'wcmmq_universal_minmaxstep', 'premium' => $this->premium()];
        } elseif ($single) {
            $this->mode = ['mode' => self::SINGLE, 'prefix' => '_wcmmq_s_', 'option' => 'wcmmq_s_universal_minmaxstep', 'premium' => $this->premium()];
        } else {
            $this->mode = ['mode' => self::PLAIN, 'prefix' => '', 'option' => 'wcmmq_minmaxstep', 'premium' => $this->premium()];
        }
        return $this->mode;
    }

    /**
     * Whether the per-variation level can fire at all.
     *
     * `wcmmq_is_premium()` is `defined('WC_MMQ_PRO_VERSION')` and nothing
     * else. On a build without it, `setIfVariationArgs()` returns before
     * reading anything and `organizeAndFinalizeArgs()` nulls the variation id
     * — so variation rows, if any exist, are inert. The preview reports them
     * regardless, because installing premium would wake them up.
     */
    private function premium(): bool
    {
        return defined('WC_MMQ_PRO_VERSION');
    }

    private function truthy(mixed $value): bool
    {
        if ($value === false || $value === null) {
            return false;
        }
        if (is_array($value)) {
            return $value !== [];
        }
        if (is_string($value)) {
            return $value !== '' && $value !== '0';
        }
        return (bool) $value;
    }

    private function option(string $key): mixed
    {
        return function_exists('get_option') ? get_option($key) : false;
    }

    /** @return array<string,mixed> */
    private function options(): array
    {
        if ($this->options !== null) {
            return $this->options;
        }
        $raw = $this->option($this->mode()['option']);
        $this->options = is_array($raw) ? $raw : [];
        return $this->options;
    }

    private function field(string $name): string
    {
        return $this->mode()['prefix'] . $name;
    }

    public function globalRule(): ?MinMaxRule
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $options = $this->options();
        if ($options === []) {
            return null;
        }
        // The DEFAULTS the plugin applies when a key is absent, and they are
        // not the same as empty: `setGlobalArgs()` reads
        // `$options[min] ?? 1`, `$options[max] ?? -1`, `$options[step] ?? 1`.
        // A `-1` maximum is how «no ceiling» looks at this level, and
        // `MinMaxRule` reads it that way.
        $min = array_key_exists($this->field('min_quantity'), $options)
            ? (string) $options[$this->field('min_quantity')] : '1';
        $max = array_key_exists($this->field('max_quantity'), $options)
            ? (string) $options[$this->field('max_quantity')] : '-1';
        $step = array_key_exists($this->field('product_step'), $options)
            ? (string) $options[$this->field('product_step')] : '1';
        $default = (string) ($options[$this->field('default_quantity')] ?? '');
        return MinMaxRule::from(MinMaxRule::AT_GLOBAL, $min, $max, $step, $default);
    }

    public function termRules(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $terms = $this->options()['terms'] ?? null;
        if (!is_array($terms)) {
            return [];
        }
        $out = [];
        foreach ($terms as $taxonomy => $byTerm) {
            if (!is_string($taxonomy) || !is_array($byTerm)) {
                continue;
            }
            foreach ($byTerm as $termId => $values) {
                if (!is_array($values)) {
                    continue;
                }
                // `setTermwiseArgs()`'s defaults, and the MAXIMUM one is a
                // trap worth naming: it is `?? 1`, not `?? ''`. A term rule
                // configured with only a minimum therefore enforces a MAXIMUM
                // OF ONE. Reproduced here rather than corrected — correcting
                // it would be this plugin deciding the other one has a bug and
                // then migrating a rule the shop never enforced.
                $out[$taxonomy . '|' . (int) $termId] = MinMaxRule::from(
                    MinMaxRule::AT_TERM,
                    (string) ($values['_min'] ?? '1'),
                    (string) ($values['_max'] ?? '1'),
                    (string) ($values['_step'] ?? '1'),
                    (string) ($values['_default'] ?? '')
                );
            }
        }
        return $out;
    }

    public function productRulesAfter(int $afterId, int $limit = self::PAGE): array
    {
        if (!$this->isAvailable()) {
            return ['rows' => [], 'cursor' => max(0, $afterId), 'done' => true];
        }
        $keys = array_map(fn (string $f): string => $this->field($f), self::FIELDS);
        $placeholders = implode(', ', array_fill(0, count($keys), '%s'));
        $page = max(1, min(1000, $limit));
        // Posts and variations in one query, because the level a row belongs
        // to is decided by the POST TYPE and asking twice would need two
        // cursors over one ordering. One row more than a page is asked for so
        // «there is more» is an observation rather than an inference from a
        // full page.
        $rows = $this->db->getResults(
            'SELECT p.ID, p.post_parent, p.post_type, m.meta_key, m.meta_value
               FROM `' . $this->db->prefix() . 'postmeta` m
         INNER JOIN `' . $this->db->prefix() . 'posts` p ON p.ID = m.post_id
              WHERE m.meta_key IN (' . $placeholders . ')
                AND p.post_type IN (%s, %s)
                AND p.ID > %d
              ORDER BY p.ID ASC, m.meta_key ASC',
            array_merge($keys, ['product', 'product_variation', max(0, $afterId)])
        );
        // Grouped in PHP after ordering by id, then cut to WHOLE products: a
        // page that ended in the middle of one product's four rows would hand
        // the caller a rule missing its maximum and a cursor that skips it.
        $byId = [];
        $more = false;
        foreach ($rows as $row) {
            $id = (int) $row['ID'];
            if (!array_key_exists($id, $byId) && count($byId) >= $page) {
                $more = true;           // a further product exists; stop here
                break;
            }
            $byId[$id] ??= ['parent' => (int) $row['post_parent'], 'type' => (string) $row['post_type'], 'meta' => []];
            $byId[$id]['meta'][(string) $row['meta_key']] = (string) ($row['meta_value'] ?? '');
        }
        $cursor = max(0, $afterId);
        $out = [];
        foreach ($byId as $id => $data) {
            $cursor = max($cursor, (int) $id);
            $level = $data['type'] === 'product_variation' ? MinMaxRule::AT_VARIATION : MinMaxRule::AT_PRODUCT;
            $rule = MinMaxRule::from(
                $level,
                $data['meta'][$this->field('min_quantity')] ?? '',
                $data['meta'][$this->field('max_quantity')] ?? '',
                $data['meta'][$this->field('product_step')] ?? '',
                $data['meta'][$this->field('default_quantity')] ?? ''
            );
            if ($rule->isEmpty()) {
                // Rows present and all blank is «no rule here»: the plugin's
                // own `getMeta()` turns a non-numeric value into `''` and
                // `assignInputArg()` then falls through to the next level. The
                // id still advances the cursor, which is the whole reason the
                // cursor is returned.
                continue;
            }
            $out[] = [
                'product_id' => (int) $id,
                'parent_id' => $data['parent'],
                'type' => $data['type'],
                'rule' => $rule,
            ];
        }
        return ['rows' => $out, 'cursor' => $cursor, 'done' => !$more];
    }

    public function counts(): array
    {
        $empty = ['product' => 0, 'variation' => 0, 'term' => 0, 'global' => 0, 'default_quantity' => 0];
        if (!$this->isAvailable()) {
            return $empty;
        }
        $limits = array_map(fn (string $f): string => $this->field($f), ['min_quantity', 'max_quantity', 'product_step']);
        $counts = $empty;
        foreach (['product' => 'product', 'variation' => 'product_variation'] as $level => $postType) {
            $counts[$level] = (int) ($this->db->getVar(
                'SELECT COUNT(DISTINCT m.post_id)
                   FROM `' . $this->db->prefix() . 'postmeta` m
             INNER JOIN `' . $this->db->prefix() . 'posts` p ON p.ID = m.post_id
                  WHERE m.meta_key IN (%s, %s, %s) AND p.post_type = %s
                    AND TRIM(COALESCE(m.meta_value, "")) <> ""',
                array_merge($limits, [$postType])
            ) ?? 0);
        }
        $counts['default_quantity'] = (int) ($this->db->getVar(
            'SELECT COUNT(DISTINCT m.post_id)
               FROM `' . $this->db->prefix() . 'postmeta` m
         INNER JOIN `' . $this->db->prefix() . 'posts` p ON p.ID = m.post_id
              WHERE m.meta_key = %s AND p.post_type IN (%s, %s)
                AND TRIM(COALESCE(m.meta_value, "")) <> ""',
            [$this->field('default_quantity'), 'product', 'product_variation']
        ) ?? 0);
        $counts['term'] = count($this->termRules());
        $global = $this->globalRule();
        $counts['global'] = $global !== null && !$global->isUnrestricted() ? 1 : 0;
        return $counts;
    }

    public function presentationSettings(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $options = $this->options();
        $out = [];
        foreach ([
            'prefix_quantity', 'sufix_quantity', 'qty_plus_minus_btn',
        ] as $name) {
            $key = $this->field($name);
            if (array_key_exists($key, $options)) {
                $out[$key] = (string) $options[$key];
            }
        }
        foreach (['quantiy_box_archive', '_cat_ids'] as $key) {
            if (array_key_exists($key, $options)) {
                $out[$key] = is_array($options[$key])
                    ? 'array(' . count($options[$key]) . ')'
                    : (string) $options[$key];
            }
        }
        return $out;
    }
}
