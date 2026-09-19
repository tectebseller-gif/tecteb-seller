<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Contracts\OptionStoreInterface;

/**
 * Which marketplace category a Dokan product's category becomes — **a
 * decision, stored; never a guess, computed.**
 *
 * A Dokan product carries a WooCommerce `product_cat` term. A marketplace
 * product carries a category key that decides which medical spec fields it
 * must have. Those are two vocabularies with no reliable correspondence:
 * «تجهیزات» in one shop's taxonomy and `medical_devices` in this one may or
 * may not mean the same thing, and the difference matters because the second
 * one determines what a seller is REQUIRED to fill in.
 *
 * So the import does not invent it. It reads the source category, looks it up
 * here, and leaves the product's category EMPTY when there is no entry —
 * which keeps the product a draft, because a draft without a category cannot
 * be published. That is the honest outcome: the catalogue arrived, and the
 * products that nobody has mapped yet are visibly waiting rather than
 * silently live under a category somebody's code chose.
 *
 * `unmapped()` is the actionable half: it names the source categories that
 * are holding products back, with counts, so the answer to «why is nothing
 * published» is a list and not an investigation.
 */
final class CategoryMap
{
    public const SETTING = 'tmc_dokan_category_map';

    public function __construct(private readonly OptionStoreInterface $options)
    {
    }

    /** @return array<string,string> source category key → marketplace category key */
    public function all(): array
    {
        $raw = $this->options->get(self::SETTING, []);
        if (!is_array($raw)) {
            return [];
        }
        $map = [];
        foreach ($raw as $source => $target) {
            $source = trim((string) $source);
            $target = trim((string) $target);
            if ($source !== '' && $target !== '') {
                $map[$source] = $target;
            }
        }
        return $map;
    }

    /** The marketplace category for a source one, or '' when nobody has said. */
    public function marketplaceCategoryFor(string $sourceCategoryKey): string
    {
        $sourceCategoryKey = trim($sourceCategoryKey);
        if ($sourceCategoryKey === '') {
            return '';
        }
        return $this->all()[$sourceCategoryKey] ?? '';
    }

    /** @param array<string,string> $map source → marketplace */
    public function save(array $map): bool
    {
        $clean = [];
        foreach ($map as $source => $target) {
            $source = trim((string) $source);
            $target = trim((string) $target);
            if ($source !== '' && $target !== '') {
                $clean[$source] = $target;
            }
        }
        return $this->options->set(self::SETTING, $clean);
    }

    /**
     * The source categories nobody has mapped, and how many products each is
     * holding as drafts.
     *
     * @param list<array{source_category_key?:string, source_category_label?:string}> $sourceProducts
     * @return list<array{key:string, label:string, products:int}>
     */
    public function unmapped(array $sourceProducts): array
    {
        $known = $this->all();
        $pending = [];
        foreach ($sourceProducts as $product) {
            $key = trim((string) ($product['source_category_key'] ?? ''));
            // A product with NO source category at all is its own problem and
            // a different one: no mapping can fix it, because there is
            // nothing to map. It is counted under the empty key so the report
            // can say so rather than quietly dropping it.
            if (isset($known[$key]) && $key !== '') {
                continue;
            }
            if (!isset($pending[$key])) {
                $pending[$key] = [
                    'key' => $key,
                    'label' => trim((string) ($product['source_category_label'] ?? '')),
                    'products' => 0,
                ];
            }
            $pending[$key]['products']++;
        }
        ksort($pending);
        return array_values($pending);
    }
}
