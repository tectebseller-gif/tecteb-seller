<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Infrastructure\WooCommerce;

/**
 * The two commission meta keys, guarded where every door passes through.
 *
 * **Why this class exists, and why the panel was not enough.** The panel is
 * the only writer *in this plugin* — `CommissionOwnershipTest` proves that by
 * searching for a second one — and `WpTectebCommissionSource` has no setter at
 * all. Both sentences are true, and both are about **our** code. They say
 * nothing about WooCommerce's, which writes whatever product meta it is handed:
 *
 *  - `WC_REST_Products_Controller` passes a request's `meta_data` array
 *    straight to `WC_Data::update_meta_data()`, and its permission is
 *    `edit_products` / `manage_woocommerce` — a `shop_manager` has those and
 *    does **not** have the marketplace's financial permission.
 *  - `WC_Product_CSV_Importer` turns any `meta:<key>` column into the same
 *    call, from the admin importer, with the same permission.
 *
 * So «فروشنده یا پرسنل بدون مجوز مدیریت مالی نباید بتواند کمیسیون را از طریق
 * فرم، CSV، درخواست دست‌کاری‌شده یا API تغییر دهد» (بند ۵-هـ) was enforced on
 * ONE of the four doors it names. This is the rule of `alpha.48` in its own
 * clothes: a write that happens «somewhere else» does not pass the guard that
 * is not on that path. The fix is not a fifth guard on a fifth door — it is
 * one guard at the single layer all of them end in, WordPress's own metadata
 * short-circuit filters (the mechanism `alpha.47` used to inject a write
 * failure, here used to refuse one).
 *
 * **A refusal, not a correction.** The filters answer `false`, which is what
 * `update_post_meta()` already means by «نوشته نشد» — so the panel, which reads
 * back after writing (`alpha.44`), would report its own write being refused
 * rather than claim success. Nothing is rewritten and nothing is silently
 * «fixed» to a default.
 *
 * ⛔ **`alpha.58` shipped this guard with two holes, and both were real.**
 * They are named here because the shape of the mistake is the point:
 *
 *  1. **Three filters were not all the doors.** WordPress has FIVE post-meta
 *     write entry points, not three: `add_metadata()`,
 *     `update_metadata()`, `delete_metadata()` — and the row-addressed
 *     `update_metadata_by_mid()` / `delete_metadata_by_mid()`, which fire
 *     `update_post_metadata_by_mid` / `delete_post_metadata_by_mid`. Those
 *     last two are **exactly** the path WooCommerce takes for a meta row it
 *     already knows: `WC_Data_Store_WP::update_meta()` calls
 *     `update_metadata_by_mid()` and `delete_meta()` calls
 *     `delete_metadata_by_mid()` (`class-wc-data-store-wp.php:132,155`), and
 *     a REST `meta_data` entry carrying an `id` — which the REST API itself
 *     hands out for every row — becomes precisely that. Measured on
 *     `alpha.58`: `$product->update_meta_data('_tmc_commission_value',
 *     '999999'); $product->save();` as a `shop_manager` ⇒
 *     `after_wc_object_save=999999`, and `delete_meta_data()` emptied the
 *     type. The fix that closed one door had left the door next to it open.
 *  2. **`===` is not how the database compares a key** — and `alpha.59`'s
 *     first answer to that, `strtolower(rtrim(…))`, was an **imitation of a
 *     Unicode collation written in PHP**, which is not a thing one does. It
 *     covered the two spellings that had been measured and missed the ones
 *     that had not: a zero-width space is IGNORABLE in a UCA collation and a
 *     full-width letter folds to its ASCII form, both measured `= 1` against
 *     `utf8mb4_unicode_520_ci` on this MariaDB. So the key now goes to the
 *     DATABASE whenever PHP cannot answer, and PHP only answers where
 *     measurement says it may: inside plain ASCII `[A-Za-z0-9_-]`, where
 *     case and trailing padding are the only ways two keys collate equal.
 *     The original form of the finding stands too:
 *     `wp_postmeta.meta_key` is `utf8mb4_unicode_520_ci` — case-insensitive
 *     and PAD SPACE — so `WHERE meta_key = '_TMC_COMMISSION_VALUE'` finds
 *     the lower-case row and overwrites it. Measured:
 *     `uppercase_write=landed value_after_uppercase=777777 rows_for_value=1`,
 *     and the same with one trailing space. PHP said «مال ما نیست», MySQL
 *     said «همان ردیف», and the write landed in the gap.
 *
 * Both are closed above and both have tests that go through the real shape.
 *
 * **And the doors are enumerated from core rather than remembered:** every
 * way a post meta ROW changes in WordPress passes one of the five filters
 * registered here. What does not is raw SQL — `$wpdb->update()` on
 * `wp_postmeta` — which no filter can intercept and which needs code
 * execution on the server, not a request.
 *
 * **And the limit is stated, not papered over: a request with no logged-in
 * user is NOT refused.** WP-CLI, cron and a migration have no actor to
 * authorise, and refusing them would break this plugin's own reset tools while
 * stopping nobody — every door clause (هـ) names arrives with a user, REST key
 * authentication and application passwords included, because WooCommerce
 * resolves those to one before the write. Somebody who can run PHP on the
 * server does not need a meta key.
 */
final class TectebCommissionMetaGuard
{
    public static function register(): void
    {
        // The three key-addressed verbs, with the same first three arguments
        // in the same positions: ($check, $objectId, $metaKey).
        add_filter('add_post_metadata', [self::class, 'decide'], 10, 3);
        add_filter('update_post_metadata', [self::class, 'decide'], 10, 3);
        add_filter('delete_post_metadata', [self::class, 'decide'], 10, 3);

        // …and the two ROW-addressed ones, which `alpha.58` missed and which
        // are the path WooCommerce itself takes. See the class docblock.
        add_filter('update_post_metadata_by_mid', [self::class, 'decideByRow'], 10, 4);
        add_filter('delete_post_metadata_by_mid', [self::class, 'decideByRow'], 10, 2);
    }

    public static function decide(mixed $check, mixed $objectId, mixed $metaKey): mixed
    {
        // Somebody earlier already decided — including another guard refusing.
        // We never overturn that; a guard that answers «yes» over a «no» is
        // not a guard.
        if ($check !== null) {
            return $check;
        }
        // The permission is asked FIRST, before the key is analysed at all:
        // an administrator saving a product writes dozens of meta rows and
        // must not pay for one lookup each.
        if (self::mayWrite()) {
            return $check;
        }

        return self::ours((string) $metaKey) ? false : $check;
    }

    /**
     * The row-addressed verbs: `update_metadata_by_mid()` and
     * `delete_metadata_by_mid()`.
     *
     * BOTH keys are asked, because a rename can move a row in either
     * direction: `update_metadata_by_mid()` takes a new `$metaKey`, so an
     * unguarded key renamed INTO a guarded one would install a rule, and a
     * guarded one renamed OUT would remove it. `$metaKey` is `false` when
     * nothing is being renamed, which is the ordinary case.
     *
     * The old key is read with `get_metadata_by_mid()` — one row by its
     * primary key, the same question the write is about. A row that cannot be
     * read that way is not one of our two rows, because ours exist and are
     * readable; so an empty old key is simply not ours, and refusing every
     * unreadable row instead would refuse every OTHER plugin's by-row meta
     * write on the site, which is not this guard's business. The one state
     * that would make that reasoning wrong — `get_metadata_by_mid()` missing
     * — has not existed since WordPress 3.3, and even then the three
     * key-addressed filters above still stand on every `update_post_meta()`
     * path.
     */
    public static function decideByRow(mixed $check, mixed $metaId, mixed $metaValue = null, mixed $metaKey = false): mixed
    {
        if ($check !== null) {
            return $check;
        }
        if (self::mayWrite()) {
            return $check;
        }

        $row = function_exists('get_metadata_by_mid')
            ? get_metadata_by_mid('post', (int) $metaId)
            : false;
        $oldKey = is_object($row) && isset($row->meta_key) ? (string) $row->meta_key : '';
        $newKey = is_string($metaKey) ? $metaKey : '';

        if (!self::ours($oldKey) && !self::ours($newKey)) {
            return $check;
        }

        return false;
    }

    /**
     * A row this guard is about — compared the way the DATABASE compares it.
     *
     * `===` was wrong, and `alpha.58` shipped it. `wp_postmeta.meta_key` is
     * `utf8mb4_unicode_520_ci` on this WordPress — case-insensitive and PAD
     * SPACE — so `UPDATE … WHERE meta_key = '_TMC_COMMISSION_VALUE'` finds
     * the row whose key is `_tmc_commission_value` and overwrites its value.
     * Measured: `uppercase_write=landed value_after_uppercase=777777` with
     * `rows_for_value=1`, and the same for one trailing space. PHP said «not
     * ours», MySQL said «same row», and the write landed in the gap.
     *
     * So the comparison is case-folded and right-trimmed. `rtrim()` strips a
     * little more than MySQL ignores (tabs and newlines as well as spaces),
     * which refuses slightly more than the database would match — and in a
     * guard that is the only safe direction to be wrong in (`alpha.46`).
     *
     * The key arrives ALREADY UNSLASHED: core's `update_metadata()` does
     * `wp_unslash()` before it applies the filter, so doing it again here
     * would corrupt a key containing a literal backslash.
     */
    private static function ours(string $metaKey): bool
    {
        $normalised = strtolower(rtrim($metaKey));
        if ($normalised === strtolower(WpTectebCommissionSource::TYPE_META)
            || $normalised === strtolower(WpTectebCommissionSource::VALUE_META)) {
            return true;
        }

        // Within plain ASCII `[A-Za-z0-9_-]`, the two checks above are
        // EXACTLY the collation — measured on this MariaDB with the column's
        // own collation rather than reasoned about:
        //
        //   '_TMC_COMMISSION_TYPE'  = '_tmc_commission_type'  → 1  (case)
        //   '_tmc_commission_type ' = '_tmc_commission_type'  → 1  (padding)
        //   '_tmccommission_type'   = '_tmc_commission_type'  → 0
        //   '-tmc-commission-type'  = '_tmc_commission_type'  → 0
        //   '_tmc_commission_type\t'= '_tmc_commission_type'  → 0
        //
        // so an ASCII key that is not one of ours cannot become one.
        if (preg_match('/^[A-Za-z0-9_-]*$/', $metaKey) === 1) {
            return false;
        }

        // Outside that set, PHP cannot answer. SAME measurement:
        //
        //   '_tmc_commission' + U+200B + '_type' = '_tmc_commission_type' → 1
        //   '_ＴMC_COMMISSION_TYPE'                                      → 1
        //
        // A zero-width space is IGNORABLE in a UCA collation and a full-width
        // letter folds to its ASCII form, so MySQL calls those the same key
        // and would overwrite the real row — while `===`, `strtolower()` and
        // `rtrim()` all call them different. Imitating a Unicode collation in
        // PHP is not a thing one does; so the question goes to the database
        // that will answer it when the write runs.
        return self::databaseCallsItOurs($metaKey);
    }

    /**
     * Does the DATABASE call this key one of ours, with its own collation?
     *
     * Asked only for a key with a character outside plain ASCII and only for
     * an actor who may not write ours, so the ordinary path costs nothing:
     * measured on this catalogue, **one** distinct key out of 972 rows is
     * outside that set (`attribute_%d8%a7…`, a WooCommerce variation key),
     * which is also why refusing every such key outright was the wrong fix —
     * it would stop variations saving.
     *
     * Memoised per key per request. A failure to ask is answered **ours**:
     * «نمی‌دانم» is not «مال ما نیست» (`alpha.49`), and for a key that
     * already looks like an attempt at ours the safe direction is to refuse.
     * The cost of being wrong that way is one unusual meta key a
     * non-privileged actor cannot write while this plugin is active.
     */
    private static function databaseCallsItOurs(string $metaKey): bool
    {
        /** @var array<string,bool> $answered */
        static $answered = [];
        if (array_key_exists($metaKey, $answered)) {
            return $answered[$metaKey];
        }

        $wpdb = $GLOBALS['wpdb'] ?? null;
        if (!is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_var')) {
            return $answered[$metaKey] = true;
        }

        [$charset, $collation] = self::metaKeyCollation($wpdb);
        if ($charset === '' || $collation === '') {
            return $answered[$metaKey] = true;
        }

        // Charset and collation are names, not values, so they cannot be
        // placeholders — hence the pattern check above before they are put
        // into SQL. The three keys ARE values and go through `prepare()`.
        $sql = $wpdb->prepare(
            'SELECT CONVERT(%s USING ' . $charset . ') COLLATE ' . $collation
            . ' IN (CONVERT(%s USING ' . $charset . ') COLLATE ' . $collation
            . ', CONVERT(%s USING ' . $charset . ') COLLATE ' . $collation . ')',
            $metaKey,
            WpTectebCommissionSource::TYPE_META,
            WpTectebCommissionSource::VALUE_META
        );
        $answer = is_string($sql) ? $wpdb->get_var($sql) : null;

        return $answered[$metaKey] = $answer === null ? true : (string) $answer === '1';
    }

    /**
     * The charset and collation of `wp_postmeta.meta_key`, asked of the table
     * rather than assumed — «ایندکس‌های یک جدول را از خودِ جدول بپرسید»
     * (`alpha.55`), and the same goes for its collation. Both are validated
     * against `[a-z0-9_]` before they reach SQL, since a name cannot be a
     * placeholder.
     *
     * @return array{0:string,1:string}
     */
    private static function metaKeyCollation(object $wpdb): array
    {
        /** @var array{0:string,1:string}|null $known */
        static $known = null;
        if ($known !== null) {
            return $known;
        }

        $table = isset($wpdb->postmeta) ? (string) $wpdb->postmeta : '';
        if ($table === '' || preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            return $known = ['', ''];
        }

        $sql = $wpdb->prepare(
            'SELECT CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            $table,
            'meta_key'
        );
        $row = is_string($sql) && method_exists($wpdb, 'get_row') ? $wpdb->get_row($sql, ARRAY_A) : null;
        $charset = is_array($row) ? (string) ($row['CHARACTER_SET_NAME'] ?? '') : '';
        $collation = is_array($row) ? (string) ($row['COLLATION_NAME'] ?? '') : '';
        if (preg_match('/^[a-z0-9_]+$/', $charset) !== 1 || preg_match('/^[a-z0-9_]+$/', $collation) !== 1) {
            return $known = ['', ''];
        }

        return $known = [$charset, $collation];
    }

    private static function mayWrite(): bool
    {
        if (!function_exists('get_current_user_id') || (int) get_current_user_id() === 0) {
            return true; // No actor. See the class docblock — stated, not claimed.
        }

        return function_exists('current_user_can')
            && current_user_can(TectebCommissionPanel::CAPABILITY);
    }
}
