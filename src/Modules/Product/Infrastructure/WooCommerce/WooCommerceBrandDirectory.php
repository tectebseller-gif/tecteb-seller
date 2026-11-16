<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Product\Application\BrandDirectoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\Brand;
use Tecteb\Marketplace\Modules\Product\Domain\BrandName;
use Tecteb\Marketplace\Modules\Product\Domain\BrandTaxonomy;

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
    /**
     * WooCommerce's own brand taxonomy, which is the FIRST thing looked for
     * and no longer the only thing.
     *
     * ⛔ Kept as a constant because it is still the preferred answer and
     * because `BrandTaxonomy::KNOWN[0]` is this exact string, with a test
     * binding the two. What changed is that nothing reads it directly any
     * more: a shop whose brands are a WooCommerce ATTRIBUTE has no
     * `product_brand` at all, and every method here used to refuse such a
     * shop a list while saying nothing about why. See `BrandTaxonomy`.
     */
    public const TAXONOMY = 'product_brand';

    /**
     * The discovered taxonomy, asked once per request.
     *
     * `false` means «not asked yet» and `null` means «asked, and this shop has
     * no brand list» — two states that a single nullable cannot hold, and
     * collapsing them would re-ask on every call of every method.
     */
    private string|null|false $taxonomy = false;

    /** @var array<int,string>|null term id => name */
    private ?array $terms = null;

    /** @var array<string,int>|null BrandName key => term id */
    private ?array $byKey = null;

    /**
     * How long `register()` waits for another process's lock.
     *
     * Five seconds, because the work inside it is one `INSERT` and a read: a
     * wait that times out means something is badly wrong, and the answer then
     * is to refuse rather than to make a second brand.
     */
    private const LOCK_SECONDS = 5;

    /**
     * The database, for the ONE thing WordPress cannot do here: serialise two
     * processes registering two SPELLINGS of one brand.
     *
     * Nullable, and when it is null `register()` says so by behaving as it did
     * before — WordPress's own duplicate check on the exact NAME, and nothing
     * for the normalised one.
     */
    public function __construct(private readonly ?DatabaseInterface $db = null)
    {
    }

    public function isAvailable(): bool
    {
        return $this->source() !== null;
    }

    public function source(): ?string
    {
        if ($this->taxonomy !== false) {
            return $this->taxonomy;
        }
        if (!function_exists('get_object_taxonomies')) {
            return $this->taxonomy = null;
        }
        $registered = array_values(array_map('strval', (array) get_object_taxonomies('product')));

        // The shop's GLOBAL attributes, each with the label a person chose.
        // Asked only from WooCommerce's own helper: a `pa_*` taxonomy is
        // WooCommerce's construction and reading `wc_attribute_taxonomies`
        // by hand would be a second opinion about it (`alpha.34`).
        $attributes = [];
        if (function_exists('wc_get_attribute_taxonomies') && function_exists('wc_attribute_taxonomy_name')) {
            foreach ((array) wc_get_attribute_taxonomies() as $attribute) {
                $name = is_object($attribute) ? (string) ($attribute->attribute_name ?? '') : '';
                $label = is_object($attribute) ? (string) ($attribute->attribute_label ?? '') : '';
                if ($name === '') {
                    continue;
                }
                $attributes[] = [
                    'taxonomy' => (string) wc_attribute_taxonomy_name($name),
                    'label' => $label !== '' ? $label : $name,
                ];
            }
        }
        return $this->taxonomy = BrandTaxonomy::discover($registered, $attributes);
    }

    /**
     * The taxonomy to read, for the methods that cannot run without one.
     *
     * They all check `isAvailable()` first, so this never answers an empty
     * string on a path that uses it — and returning one rather than throwing
     * keeps every signature as it was.
     */
    private function taxonomy(): string
    {
        return $this->source() ?? '';
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
        $key = BrandName::key($display);
        $existing = $this->findByKey($key);
        if ($existing !== null) {
            return $existing;
        }
        // THE LOCK, and the measurement that earned it. The first version of
        // this method relied on `wp_insert_term()` refusing a duplicate, which
        // it does — for the SAME NAME. Two processes registering «آمبو بگ» and
        // «آمبوبگ» at the same moment are registering two different names, so
        // both inserts succeed and the list ends with two terms for one brand.
        // Measured with two real processes: `a=37 b=36`, two terms. A
        // same-name race was already safe; this one was not, and the only
        // thing that can serialise it is a lock outside PHP.
        $lock = $this->takeLock($key);
        try {
            if ($lock === false) {
                // Somebody else is registering this brand and did not finish
                // in five seconds. Refuse rather than make a second term: a
                // manager can press the button again, and a duplicate brand is
                // what everything above exists to prevent.
                return $this->freshByKey($key);
            }
            // ASKED AGAIN, inside the lock, FROM THE DATABASE. The winner may
            // have inserted between the two questions, and this process's term
            // cache cannot see another process's commit.
            $already = $this->freshByKey($key);
            if ($already !== null) {
                return $already;
            }
            $inserted = wp_insert_term($display, $this->taxonomy());
            $this->terms = null;
            $this->byKey = null;
            if (is_array($inserted) && (int) ($inserted['term_id'] ?? 0) > 0) {
                // Read back rather than trusted: the term is the list's
                // answer, and a name WordPress filtered on the way in is the
                // name the list now holds (the `alpha.26` rule).
                return $this->find((int) $inserted['term_id']);
            }
            // A name WordPress refuses, or an exact-name duplicate it caught.
            // Either way the question is the same: is it there NOW?
            return $this->freshByKey($key);
        } finally {
            if ($lock === true) {
                $this->releaseLock($key);
            }
        }
    }

    /**
     * The lock's name: short, deterministic, and never the brand itself.
     *
     * MySQL lock names are limited and are global to the server, so the key is
     * hashed and prefixed with the table prefix — two WordPress installs on one
     * database server must not block each other over a brand.
     */
    private function lockName(string $key): string
    {
        return 'tmc_brand_' . ($this->db?->prefix() ?? '') . substr(hash('sha256', $key), 0, 24);
    }

    /** True when held, false when another process holds it, null when there is no database. */
    private function takeLock(string $key): ?bool
    {
        if ($this->db === null) {
            return null;
        }
        $got = $this->db->getVar('SELECT GET_LOCK(%s, %d)', [$this->lockName($key), self::LOCK_SECONDS]);
        // `null` is a read that failed and `0` is «somebody else has it». Only
        // `1` is the lock; a failed read is treated as «not held», so the
        // caller falls back to the fresh read rather than inserting blind.
        return (int) $got === 1;
    }

    private function releaseLock(string $key): void
    {
        $this->db?->getVar('SELECT RELEASE_LOCK(%s)', [$this->lockName($key)]);
    }

    /**
     * The approved brand this key is, read from the DATABASE rather than from
     * the cache.
     *
     * `get_terms()` is cached per request and per process, so inside the lock
     * it would answer with what this process knew BEFORE the other one
     * committed — which is the whole window the lock exists to close. Falls
     * back to the cached reader when there is no database to ask.
     */
    private function freshByKey(string $key): ?Brand
    {
        if ($this->db === null) {
            $this->terms = null;
            $this->byKey = null;
            return $this->findByKey($key);
        }
        $prefix = $this->db->prefix();
        $rows = $this->db->getResults(
            'SELECT t.term_id AS term_id, t.name AS name
               FROM `' . $prefix . 'terms` t
               INNER JOIN `' . $prefix . 'term_taxonomy` tt ON tt.term_id = t.term_id
              WHERE tt.taxonomy = %s',
            [$this->taxonomy()]
        );
        foreach ($rows as $row) {
            if (BrandName::key((string) ($row['name'] ?? '')) === $key) {
                return new Brand((int) $row['term_id'], (string) $row['name']);
            }
        }
        return null;
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
            'taxonomy' => $this->taxonomy(),
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
