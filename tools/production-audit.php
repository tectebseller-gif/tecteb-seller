<?php
/**
 * What production actually holds — READ ONLY.
 *
 * §3 of the owner's instruction asks for a row-by-row account of every
 * product and every Dokan financial record on `tecteb.com`, and §9-A forbids
 * this repository from connecting to it:
 *
 *   «اگر نیاز به بررسی داده production است، ابزار فقط‌خواندنی آماده کن، اما
 *    خودت به سایت اصلی متصل نشو.»
 *
 * So this is the tool. It is the second script here meant to be run on the
 * owner's own site, and the reason it is safe is the same reason the first one
 * is: it CHANGES NOTHING, and that is checked by reading the file rather than
 * promised in a sentence. `tests/Unit/ReadOnlyInventoryTest.php` blanks the
 * comments and then refuses every WordPress writer, every wpdb writer and
 * every SQL verb that changes anything.
 *
 *   wp eval-file tools/production-audit.php products
 *   wp eval-file tools/production-audit.php products --csv > products.csv
 *   wp eval-file tools/production-audit.php finance
 *   wp eval-file tools/production-audit.php reconcile
 *   wp eval-file tools/production-audit.php reconcile --csv > reconcile.csv
 *   wp eval-file tools/production-audit.php cron
 *   wp eval-file tools/production-audit.php finance --csv > finance.csv
 *
 * **Three things it refuses to do, each for a written reason.**
 *
 *  1. **It does not decide the Min Max mode.** That derivation — truthiness,
 *     not existence, and the PRO key winning though it is read second — lives
 *     in exactly one place, `plugin-exit-inventory.php minmax`. Copying twenty
 *     lines of it here would make two tools that can disagree about one site,
 *     which is the shape this repository keeps paying for. So this one prints
 *     the raw per-product meta for ALL THREE possible prefixes and leaves the
 *     verdict where it already is.
 *  2. **It does not add up Dokan's money.** Dokan records a paid withdrawal
 *     twice on purpose — a `withdraw` row and a `debit` in the balance ledger
 *     — so the closing balance has already subtracted it, and a naive SUM is
 *     wrong in both directions. It prints rows, with their type and their
 *     unit, and lets the reconciliation document do the arithmetic under a
 *     rule somebody chose.
 *  3. **It does not name Dokan's commission key.** No Dokan source is
 *     readable from this repository, so a typed key name would be a guess
 *     about the owner's site. The commission section DISCOVERS every product
 *     meta key whose name contains `commission` and prints the foreign ones
 *     raw, the same discipline as the Min Max triple.
 *  4. **It never prints a zero it could not measure.** A read that failed says
 *     `__unreadable__`. «نتوانستم بخوانم» and «صفر» are different work for a
 *     person, and conflating them is the one mistake this file is for.
 *
 * **What it prints is not secret**: ids, statuses, prices, stock, category and
 * brand names, table names and row counts. It prints no customer name, no
 * email, no phone, no address, no IBAN, no option value and no credential.
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "run this through: wp eval-file tools/production-audit.php <products|finance|reconcile|cron> [--csv]\n");
    return;
}

global $wpdb;

$argv0 = (string) ($args[0] ?? '');
$csv = in_array('--csv', $args ?? [], true);
$unreadable = '__unreadable__';

$line = static function (string $key, mixed $value): void {
    echo $key . '=' . str_replace("\n", ' ', (string) $value) . "\n";
};
$head = static function (string $title): void {
    echo "\n## " . $title . "\n";
};

/** One cell, safe for a spreadsheet: the repository's own rule, by hand. */
$cell = static function (mixed $value): string {
    $text = str_replace(["\r", "\n"], ' ', (string) $value);
    if ($text !== '' && strpbrk($text[0], "=+-@\t\r") !== false) {
        $text = "'" . $text;
    }
    return '"' . str_replace('"', '""', $text) . '"';
};
$row = static function (array $cells) use ($cell): void {
    echo implode(',', array_map($cell, $cells)) . "\n";
};

/**
 * One postmeta reader, for ANY post id — the per-row closure delegates to it.
 *
 * `get_var()` cannot tell «no row» from «a row holding the empty string»,
 * because WordPress turns `''` into `null` on purpose (`alpha.82`). That is
 * fine HERE and only here, because the question this tool asks of a
 * commission key is the one `WpTectebCommissionSource::hasRule()` asks:
 * `metadata_exists()` AND `trim(...) !== ''`. Both states answer «no rule»,
 * so they need not be distinguished. A read that FAILED is a third answer and
 * is never collapsed into either.
 */
$metaOf = static function (int $postId, string $key) use ($wpdb, $unreadable): string {
    $value = $wpdb->get_var(
        $wpdb->prepare(
            'SELECT CAST(meta_value AS CHAR) FROM `' . $wpdb->postmeta . '`
             WHERE post_id = %d AND meta_key = %s LIMIT 1',
            $postId,
            $key
        )
    );
    if ($value === null) {
        return $wpdb->last_error !== '' ? $unreadable : '';
    }
    return (string) $value;
};

// ------------------------------------------------------------------ products

if ($argv0 === 'products') {
    if (!$csv) {
        $head('products: one row per product and per variation');
        echo "# ownership has six answers and none of them is a guess:\n";
        echo "#   marketplace  — a TMC row says link_ownership = marketplace\n";
        echo "#   observed     — a TMC row says link_ownership = observed\n";
        echo "#   dokan_only   — post_author has the seller role, no TMC row\n";
        echo "#   shop_only    — neither; this is the shop's own product\n";
        echo "#   conflict     — both claim an owner AND they are not the same\n";
        echo "#   unreadable   — a read failed. NOT zero.\n";
        echo "# min/max/step are printed RAW for all three Min Max prefixes.\n";
        echo "# Which prefix is in force comes from:\n";
        echo "#   wp eval-file tools/plugin-exit-inventory.php minmax\n";
    }

    // Who is a Dokan vendor: the seller role, matched the way core matches a
    // role — a key inside the serialised capabilities blob.
    $sellers = [];
    $sellerRows = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT user_id FROM `' . $wpdb->usermeta . '`
             WHERE meta_key = %s AND meta_value LIKE %s',
            $wpdb->prefix . 'capabilities',
            '%"seller"%'
        ),
        ARRAY_A
    );
    $sellersReadable = is_array($sellerRows);
    foreach ($sellerRows ?: [] as $sellerRow) {
        $sellers[(int) $sellerRow['user_id']] = true;
    }

    $tmcTable = $wpdb->prefix . 'tmc_products';
    $tmcExists = (string) $wpdb->get_var(
        $wpdb->prepare('SHOW TABLES LIKE %s', $tmcTable)
    ) === $tmcTable;

    // ----------------------------------------------------------- commission
    //
    // ⛔ WHAT THIS MEASURES, AND WHY NO SCREEN MEASURED IT BEFORE.
    //
    // This plugin reads exactly two meta keys for a commission —
    // `_tmc_commission_type` and `_tmc_commission_value`, the two constants on
    // `WpTectebCommissionSource` — and `src/` holds ZERO references to any
    // Dokan commission key, measured by search. `RecordCommission` then treats
    // a missing rule as `none`, and `none` is A RESULT RATHER THAN A REFUSAL:
    // «نبود نرخ مانع … فروش نیست». Both halves are the owner's own decision
    // and neither is a defect.
    //
    // Together they say one thing that nothing on screen said: a product whose
    // commission lives only in Dokan's metadata sells, AFTER Dokan is gone, at
    // ZERO marketplace commission — quietly, with a ledger that balances. The
    // owner's 2026-11-14 export counts 148 main products carrying Dokan
    // commission metadata, so the number of products this could touch is not
    // hypothetical. It is still not a defect in either plugin; it is a
    // migration fact nobody could see, which is what an audit is for.
    //
    // The keys are taken from the plugin when the plugin is loaded, so this
    // tool cannot drift from the reader, and it SAYS which it used.
    $tmcTypeMeta = '_tmc_commission_type';
    $tmcValueMeta = '_tmc_commission_value';
    $tmcKeysFrom = 'literal';
    $sourceClass = 'Tecteb\\Marketplace\\Modules\\Finance\\Infrastructure\\WooCommerce\\WpTectebCommissionSource';
    if (class_exists($sourceClass)) {
        $tmcTypeMeta = (string) constant($sourceClass . '::TYPE_META');
        $tmcValueMeta = (string) constant($sourceClass . '::VALUE_META');
        $tmcKeysFrom = 'plugin';
    }

    // Which commission keys exist on this site AT ALL — discovered, not typed.
    $keyRows = $wpdb->get_results(
        'SELECT pm.meta_key AS k, COUNT(*) AS n
         FROM `' . $wpdb->postmeta . '` pm
         INNER JOIN `' . $wpdb->posts . '` p ON p.ID = pm.post_id
         WHERE p.post_type IN (\'product\', \'product_variation\')
           AND pm.meta_key LIKE \'%commission%\'
         GROUP BY pm.meta_key
         ORDER BY pm.meta_key ASC',
        ARRAY_A
    );
    $keysReadable = is_array($keyRows);
    $commissionKeys = [];
    foreach ($keyRows ?: [] as $keyRow) {
        $commissionKeys[(string) $keyRow['k']] = (int) $keyRow['n'];
    }
    $foreignKeys = [];
    foreach (array_keys($commissionKeys) as $key) {
        if ($key !== $tmcTypeMeta && $key !== $tmcValueMeta) {
            $foreignKeys[] = $key;
        }
    }

    $posts = $wpdb->get_results(
        'SELECT p.ID, p.post_type, p.post_parent, p.post_author, p.post_status, p.post_title, p.post_name
         FROM `' . $wpdb->posts . '` p
         WHERE p.post_type IN (\'product\', \'product_variation\')
         ORDER BY p.ID ASC',
        ARRAY_A
    );
    if (!is_array($posts)) {
        $line('products', $unreadable);
        return;
    }

    if ($csv) {
        $row([
            'wc_id', 'type', 'parent_id', 'post_status', 'title', 'slug',
            'post_author', 'author_is_dokan_seller',
            'tmc_vendor', 'tmc_link_ownership', 'tmc_status', 'ownership',
            'sku', 'regular_price', 'sale_price',
            'manage_stock', 'stock', 'backorders', 'purchasable',
            'minmax_plain', 'minmax_s', 'minmax_pro',
            'categories', 'brand', 'attributes', 'permalink',
            'tmc_commission_type', 'tmc_commission_value', 'tmc_commission_source',
            'foreign_commission_meta', 'commission_after_exit',
        ]);
    }

    $counts = [
        'marketplace' => 0, 'observed' => 0, 'dokan_only' => 0,
        'shop_only' => 0, 'conflict' => 0, 'unreadable' => 0,
    ];
    $statuses = [];
    $types = [];
    $conflicts = [];
    $unreadables = [];
    $commissionCounts = [];
    $uncoveredIds = [];
    $wooThere = function_exists('wc_get_product');

    foreach ($posts as $post) {
        $id = (int) $post['ID'];
        $author = (int) $post['post_author'];
        $isSeller = $sellersReadable ? isset($sellers[$author]) : null;

        $tmcVendor = null;
        $tmcOwnership = null;
        $tmcStatus = null;
        $tmcRead = true;
        if ($tmcExists) {
            $tmcRow = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT vendor_user_id, link_ownership, status FROM `' . $tmcTable . '`
                     WHERE wc_product_id = %d LIMIT 1',
                    $id
                ),
                ARRAY_A
            );
            if ($tmcRow === null && $wpdb->last_error !== '') {
                $tmcRead = false;
            } elseif (is_array($tmcRow)) {
                $tmcVendor = (int) $tmcRow['vendor_user_id'];
                $tmcOwnership = (string) $tmcRow['link_ownership'];
                $tmcStatus = (string) $tmcRow['status'];
            }
        } else {
            $tmcRead = false;
        }

        // ⛔ Six answers, in one place, and `conflict` is not «both exist» —
        // it is «both exist and they name different owners». A product the
        // marketplace took over keeps its `post_author`, on purpose, so a
        // transferred product has BOTH and they agree.
        if (!$tmcRead || $isSeller === null) {
            $ownership = 'unreadable';
            $unreadables[] = $id;
        } elseif ($tmcVendor !== null && $isSeller && $tmcVendor !== $author) {
            $ownership = 'conflict';
            $conflicts[] = $id . ':tmc=' . $tmcVendor . ',author=' . $author;
        } elseif ($tmcOwnership === 'marketplace') {
            $ownership = 'marketplace';
        } elseif ($tmcVendor !== null) {
            $ownership = 'observed';
        } elseif ($isSeller) {
            $ownership = 'dokan_only';
        } else {
            $ownership = 'shop_only';
        }
        $counts[$ownership]++;
        $statuses[(string) $post['post_status']] = ($statuses[(string) $post['post_status']] ?? 0) + 1;
        $types[(string) $post['post_type']] = ($types[(string) $post['post_type']] ?? 0) + 1;

        $meta = static function (string $key) use ($metaOf, $id): string {
            return $metaOf($id, $key);
        };
        $triple = static function (string $prefix) use ($meta): string {
            return $meta($prefix . 'min_quantity') . '/'
                . $meta($prefix . 'max_quantity') . '/'
                . $meta($prefix . 'product_step');
        };

        $sku = $meta('_sku');
        $regular = $meta('_regular_price');
        $sale = $meta('_sale_price');
        $manage = $meta('_manage_stock');
        $stock = $meta('_stock');
        $backorders = $meta('_backorders');

        $purchasable = $unreadable;
        $permalink = '';
        $categories = '';
        $brand = '';
        $attributes = '';
        if ($wooThere && (string) $post['post_type'] === 'product') {
            $product = wc_get_product($id);
            if (is_object($product)) {
                $purchasable = method_exists($product, 'is_purchasable')
                    ? ($product->is_purchasable() ? 'yes' : 'no')
                    : $unreadable;
                $permalink = method_exists($product, 'get_permalink')
                    ? (string) $product->get_permalink()
                    : '';
                if (method_exists($product, 'get_attributes')) {
                    $names = [];
                    foreach ((array) $product->get_attributes() as $key => $attribute) {
                        $names[] = (string) $key;
                    }
                    $attributes = implode('|', $names);
                }
            }
            $terms = get_the_terms($id, 'product_cat');
            if (is_array($terms)) {
                $paths = [];
                foreach ($terms as $term) {
                    $paths[] = (string) $term->term_id . ':' . (string) $term->name;
                }
                $categories = implode('|', $paths);
            }
            $brands = get_the_terms($id, 'product_brand');
            if (is_array($brands)) {
                $names = [];
                foreach ($brands as $term) {
                    $names[] = (string) $term->term_id . ':' . (string) $term->name;
                }
                $brand = implode('|', $names);
            }
        }

        // ⛔ THE RULE THE WAY THE PLUGIN READS IT: the variation's own key
        // first, then its parent's — `WpTectebCommissionSource::forItem()`.
        // Reading the parent first would report a rule the sale does not use.
        $parentId = (int) $post['post_parent'];
        $ownType = $metaOf($id, $tmcTypeMeta);
        $parentType = $parentId > 0 ? $metaOf($parentId, $tmcTypeMeta) : '';
        $ruleType = '';
        $ruleValue = '';
        $ruleSource = 'none';
        if ($ownType === $unreadable || $parentType === $unreadable) {
            $ruleSource = $unreadable;
        } elseif (trim($ownType) !== '') {
            $ruleType = trim($ownType);
            $ruleValue = $metaOf($id, $tmcValueMeta);
            $ruleSource = 'self';
        } elseif ($parentId > 0 && trim($parentType) !== '') {
            $ruleType = trim($parentType);
            $ruleValue = $metaOf($parentId, $tmcValueMeta);
            $ruleSource = 'parent';
        }

        // Every foreign commission key that actually HOLDS something on this
        // row or on its parent, printed raw. The value is printed because the
        // owner has to decide a number from it; the key is printed because
        // only the site can say which key Dokan used.
        $foreignHeld = [];
        foreach ($foreignKeys as $key) {
            foreach ([$id, $parentId] as $where) {
                if ($where <= 0) {
                    continue;
                }
                $held = $metaOf($where, $key);
                if ($held === '' || $held === 'a:0:{}') {
                    continue;
                }
                $foreignHeld[] = $key . '@' . $where . '=' . $held;
            }
        }

        // Five answers, and the third one is the finding.
        if ($ruleSource === $unreadable || !$keysReadable) {
            $afterExit = 'unreadable';
        } elseif ($ruleType !== '') {
            $afterExit = in_array($ruleType, ['none', 'percent', 'fixed'], true)
                ? 'rule_' . $ruleType
                : 'rule_type_unknown';
        } elseif ($foreignHeld !== []) {
            // No marketplace rule, and a commission was recorded somewhere
            // else. After the exit this line earns the marketplace NOTHING,
            // and nothing refuses the sale.
            $afterExit = 'no_rule_but_foreign_meta';
            $uncoveredIds[] = $id;
        } else {
            $afterExit = 'no_rule';
        }
        $commissionCounts[$afterExit] = ($commissionCounts[$afterExit] ?? 0) + 1;

        $cells = [
            $id, (string) $post['post_type'], (int) $post['post_parent'],
            (string) $post['post_status'], (string) $post['post_title'], (string) $post['post_name'],
            $author, $isSeller === null ? $unreadable : ($isSeller ? 'yes' : 'no'),
            $tmcVendor === null ? '' : $tmcVendor,
            $tmcOwnership ?? '', $tmcStatus ?? '', $ownership,
            $sku, $regular, $sale,
            $manage, $stock, $backorders, $purchasable,
            $triple('_'), $triple('_wcmmq_s_'), $triple('_wcmmq_'),
            $categories, $brand, $attributes, $permalink,
            $ruleType, $ruleValue, $ruleSource,
            implode('|', $foreignHeld), $afterExit,
        ];
        if ($csv) {
            $row($cells);
        }
    }

    if ($csv) {
        return;
    }

    $head('products: the invariant');
    $total = count($posts);
    $line('rows_read', $total);
    $sum = 0;
    foreach ($counts as $name => $n) {
        $line('ownership_' . $name, $n);
        $sum += $n;
    }
    $line('ownership_sum', $sum);
    // ⛔ The invariant, not today's number: every row lands in exactly one
    // bucket. If this says `no`, the tool is wrong and nothing below it means
    // anything.
    $line('ownership_sum_equals_rows', $sum === $total ? 'yes' : 'no');
    foreach ($types as $name => $n) {
        $line('type_' . $name, $n);
    }
    foreach ($statuses as $name => $n) {
        $line('status_' . $name, $n);
    }
    $line('conflict_ids', $conflicts === [] ? '(none)' : implode(' ', $conflicts));
    $line('unreadable_ids', $unreadables === [] ? '(none)' : implode(' ', $unreadables));
    $line('woocommerce_loaded', $wooThere ? 'yes' : 'no');
    $line('tmc_products_table', $tmcExists ? 'present' : 'absent');
    $line('seller_role_readable', $sellersReadable ? 'yes' : 'no');
    $line('dokan_sellers_found', $sellersReadable ? count($sellers) : $unreadable);
    echo "\n# The gate: ownership_sum_equals_rows=yes AND conflict_ids=(none)\n";
    echo "# AND unreadable_ids=(none). Anything else is a blocker, by id.\n";

    $head('commission: what each line would earn AFTER Dokan is gone');
    $line('commission_tmc_keys_from', $tmcKeysFrom);
    $line('commission_tmc_type_key', $tmcTypeMeta);
    $line('commission_tmc_value_key', $tmcValueMeta);
    if (!$keysReadable) {
        $line('commission_keys_discovered', $unreadable);
    } else {
        $discovered = [];
        foreach ($commissionKeys as $key => $n) {
            $discovered[] = $key . ':' . $n;
        }
        $line('commission_keys_discovered', $discovered === [] ? '(none)' : implode(' ', $discovered));
        $line('commission_foreign_keys', $foreignKeys === [] ? '(none)' : implode(' ', $foreignKeys));
    }
    $commissionSum = 0;
    foreach ($commissionCounts as $name => $n) {
        $line('commission_' . $name, $n);
        $commissionSum += $n;
    }
    $line('commission_sum_equals_rows', $commissionSum === $total ? 'yes' : 'no');
    $line('commission_uncovered_count', count($uncoveredIds));
    $line('commission_uncovered_ids', $uncoveredIds === [] ? '(none)' : implode(' ', $uncoveredIds));
    echo "\n# `no_rule_but_foreign_meta` is the one row that needs a decision\n";
    echo "# BEFORE the exit: this plugin reads only its own two keys, and a\n";
    echo "# missing rule is a RESULT, not a refusal — the line sells and earns\n";
    echo "# the marketplace nothing. `no_rule` with no foreign metadata is the\n";
    echo "# same deliberate answer on a product nobody ever charged for.\n";
    return;
}

// --------------------------------------------------------------- reconcile

if ($argv0 === 'reconcile') {
    if (!$csv) {
        $head('reconcile: every marketplace row against the shop, BY ID');
        echo "# §4 asks for a reconciliation that can be matched by id rather
";
        echo "# than by count: «حداقل باید بتوان ۱۸۴ محصول #102 را با مجموعه
";
        echo "# شناسه‌ها (نه تعداد صرف) تطبیق داد».
";
        echo "#
";
        echo "# The answer in the last column comes from the SAME rule the
";
        echo "# manager's screen uses (CatalogueLink), so this tool and that
";
        echo "# screen cannot disagree about one site. Seven answers:
";
        echo "#   linked       — a row and a product, one each
";
        echo "#   tmc_only     — the row names no shop product. NOT a fault
";
        echo "#   woo_missing  — it names an id the shop has not got
";
        echo "#   wrong_type   — the id is there and is not a product
";
        echo "#   duplicate    — two rows name ONE product
";
        echo "#   woo_only     — a stamped shop product with no row of ours
";
        echo "#   unreadable   — a read failed. NOT zero, NOT «gone».
";
        echo "#
";
        echo "# ⛔ IT CHANGES NOTHING. No row is deleted, relinked or moved:
";
        echo "# «اختلاف‌ها را خودکار پاک/منتقل نکن».
";
    }

    $table = $wpdb->prefix . 'tmc_products';
    if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        $line('tmc_products', 'absent');
        return;
    }

    // The classification, asked of the plugin's own rule when the plugin is
    // there. When it is not — this script can be run on a site where the
    // plugin is deactivated — the keys are spelled out here with the SAME
    // precedence, and the fact that the fallback was used is PRINTED, so
    // nobody reads these columns thinking the shared rule answered them.
    $ruleClass = 'Tecteb\\Marketplace\\Modules\\Product\\Domain\\CatalogueLink';
    $sharedRule = class_exists($ruleClass);
    $line('classified_by', $sharedRule ? 'plugin rule (CatalogueLink)' : 'this file (plugin not loaded)');
    $decide = static function (?int $wc, ?string $postType, bool $readable, int $naming) use ($sharedRule, $ruleClass): string {
        if ($sharedRule) {
            return $ruleClass::decide($wc, $postType, $readable, $naming);
        }
        if (!$readable) {
            return 'unreadable';
        }
        if ($wc === null || $wc <= 0) {
            return 'tmc_only';
        }
        if ($postType === null || $postType === '') {
            return 'woo_missing';
        }
        if ($postType !== 'product') {
            return 'wrong_type';
        }
        return $naming > 1 ? 'duplicate' : 'linked';
    };

    $total = $wpdb->get_var('SELECT COUNT(*) FROM `' . $table . '`');
    $line('tmc_rows_total', $total === null ? $unreadable : (int) $total);

    $rows = $wpdb->get_results(
        'SELECT p.id, p.vendor_user_id, p.wc_product_id, p.sku, p.type, p.status, p.link_ownership,
                p.price_minor, p.stock, wp.post_type, wp.post_status, wp.post_author,
                (SELECT COUNT(*) FROM `' . $table . '` d WHERE d.wc_product_id = p.wc_product_id) AS naming
           FROM `' . $table . '` p
           LEFT JOIN `' . $wpdb->posts . '` wp ON wp.ID = p.wc_product_id
          ORDER BY p.vendor_user_id ASC, p.id ASC',
        ARRAY_A
    );
    if (!is_array($rows)) {
        $line('rows', $unreadable);
        return;
    }
    $line('tmc_rows_read', count($rows));
    if ($total !== null && count($rows) !== (int) $total) {
        // Said out loud rather than quietly counted: a short read is the one
        // thing a reconciliation must never present as the whole set.
        $line('WARNING_short_read', 'read ' . count($rows) . ' of ' . (int) $total . ' — the tallies below are INCOMPLETE');
    }

    $wooThere = function_exists('wc_get_product');
    $line('woocommerce_loaded', $wooThere ? 'yes' : 'no');

    if ($csv) {
        $row([
            'vendor_user_id', 'tmc_row_id', 'wc_product_id', 'sku', 'tmc_type', 'tmc_status',
            'link_ownership', 'wc_post_type', 'wc_post_status', 'wc_post_author',
            'rows_naming_it', 'wc_product_type', 'wc_min_price', 'wc_max_price',
            'wc_stock_status', 'wc_stock_quantity', 'variation_ids', 'purchasable', 'answer',
        ]);
    }

    $byVendor = [];
    foreach ($rows as $r) {
        $vendor = (int) $r['vendor_user_id'];
        $wc = $r['wc_product_id'] === null ? null : (int) $r['wc_product_id'];
        $postType = $r['post_type'] === null ? null : (string) $r['post_type'];

        // The shop's own answer about this product, asked product by product
        // and allowed to say «no»: THIS is where a per-row `unreadable` is
        // real, because `wc_get_product()` can refuse one id.
        $readable = true;
        $wcType = '';
        $minPrice = '';
        $maxPrice = '';
        $stockStatus = '';
        $stockQty = '';
        $variations = '';
        $purchasable = '';
        if ($wc !== null && $wc > 0 && $postType === 'product') {
            if (!$wooThere) {
                $wcType = $unreadable;
                $purchasable = $unreadable;
            } else {
                $product = wc_get_product($wc);
                if (!is_object($product)) {
                    // The post is there and WooCommerce will not load it.
                    // «نتوانستم بخوانم» — not «گم شده» and not «۰».
                    $readable = false;
                    $wcType = $unreadable;
                    $purchasable = $unreadable;
                } else {
                    $wcType = method_exists($product, 'get_type') ? (string) $product->get_type() : $unreadable;
                    $minPrice = method_exists($product, 'get_price') ? (string) $product->get_price() : $unreadable;
                    $maxPrice = $minPrice;
                    $stockStatus = method_exists($product, 'get_stock_status') ? (string) $product->get_stock_status() : $unreadable;
                    $qty = method_exists($product, 'get_stock_quantity') ? $product->get_stock_quantity() : null;
                    $stockQty = $qty === null ? '' : (string) (int) $qty;
                    $purchasable = method_exists($product, 'is_purchasable')
                        ? ($product->is_purchasable() ? 'yes' : 'no')
                        : $unreadable;
                    if ($wcType === 'variable' && method_exists($product, 'get_children')) {
                        $children = array_map('intval', (array) $product->get_children());
                        $variations = implode('|', $children);
                        // The range a buyer sees, which on a variable product
                        // is NOT the parent's own price.
                        $prices = [];
                        foreach ($children as $child) {
                            $variation = wc_get_product($child);
                            if (!is_object($variation) || !method_exists($variation, 'get_price')) {
                                continue;
                            }
                            $price = (string) $variation->get_price();
                            if ($price !== '') {
                                $prices[] = (float) $price;
                            }
                        }
                        $minPrice = $prices === [] ? '' : (string) min($prices);
                        $maxPrice = $prices === [] ? '' : (string) max($prices);
                    }
                }
            }
        }

        $answer = $decide($wc, $postType, $readable, max(1, (int) $r['naming']));
        $byVendor[$vendor][$answer][] = (int) $r['id'];

        if ($csv) {
            $row([
                $vendor, (int) $r['id'], $wc ?? '', (string) $r['sku'], (string) $r['type'],
                (string) $r['status'], (string) $r['link_ownership'],
                $postType ?? '', $r['post_status'] ?? '', $r['post_author'] ?? '',
                max(1, (int) $r['naming']), $wcType, $minPrice, $maxPrice,
                $stockStatus, $stockQty, $variations, $purchasable, $answer,
            ]);
        }
    }

    // The other direction, by id: a shop product carrying OUR vendor stamp
    // with no row of ours. `post_author` is deliberately not used for this —
    // a product authored by a Dokan seller is evidence about Dokan.
    $orphans = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT wp.ID, CAST(m.meta_value AS CHAR) AS vendor, wp.post_status
               FROM `' . $wpdb->postmeta . '` m
               INNER JOIN `' . $wpdb->posts . '` wp ON wp.ID = m.post_id AND wp.post_type = %s
               LEFT JOIN `' . $table . '` t ON t.wc_product_id = wp.ID
              WHERE m.meta_key = %s AND t.id IS NULL
              ORDER BY wp.ID ASC',
            'product',
            '_tmc_vendor_id'
        ),
        ARRAY_A
    );
    if (!is_array($orphans)) {
        $line('woo_only', $unreadable);
    } else {
        foreach ($orphans as $orphan) {
            $vendor = (int) $orphan['vendor'];
            $byVendor[$vendor]['woo_only'][] = (int) $orphan['ID'];
            if ($csv) {
                $row([
                    $vendor, '', (int) $orphan['ID'], '', '', '', '',
                    'product', (string) $orphan['post_status'], '',
                    0, '', '', '', '', '', '', '', 'woo_only',
                ]);
            }
        }
    }

    if ($csv) {
        return;
    }

    ksort($byVendor);
    foreach ($byVendor as $vendor => $answers) {
        $head('shop ' . $vendor);
        ksort($answers);
        $sum = 0;
        foreach ($answers as $answer => $ids) {
            sort($ids);
            $sum += count($ids);
            $line($answer . '_count', count($ids));
            // ⛔ THE IDS, because §4 asks for «با شناسه و علت» and a count
            // cannot be reconciled against anything.
            $line($answer . '_ids', implode(',', $ids));
        }
        $line('rows_plus_woo_only', $sum);
    }
    return;
}

// ------------------------------------------------------------------- finance

/**
 * WHY THE QUEUE IS NOT MOVING — and whose queue it is.
 *
 * §6 asks for this one read-only and says what it must not do: «هیچ اجرای
 * کورکورانه، حذف صف تاریخی یا تغییر cron میزبان»، and «کار
 * `rocket_saas_pending_jobs` را اشتباهاً خطای TMC اعلام نکن». So this verb
 * runs nothing, cancels nothing and schedules nothing. It prints four facts
 * and lets a person read them:
 *
 *  1. which backend `tmc_run_jobs` is actually on, from the same derivation
 *     the panel uses (`WpJobScheduler::status()`) — one rule, two readers;
 *  2. when it is next due and how far in the PAST that is, because an action
 *     whose time passed weeks ago still answers «scheduled» to both backends
 *     and nothing re-schedules it;
 *  3. our own queue: how many rows are waiting, running or failed;
 *  4. ⛔ and Action Scheduler's backlog BY HOOK, every plugin's included.
 *
 * The fourth is the point of the verb. A pending `tmc_run_jobs` and a pending
 * `rocket_saas_pending_jobs` side by side say something a count of ours alone
 * cannot: the runner on this host is behind for EVERYBODY, so the fault is
 * the host's cron and not this plugin's scheduling. Printed as hook names and
 * counts — no payloads, no arguments, nothing a hook's arguments might carry.
 */
if ($argv0 === 'cron') {
    $head('What drives the queue');

    if (class_exists(\Tecteb\Marketplace\Infrastructure\WordPress\WpJobScheduler::class)) {
        $status = \Tecteb\Marketplace\Infrastructure\WordPress\WpJobScheduler::status();
        $line('binding', (string) $status['binding']);
        $line('next_run_at', $status['next'] > 0 ? gmdate('Y-m-d H:i:s', (int) $status['next']) . ' UTC' : 'not_scheduled');
        $line('overdue_seconds', (int) $status['overdue']);
        $line('last_tick_at', $status['last_tick'] > 0 ? gmdate('Y-m-d H:i:s', (int) $status['last_tick']) . ' UTC' : 'never');
        $line('last_run_at', $status['last_run'] > 0 ? gmdate('Y-m-d H:i:s', (int) $status['last_run']) . ' UTC' : 'never');
        $line('last_run_started_by', (string) $status['last_run_how']);
    } else {
        // The plugin is not loaded; say so rather than deriving the binding a
        // second time here (`alpha.78`).
        $line('binding', 'plugin_not_loaded');
    }
    $line('DISABLE_WP_CRON', defined('DISABLE_WP_CRON') && constant('DISABLE_WP_CRON') ? 'yes' : 'no');
    $line('ALTERNATE_WP_CRON', defined('ALTERNATE_WP_CRON') && constant('ALTERNATE_WP_CRON') ? 'yes' : 'no');

    $head('Our own queue');
    $jobs = $wpdb->prefix . 'tmc_jobs';
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $jobs));
    if ((string) $exists !== $jobs) {
        $line('tmc_jobs', 'absent');
    } else {
        $rows = $wpdb->get_results(
            'SELECT status, COUNT(*) AS rows_seen FROM `' . $jobs . '` GROUP BY status',
            ARRAY_A
        );
        if ($rows === null) {
            $line('tmc_jobs', $unreadable);
        } elseif ($rows === []) {
            $line('tmc_jobs_rows', 0);
        } else {
            foreach ($rows as $group) {
                $line('tmc_jobs:' . (string) ($group['status'] ?? '?'), (int) ($group['rows_seen'] ?? 0));
            }
        }
    }

    $head('Action Scheduler backlog, by hook — every plugin');
    $asActions = $wpdb->prefix . 'actionscheduler_actions';
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $asActions));
    if ((string) $exists !== $asActions) {
        // Not an error: without WooCommerce there is no Action Scheduler and
        // the binding above already said `wp_cron` or `wp_cron_disabled`.
        $line('actionscheduler_actions', 'absent');
    } else {
        $pending = $wpdb->get_results(
            'SELECT hook, status, COUNT(*) AS rows_seen,
                    MIN(scheduled_date_gmt) AS oldest_due
             FROM `' . $asActions . '`
             WHERE status IN ("pending", "in-progress", "failed")
             GROUP BY hook, status
             ORDER BY rows_seen DESC, hook ASC
             LIMIT 60',
            ARRAY_A
        );
        if ($pending === null) {
            $line('actionscheduler_actions', $unreadable);
        } elseif ($pending === []) {
            $line('actionscheduler_backlog', 0);
        } else {
            foreach ($pending as $group) {
                $hook = (string) ($group['hook'] ?? '?');
                $line(
                    'as:' . $hook . ':' . (string) ($group['status'] ?? '?'),
                    (int) ($group['rows_seen'] ?? 0) . ' oldest_due=' . (string) ($group['oldest_due'] ?? '')
                );
            }
            $line('as_note', 'a hook other than tmc_run_jobs waiting here is NOT a defect of this plugin');
        }
    }

    echo "\n# How to read this, and what NOT to do:\n";
    echo "#  • overdue_seconds > 0 with binding=action_scheduler means the\n";
    echo "#    Action Scheduler runner is not running on this host. Our action\n";
    echo "#    is on the calendar; nothing is firing it.\n";
    echo "#  • If other hooks are waiting too, the host cron is the subject.\n";
    echo "#  • Do NOT cancel or re-schedule actions and do NOT change the host\n";
    echo "#    cron on the strength of this output alone (§6). The panel's\n";
    echo "#    «اجرای دستی یک دسته» button moves one batch, safely.\n";
    return;
}

if ($argv0 === 'finance') {
    if (!$csv) {
        $head('dokan finance: rows, with their type and their unit');
        echo "# No totals here, on purpose: Dokan records a paid withdrawal\n";
        echo "# twice (a withdraw row AND a debit in the balance ledger), so\n";
        echo "# the closing balance has already subtracted it. Summing one\n";
        echo "# table is wrong in both directions. The rule that adds these up\n";
        echo "# lives in docs/FINANCIAL-RECONCILIATION-FA.md.\n";
    }

    $has = static function (string $suffix) use ($wpdb): bool {
        $name = $wpdb->prefix . $suffix;
        return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)) === $name;
    };
    $countOf = static function (string $suffix) use ($wpdb, $unreadable): string {
        $n = $wpdb->get_var('SELECT COUNT(*) FROM `' . $wpdb->prefix . $suffix . '`');
        return $n === null ? $unreadable : (string) (int) $n;
    };

    $head('which tables are there at all');
    foreach ([
        'dokan_orders', 'dokan_order_stats', 'dokan_vendor_balance',
        'dokan_withdraw', 'dokan_refund', 'dokan_announcement',
        'dokan_vendor_store', 'dokan_product_map',
    ] as $suffix) {
        $line('table_' . $suffix, $has($suffix) ? $countOf($suffix) . ' rows' : 'absent');
    }
    foreach ([
        'tmc_dokan_handover', 'tmc_dokan_orders', 'tmc_dokan_balance',
        'tmc_dokan_withdrawals', 'tmc_dokan_staff',
    ] as $suffix) {
        $line('our_archive_' . $suffix, $has($suffix) ? $countOf($suffix) . ' rows' : 'absent');
    }

    $head('the shops');
    $sellerRows = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT u.ID, u.display_name FROM `' . $wpdb->users . '` u
             INNER JOIN `' . $wpdb->usermeta . '` m ON m.user_id = u.ID
             WHERE m.meta_key = %s AND m.meta_value LIKE %s
             ORDER BY u.ID ASC',
            $wpdb->prefix . 'capabilities',
            '%"seller"%'
        ),
        ARRAY_A
    );
    if (!is_array($sellerRows)) {
        $line('sellers', $unreadable);
        return;
    }
    $line('seller_count', count($sellerRows));

    if ($csv) {
        $row(['vendor_user_id', 'section', 'key', 'value']);
    }

    foreach ($sellerRows as $seller) {
        $vendor = (int) $seller['ID'];
        if (!$csv) {
            $head('shop ' . $vendor . ' — ' . (string) $seller['display_name']);
        }
        $emit = static function (string $section, string $key, mixed $value) use ($csv, $row, $line, $vendor): void {
            if ($csv) {
                $row([$vendor, $section, $key, $value]);
                return;
            }
            $line($section . '.' . $key, $value);
        };

        // Selling permission, which is NOT the same question as active/inactive.
        $enabled = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT CAST(meta_value AS CHAR) FROM `' . $wpdb->usermeta . '`
                 WHERE user_id = %d AND meta_key = %s LIMIT 1',
                $vendor,
                'dokan_enable_selling'
            )
        );
        $emit('profile', 'dokan_enable_selling', $enabled === null ? '(absent)' : (string) $enabled);

        $owned = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT post_status, COUNT(*) AS n FROM `' . $wpdb->posts . '`
                 WHERE post_author = %d AND post_type = \'product\'
                 GROUP BY post_status ORDER BY post_status ASC',
                $vendor
            ),
            ARRAY_A
        );
        if (!is_array($owned)) {
            $emit('products', 'counts', $unreadable);
        } else {
            foreach ($owned as $bucket) {
                $emit('products', (string) $bucket['post_status'], (int) $bucket['n']);
            }
            if ($owned === []) {
                $emit('products', 'none', 0);
            }
        }

        // ⛔ `dokan_order_stats` is the 5.x reference, and `order_type`
        // separates parent from sub-order. Grouping by it is what stops the
        // same money being counted twice.
        if ($has('dokan_order_stats')) {
            $stats = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT order_type, order_status, COUNT(*) AS n,
                            CAST(SUM(order_total) AS CHAR) AS total,
                            CAST(SUM(net_amount) AS CHAR) AS net
                     FROM `' . $wpdb->prefix . 'dokan_order_stats`
                     WHERE seller_id = %d
                     GROUP BY order_type, order_status
                     ORDER BY order_type ASC, order_status ASC',
                    $vendor
                ),
                ARRAY_A
            );
            if (!is_array($stats)) {
                $emit('order_stats', 'rows', $unreadable);
            } elseif ($stats === []) {
                $emit('order_stats', 'rows', 0);
            } else {
                foreach ($stats as $stat) {
                    $emit(
                        'order_stats',
                        (string) $stat['order_type'] . '.' . (string) $stat['order_status'],
                        (int) $stat['n'] . ' orders, total=' . (string) $stat['total']
                            . ', net=' . (string) $stat['net']
                    );
                }
            }
        } else {
            $emit('order_stats', 'table', 'absent');
        }

        if ($has('dokan_vendor_balance')) {
            $balance = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT trn_type, COUNT(*) AS n,
                            CAST(SUM(debit) AS CHAR) AS debit,
                            CAST(SUM(credit) AS CHAR) AS credit
                     FROM `' . $wpdb->prefix . 'dokan_vendor_balance`
                     WHERE vendor_id = %d
                     GROUP BY trn_type ORDER BY trn_type ASC',
                    $vendor
                ),
                ARRAY_A
            );
            if (!is_array($balance)) {
                $emit('balance', 'rows', $unreadable);
            } elseif ($balance === []) {
                $emit('balance', 'rows', 0);
            } else {
                foreach ($balance as $bucket) {
                    $emit(
                        'balance',
                        (string) $bucket['trn_type'],
                        (int) $bucket['n'] . ' rows, debit=' . (string) $bucket['debit']
                            . ', credit=' . (string) $bucket['credit']
                    );
                }
            }
        } else {
            $emit('balance', 'table', 'absent');
        }

        // ⛔ Every state, not just `pending`. An empty list in the panel is a
        // sentence about `pending` alone.
        if ($has('dokan_withdraw')) {
            $withdrawals = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT status, method, COUNT(*) AS n, CAST(SUM(amount) AS CHAR) AS amount
                     FROM `' . $wpdb->prefix . 'dokan_withdraw`
                     WHERE user_id = %d
                     GROUP BY status, method ORDER BY status ASC, method ASC',
                    $vendor
                ),
                ARRAY_A
            );
            if (!is_array($withdrawals)) {
                $emit('withdraw', 'rows', $unreadable);
            } elseif ($withdrawals === []) {
                $emit('withdraw', 'rows', 0);
            } else {
                foreach ($withdrawals as $bucket) {
                    $emit(
                        'withdraw',
                        (string) $bucket['status'] . '.' . (string) $bucket['method'],
                        (int) $bucket['n'] . ' rows, amount=' . (string) $bucket['amount']
                    );
                }
            }
        } else {
            $emit('withdraw', 'table', 'absent');
        }

        if ($has('tmc_dokan_handover')) {
            $decided = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM `' . $wpdb->prefix . 'tmc_dokan_handover`
                     WHERE vendor_user_id = %d',
                    $vendor
                )
            );
            $emit('our_handover', 'rows', $decided === null ? $unreadable : (int) $decided);
        }
    }

    if (!$csv) {
        echo "\n# The gate: no `__unreadable__` anywhere above, and every row\n";
        echo "# accounted for in docs/FINANCIAL-RECONCILIATION-FA.md section 4.\n";
    }
    return;
}

fwrite(STDERR, "usage: wp eval-file tools/production-audit.php <products|finance|reconcile|cron> [--csv]\n");
