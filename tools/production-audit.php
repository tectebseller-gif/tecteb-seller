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
 *  3. **It never prints a zero it could not measure.** A read that failed says
 *     `__unreadable__`. «نتوانستم بخوانم» and «صفر» are different work for a
 *     person, and conflating them is the one mistake this file is for.
 *
 * **What it prints is not secret**: ids, statuses, prices, stock, category and
 * brand names, table names and row counts. It prints no customer name, no
 * email, no phone, no address, no IBAN, no option value and no credential.
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "run this through: wp eval-file tools/production-audit.php <products|finance> [--csv]\n");
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

        $meta = static function (string $key) use ($wpdb, $id, $unreadable): string {
            $value = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT CAST(meta_value AS CHAR) FROM `' . $wpdb->postmeta . '`
                     WHERE post_id = %d AND meta_key = %s LIMIT 1',
                    $id,
                    $key
                )
            );
            if ($value === null) {
                return $wpdb->last_error !== '' ? $unreadable : '';
            }
            return (string) $value;
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
    return;
}

// ------------------------------------------------------------------- finance

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

fwrite(STDERR, "usage: wp eval-file tools/production-audit.php <products|finance> [--csv]\n");
