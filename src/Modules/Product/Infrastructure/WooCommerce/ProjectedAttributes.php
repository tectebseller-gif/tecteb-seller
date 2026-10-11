<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce;

/**
 * Which product attributes this plugin wrote — and WHAT IT WROTE THERE.
 *
 * Two rounds, two halves of one answer.
 *
 * `alpha.42` recognised its own attributes by a key prefix and could not,
 * because the keys handed to `set_attributes()` are thrown away. MEASURED on
 * WooCommerce 11.0.1 (`includes/abstracts/abstract-wc-product.php:1227`):
 *
 *     $attributes = array_fill_keys( array_keys( $this->get_attributes( 'edit' ) ), null );
 *     foreach ( $raw_attributes as $attribute ) {
 *         if ( is_a( $attribute, 'WC_Product_Attribute' ) ) {
 *             $attributes[ sanitize_title( $attribute->get_name() ) ] = $attribute;
 *         }
 *     }
 *
 * Three facts come out of those five lines:
 *
 *  1. **the key is the NAME** — `sanitize_title()` of the display name, which
 *     for a Persian label is percent-encoded. The key passed in is gone IN
 *     THE SAME CALL, so no prefix could ever have identified ours.
 *  2. **an omitted key is deleted, not kept.** Existing keys are pre-filled
 *     with `null` and the data store reads a `null` as «unset this one»
 *     (`class-wc-product-data-store-cpt.php:1053`), so the set passed in is
 *     the complete intended set.
 *  3. **so ownership has to be recorded, not recognised.**
 *
 * `alpha.43` recorded the KEYS and stopped there — and a key is not enough.
 * MEASURED on `alpha.43` with the same WooCommerce: the plugin wrote «برند»,
 * a manager changed it in the editor, the next ordinary projection put the
 * plugin's value back, and nothing anywhere said so:
 *
 *     brand_after_manager=برندی_که_مدیر_نوشته
 *     brand_after_projection=آریا_طب          ← the manager's edit, gone
 *     conflicts=
 *
 * Retiring a specification DELETED the manager's edited row, and renaming a
 * label deleted it too. All three because «the key is in the record» was read
 * as «nobody has touched it since».
 *
 * So the record is now `key => fingerprint`, and the fingerprint is of what
 * WooCommerce HELD after the save — not of what was sent, because WooCommerce
 * filters on the way in (the `alpha.26` rule). The rule per key:
 *
 *  - recorded, and the shop still holds that exact attribute → **ours**: free
 *    to rewrite, and free to drop when the vendor's data stops producing it.
 *  - recorded, and the shop holds something else → **held**: theirs stays,
 *    ours is not written, and the key is reported. Exactly `writeOwned()`'s
 *    rule for the storefront text, one object further down.
 *  - **not recorded** (a product `alpha.43` projected, or one from before any
 *    record existed) → the only safe test left is whether writing ours would
 *    change anything at all. If the stored attribute is identical in every
 *    persisted field, the write is a **no-op**, so taking the key is riskless
 *    — and that is the whole of the argument. It is NOT evidence that this
 *    plugin wrote it: an accidental match is still a match, and what makes it
 *    safe is that nothing happens either way. Anything else is held.
 *  - recorded, and the attribute is **gone**: a manager deleted it. There is
 *    no value to preserve, so it is written again if the vendor's data still
 *    produces it. Stated as a decision rather than discovered as a surprise:
 *    the alternative is a permanent tombstone that no screen could undo.
 *
 * A held key is written to `CONFLICT_META` so that a person can look, and the
 * way out is the manager's own: delete the attribute in WooCommerce and the
 * next projection writes a fresh one it owns.
 */
final class ProjectedAttributes
{
    /**
     * What this plugin last wrote: `key => fingerprint`.
     *
     * The same meta key `alpha.43` used for a plain list of keys, on purpose
     * — a second key would leave two records to disagree with each other, and
     * `normalise()` reads the old shape as «recorded, value unknown», which
     * is the conservative reading of exactly what that record said.
     */
    public const OWNED_META = '_tmc_attribute_keys';

    /** Keys we wanted and did not write, because somebody else holds them. */
    public const CONFLICT_META = '_tmc_attribute_conflicts';

    /** Reported in place of a key when the record itself could not be stored. */
    public const RECORD_NOT_STORED = '_record_not_stored';

    /**
     * The key WooCommerce will file an attribute under, computed its way.
     *
     * One function, called by everything that needs the key, so «what we
     * write» and «what we look for» cannot drift apart.
     */
    public static function keyFor(string $name): string
    {
        return (string) sanitize_title($name);
    }

    /**
     * Every field the data store persists, in one string.
     *
     * Position included: an attribute a manager dragged to another place in
     * the editor is an attribute a manager touched. `get_options()` is
     * compared by VALUE SEQUENCE because `wc_get_text_attributes()` keeps the
     * keys of whatever it filtered out.
     */
    public static function fingerprint(\WC_Product_Attribute $attribute): string
    {
        return hash('sha256', implode("\0", [
            (string) $attribute->get_name(),
            implode("\x1f", array_map('strval', array_values((array) $attribute->get_options()))),
            $attribute->get_visible() ? '1' : '0',
            $attribute->get_variation() ? '1' : '0',
            (string) (int) $attribute->get_position(),
            $attribute->is_taxonomy() ? '1' : '0',
        ]));
    }

    /**
     * The stored record, in one shape, whatever shape it is on disk.
     *
     * `alpha.43` wrote a list of keys. That record is true as far as it goes
     * — those keys were written by this plugin once — and it says nothing
     * about what is in them now, so it becomes `key => ''`: recorded, value
     * unknown, and therefore decided by the no-op test below rather than by
     * the record.
     *
     * @param mixed $stored
     * @return array<string,string> key => fingerprint ('' when unknown)
     */
    public static function normalise(mixed $stored): array
    {
        if (!is_array($stored)) {
            return [];
        }
        $out = [];
        foreach ($stored as $key => $value) {
            if (is_int($key)) {
                $out[(string) $value] = '';          // the `alpha.43` list shape
                continue;
            }
            $out[(string) $key] = is_string($value) ? $value : '';
        }
        return $out;
    }

    /**
     * Ours over ours, theirs left alone, and nothing of ours forced over theirs.
     *
     * @param array<string,\WC_Product_Attribute|null> $current what the product holds now
     * @param array<string,\WC_Product_Attribute>      $ours    keyed by keyFor(name)
     * @param array<string,string>                     $record  key => fingerprint, from normalise()
     * @return array{attributes:array<string,\WC_Product_Attribute>, wrote:list<string>, held:list<string>}
     */
    public static function merge(array $current, array $ours, array $record): array
    {
        $theirs = [];
        foreach ($current as $key => $attribute) {
            $key = (string) $key;
            if (!$attribute instanceof \WC_Product_Attribute) {
                // A `null` left by an earlier `set_attributes()`: the data
                // store reads it as «unset», so carrying it forward would be
                // carrying forward a deletion nobody asked for twice.
                continue;
            }
            if (self::isOurs($key, $attribute, $ours, $record)) {
                continue;       // re-added below only if we still write it
            }
            $theirs[$key] = $attribute;
        }

        $attributes = $theirs;
        $wrote = [];
        $held = [];
        foreach ($ours as $key => $attribute) {
            $key = (string) $key;
            if (isset($theirs[$key])) {
                $held[] = $key;
                continue;
            }
            $attributes[$key] = $attribute;
            $wrote[] = $key;
        }
        // A key we hold a record for whose attribute now belongs to somebody
        // else is reported too, even when this projection has nothing of its
        // own to put there — a retired specification a manager had edited is
        // the case, and silence about it would be the old defect wearing a
        // different hat.
        foreach (array_keys($record) as $key) {
            $key = (string) $key;
            if (isset($theirs[$key]) && !in_array($key, $held, true)) {
                $held[] = $key;
            }
        }

        return ['attributes' => $attributes, 'wrote' => $wrote, 'held' => $held];
    }

    /**
     * Whether this plugin may rewrite or drop the attribute sitting here.
     *
     * The record decides when it can; the no-op test decides when it cannot.
     *
     * @param array<string,\WC_Product_Attribute> $ours
     * @param array<string,string>                $record
     */
    private static function isOurs(
        string $key,
        \WC_Product_Attribute $attribute,
        array $ours,
        array $record
    ): bool {
        $recorded = $record[$key] ?? null;
        if (is_string($recorded) && $recorded !== '') {
            // Ours only while the shop still holds what we put there. This
            // single comparison is the whole of this round's fix.
            return hash_equals($recorded, self::fingerprint($attribute));
        }
        // No usable record: a product `alpha.43` projected, or one from before
        // any record existed. Writing ours is permitted only where it would
        // change nothing — see the class docblock on why that is safety
        // rather than proof.
        return isset($ours[$key]) && self::same($attribute, $ours[$key]);
    }

    /** Whether writing `$ours` over `$theirs` would change nothing at all. */
    private static function same(\WC_Product_Attribute $theirs, \WC_Product_Attribute $ours): bool
    {
        return hash_equals(self::fingerprint($theirs), self::fingerprint($ours));
    }
}
