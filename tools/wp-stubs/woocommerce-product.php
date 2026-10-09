<?php
declare(strict_types=1);

/*
 * A WooCommerce PRODUCT, stubbed. THIS IS NOT WOOCOMMERCE.
 *
 * Nothing measured against this file may be reported as a WooCommerce result
 * — the standing rule in this repository, and the reason every delivery note
 * lists real WooCommerce as `Not Run`.
 *
 * It exists because `WooCommerceProjector::project()` had NO suite at all:
 * every test that touches the projection goes through `FakeCatalogProjector`,
 * which is a different object, so the projector's own branches — which field
 * is written, which is refused, what the attributes end up as — were only
 * ever read, never run. `alpha.41` removed the generated brand-and-
 * specification block from this very method and no test noticed.
 *
 * So the stub keeps exactly one property, and keeps it honestly: a product is
 * a bag of values with getters and setters, and `save()` records that it was
 * called. It models no filter, no taxonomy, no price logic, no stock
 * reservation, and it is deliberately not a place to put any.
 */

namespace {
    if (!class_exists('WC_Product_Attribute')) {
        class WC_Product_Attribute
        {
            private string $name = '';
            /** @var list<string> */
            private array $options = [];
            private int $position = 0;
            private bool $visible = false;
            private bool $variation = false;

            public function set_name(string $name): void
            {
                $this->name = $name;
            }

            public function get_name(): string
            {
                return $this->name;
            }

            /** @param list<string> $options */
            public function set_options(array $options): void
            {
                $this->options = $options;
            }

            /** @return list<string> */
            public function get_options(): array
            {
                return $this->options;
            }

            public function set_position(int $position): void
            {
                $this->position = $position;
            }

            public function get_position(): int
            {
                return $this->position;
            }

            public function set_visible(bool $visible): void
            {
                $this->visible = $visible;
            }

            public function get_visible(): bool
            {
                return $this->visible;
            }

            public function set_variation(bool $variation): void
            {
                $this->variation = $variation;
            }

            public function get_variation(): bool
            {
                return $this->variation;
            }
        }
    }

    if (!class_exists('WC_Product')) {
        class WC_Product
        {
            /** Every product the stub has ever saved, by id. */
            /** @var array<int,WC_Product> */
            public static array $saved = [];

            /** The next id a save() hands out. */
            public static int $nextId = 9000;

            /** Saves that must fail, so the refusal branches can be reached. */
            public static bool $saveFails = false;

            /** @var array<string,mixed> */
            private array $data = [];

            /** Which setters were called, so «never written» is measurable. */
            /** @var list<string> */
            public array $wrote = [];

            public function __construct(int $id = 0)
            {
                $this->data['id'] = $id;
                if ($id > 0 && isset(self::$saved[$id])) {
                    $this->data = self::$saved[$id]->data;
                }
            }

            private function put(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
                $this->wrote[] = $key;
            }

            public function get_id(): int
            {
                return (int) ($this->data['id'] ?? 0);
            }

            public function set_name(string $v): void
            {
                $this->put('name', $v);
            }

            public function get_name(): string
            {
                return (string) ($this->data['name'] ?? '');
            }

            public function set_short_description(string $v): void
            {
                $this->put('short_description', $v);
            }

            public function get_short_description(): string
            {
                return (string) ($this->data['short_description'] ?? '');
            }

            public function set_description(string $v): void
            {
                $this->put('description', $v);
            }

            public function get_description(): string
            {
                return (string) ($this->data['description'] ?? '');
            }

            public function set_image_id(int $v): void
            {
                $this->put('image_id', $v);
            }

            public function get_image_id(): int
            {
                return (int) ($this->data['image_id'] ?? 0);
            }

            /** @param list<int> $v */
            public function set_gallery_image_ids(array $v): void
            {
                $this->put('gallery', $v);
            }

            /** @return list<int> */
            public function get_gallery_image_ids(): array
            {
                return (array) ($this->data['gallery'] ?? []);
            }

            /** @param list<int> $v */
            public function set_category_ids(array $v): void
            {
                $this->put('categories', $v);
            }

            /** @return list<int> */
            public function get_category_ids(): array
            {
                return (array) ($this->data['categories'] ?? []);
            }

            /** @param array<string,WC_Product_Attribute> $v */
            public function set_attributes(array $v): void
            {
                $this->put('attributes', $v);
            }

            /** @return array<string,WC_Product_Attribute> */
            public function get_attributes(): array
            {
                return (array) ($this->data['attributes'] ?? []);
            }

            public function set_status(string $v): void
            {
                $this->put('status', $v);
            }

            public function get_status(): string
            {
                return (string) ($this->data['status'] ?? 'draft');
            }

            public function set_catalog_visibility(string $v): void
            {
                $this->put('visibility', $v);
            }

            public function set_sku(string $v): void
            {
                $this->put('sku', $v);
            }

            public function get_sku(): string
            {
                return (string) ($this->data['sku'] ?? '');
            }

            public function set_slug(string $v): void
            {
                $this->put('slug', $v);
            }

            public function get_slug(): string
            {
                return (string) ($this->data['slug'] ?? '');
            }

            public function set_regular_price(string $v): void
            {
                $this->put('regular_price', $v);
            }

            public function get_regular_price(): string
            {
                return (string) ($this->data['regular_price'] ?? '');
            }

            public function set_sale_price(string $v): void
            {
                $this->put('sale_price', $v);
            }

            public function set_date_on_sale_from(?string $v): void
            {
                $this->put('sale_from', $v);
            }

            public function set_date_on_sale_to(?string $v): void
            {
                $this->put('sale_to', $v);
            }

            public function set_manage_stock(bool $v): void
            {
                $this->put('manage_stock', $v);
            }

            public function get_manage_stock(): bool
            {
                return (bool) ($this->data['manage_stock'] ?? false);
            }

            public function set_stock_quantity(?int $v): void
            {
                $this->put('stock', $v);
            }

            public function get_stock_quantity(): ?int
            {
                return $this->data['stock'] ?? null;
            }

            public function set_stock_status(string $v): void
            {
                $this->put('stock_status', $v);
            }

            public function set_weight(string $v): void
            {
                $this->put('weight', $v);
            }

            public function save(): int
            {
                if (self::$saveFails) {
                    return 0;
                }
                $id = $this->get_id();
                if ($id <= 0) {
                    $id = self::$nextId++;
                    $this->data['id'] = $id;
                }
                self::$saved[$id] = $this;
                // A saved product is also a POST, because that is what it is
                // in WordPress and because `owns()` asks `get_post()` for its
                // `post_type` before the projector will touch it. A stub that
                // saved a product without a post would make every projection
                // onto an existing product look like «the post went away».
                \TmcWpStubs\State::$posts[$id] = array_merge(
                    \TmcWpStubs\State::$posts[$id] ?? [],
                    ['post_type' => 'product', 'status' => $this->get_status(), 'title' => $this->get_name()]
                );
                return $id;
            }

            public static function reset(): void
            {
                self::$saved = [];
                self::$nextId = 9000;
                self::$saveFails = false;
            }
        }
    }

    // The projector's closures are typed `\WC_Product`, and WooCommerce's own
    // simple product extends it. Same here, so the signatures are the real
    // ones rather than a shape that happens to work.
    if (!class_exists('WC_Product_Simple')) {
        class WC_Product_Simple extends WC_Product
        {
        }
    }

    // `class WooCommerce` is deliberately NOT defined here.
    //
    // `WpDependencyProbeTest` starts from «WooCommerce is absent» and `eval`s
    // the class itself to walk the detection step by step — in a separate
    // process, which still loads this bootstrap, so defining it here made
    // that test fail on its first assertion. The projector's own test defines
    // it, under process isolation, so the definition cannot leak into a test
    // that is about its absence.

    if (!function_exists('wc_get_product')) {
        function wc_get_product(int|string $id = 0): WC_Product|false
        {
            $id = (int) $id;
            return $id > 0 && isset(WC_Product::$saved[$id])
                ? WC_Product::$saved[$id]
                : false;
        }
    }

    // `get_post()` is not in `functions.php` — only `get_post_status()` is —
    // and the projector asks for the whole post to check its `post_type`
    // before it will touch a product it thinks it owns. Backed by the same
    // `State::$posts` every other post stub reads, so a test sets up a post
    // in one place.
    if (!function_exists('get_post')) {
        function get_post(int|null $id = null): ?object
        {
            $id = (int) $id;
            $row = \TmcWpStubs\State::$posts[$id] ?? null;
            if ($row === null) {
                return null;
            }
            return (object) [
                'ID' => $id,
                'post_type' => (string) ($row['post_type'] ?? 'product'),
                'post_status' => (string) ($row['status'] ?? 'publish'),
                'post_title' => (string) ($row['title'] ?? ''),
                'post_name' => (string) ($row['slug'] ?? ''),
            ];
        }
    }

    // Post meta. The projector's ownership stamps are post meta, so these
    // are what make `writeOwned()` reachable at all — the method that decides
    // whether a manager's edit in WooCommerce is overwritten or held as a
    // proposal. `metadata_exists()` is separate from `get_post_meta()` on
    // purpose: «no row» and «a row holding an empty string» are two answers,
    // and conflating them is the defect `alpha.28` fixed.
    if (!function_exists('get_post_meta')) {
        function get_post_meta(int $postId, string $key = '', bool $single = false): mixed
        {
            $all = \TmcWpStubs\State::$postMeta[$postId] ?? [];
            if ($key === '') {
                return $all;
            }
            if (!array_key_exists($key, $all)) {
                return $single ? '' : [];
            }
            return $single ? $all[$key] : [$all[$key]];
        }
    }

    if (!function_exists('update_post_meta')) {
        function update_post_meta(int $postId, string $key, mixed $value): bool
        {
            \TmcWpStubs\State::$postMeta[$postId][$key] = $value;
            return true;
        }
    }

    if (!function_exists('delete_post_meta')) {
        function delete_post_meta(int $postId, string $key): bool
        {
            unset(\TmcWpStubs\State::$postMeta[$postId][$key]);
            return true;
        }
    }

    if (!function_exists('metadata_exists')) {
        function metadata_exists(string $type, int $objectId, string $key): bool
        {
            return array_key_exists($key, \TmcWpStubs\State::$postMeta[$objectId] ?? []);
        }
    }

    if (!function_exists('wp_update_post')) {
        function wp_update_post(array $post = []): int
        {
            $id = (int) ($post['ID'] ?? 0);
            if ($id <= 0) {
                return 0;
            }
            foreach ($post as $key => $value) {
                if ($key === 'ID') {
                    continue;
                }
                \TmcWpStubs\State::$posts[$id][$key] = $value;
            }
            return $id;
        }
    }

    if (!function_exists('wp_unique_post_slug')) {
        function wp_unique_post_slug(
            string $slug,
            int $postId = 0,
            string $status = '',
            string $type = '',
            int $parent = 0
        ): string {
            return $slug;
        }
    }

    // The product page's own «which post am I» question. Not in
    // `functions.php`, so `function_exists()` was false and the product-page
    // box returned an empty string in every suite — which is why nothing
    // could measure it being printed twice.
    if (!function_exists('get_the_ID')) {
        function get_the_ID(): int|false
        {
            return \TmcWpStubs\State::$currentPostId > 0
                ? \TmcWpStubs\State::$currentPostId
                : false;
        }
    }

    if (!function_exists('wc_delete_product_transients')) {
        function wc_delete_product_transients(int $productId = 0): void
        {
        }
    }

    if (!function_exists('wc_update_product_stock')) {
        function wc_update_product_stock(mixed $product = null, mixed $quantity = null, string $operation = 'set'): mixed
        {
            return $quantity;
        }
    }
}
