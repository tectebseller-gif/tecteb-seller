<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Modules\Product\Application\BrandDirectoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\Brand;
use Tecteb\Marketplace\Modules\Product\Domain\BrandName;

/**
 * WooCommerce's own `product_brand` terms, read once per request and indexed.
 *
 * The taxonomy is WooCommerce's, not ours: measured on the disposable install
 * with WooCommerce 11.0.1, `get_taxonomies()` lists `product_brand` beside
 * `product_cat`. So the approved brand list is a thing the manager already
 * has a screen for, and this class reads it — the arrangement
 * `WpProductCategoryDirectory` has had since `alpha.25`.
 *
 * `hide_empty => false` for the same reason it is set there: a brand the
 * manager has just approved has no products yet, and a list that hid it would
 * be empty on exactly the shop that needs it.
 *
 * **The index is by `BrandName::key()`**, so «آمبو بگ» finds «آمبوبگ» and
 * neither can be registered twice. The display name is always the term's own.
 */
final class WooCommerceBrandDirectory implements BrandDirectoryInterface
{
    public const TAXONOMY = 'product_brand';

    /** @var array<int,string>|null term id => name */
    private ?array $terms = null;

    /** @var array<string,int>|null BrandName key => term id */
    private ?array $byKey = null;

    public function isAvailable(): bool
    {
        return function_exists('taxonomy_exists') && taxonomy_exists(self::TAXONOMY);
    }

    public function search(string $query, int $limit = 30): array
    {
        $this->load();
        $key = BrandName::key($query);
        $hits = [];
        foreach ($this->terms ?? [] as $termId => $name) {
            // A substring of the KEY on both sides, so a vendor typing with a
            // space finds a brand stored without one. No edit distance: for
            // identity, «near» is how one shop's goods end up under another
            // shop's name (`BrandName`).
            if ($key === '' || str_contains(BrandName::key($name), $key)) {
                $hits[] = new Brand((int) $termId, (string) $name);
            }
            if (count($hits) >= max(1, $limit)) {
                break;
            }
        }
        return $hits;
    }

    public function find(int $termId): ?Brand
    {
        $this->load();
        $name = $this->terms[$termId] ?? null;
        return $name === null ? null : new Brand($termId, (string) $name);
    }

    public function findByKey(string $key): ?Brand
    {
        if ($key === '') {
            return null;
        }
        $this->load();
        $termId = $this->byKey[$key] ?? null;
        return $termId === null ? null : new Brand((int) $termId, (string) ($this->terms[$termId] ?? ''));
    }

    /**
     * The manager's decision, written once.
     *
     * Asked FIRST and asked AGAIN. The first question is the ordinary one: a
     * name that is already a brand needs no second term, however it is spelled.
     * The second is the concurrency one: two managers approving «آمبو بگ» in
     * the same second both get past the first question, `wp_insert_term()`
     * refuses the loser with `term_exists`, and the loser must come back with
     * the WINNER'S term rather than with a failure — the `alpha.39` shape, at
     * a taxonomy instead of a unique index.
     *
     * Null means the write refused, which is not the same as «it is not
     * there»; a caller reading one as the other would report a brand
     * registered when nothing was.
     */
    public function register(string $name): ?Brand
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $display = BrandName::display($name);
        if (!BrandName::isValid($display)) {
            return null;
        }
        $existing = $this->findByKey(BrandName::key($display));
        if ($existing !== null) {
            return $existing;
        }
        $inserted = wp_insert_term($display, self::TAXONOMY);
        $this->terms = null;
        $this->byKey = null;
        if (is_array($inserted) && (int) ($inserted['term_id'] ?? 0) > 0) {
            // Read back rather than trusted: the term is the list's answer,
            // and a name WordPress filtered on the way in is the name the
            // list now holds (the `alpha.26` rule).
            return $this->find((int) $inserted['term_id']);
        }
        // The loser of a race, or a name WordPress refuses. Either way the
        // question is the same: is it there NOW?
        return $this->findByKey(BrandName::key($display));
    }

    public function count(): int
    {
        $this->load();
        return count($this->terms ?? []);
    }

    private function load(): void
    {
        if ($this->terms !== null) {
            return;
        }
        $this->terms = [];
        $this->byKey = [];
        if (!$this->isAvailable()) {
            return;
        }
        $terms = get_terms([
            'taxonomy' => self::TAXONOMY,
            'hide_empty' => false,
            'orderby' => 'name',
        ]);
        if (!is_array($terms)) {
            return;
        }
        foreach ($terms as $term) {
            if (!is_object($term) || !isset($term->term_id, $term->name)) {
                continue;
            }
            $this->terms[(int) $term->term_id] = (string) $term->name;
            $key = BrandName::key((string) $term->name);
            // FIRST WINS, so a list that already holds two spellings of one
            // brand — which a manager could have made by hand before this
            // existed — resolves to one of them deterministically instead of
            // to whichever came last. Nothing is merged and nothing is
            // renamed: the owner asked that old brands not be touched.
            if ($key !== '' && !isset($this->byKey[$key])) {
                $this->byKey[$key] = (int) $term->term_id;
            }
        }
    }
}
