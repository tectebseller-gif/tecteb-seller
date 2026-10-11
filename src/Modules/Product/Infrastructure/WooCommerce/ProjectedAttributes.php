<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce;

/**
 * Which product attributes this plugin wrote — and what that lets it change.
 *
 * `alpha.42` answered that question with a key prefix (`tmc-brand`,
 * `tmc-spec-…`) and the answer was wrong, because the array keys handed to
 * `set_attributes()` are thrown away. MEASURED on WooCommerce 11.0.1
 * (`includes/abstracts/abstract-wc-product.php:1227`):
 *
 *     $attributes = array_fill_keys( array_keys( $this->get_attributes( 'edit' ) ), null );
 *     foreach ( $raw_attributes as $attribute ) {
 *         if ( is_a( $attribute, 'WC_Product_Attribute' ) ) {
 *             $attributes[ sanitize_title( $attribute->get_name() ) ] = $attribute;
 *         }
 *     }
 *
 * Three facts come out of those five lines, and every rule below is one of
 * them:
 *
 *  1. **the key is the NAME.** Not the key we passed — `sanitize_title()` of
 *     the display name. For a Persian label that is percent-encoded, so
 *     «برند» lands under `%d8%a8%d8%b1%d9%86%d8%af` and nothing in the
 *     product starts with `tmc-`. It is lost IN THE SAME CALL, not at save
 *     time, so even a second projection inside one request could not see it.
 *  2. **an omitted key is deleted, not kept.** Existing keys are pre-filled
 *     with `null`, and the data store treats a `null` as «unset this one»
 *     (`class-wc-product-data-store-cpt.php:1053`). So the set passed in is
 *     the complete intended set: whatever is left out goes away.
 *  3. **so ownership has to be recorded, not recognised.** The name is the
 *     only thing WooCommerce keeps, and a name is not a proof: a manager may
 *     have typed «برند» by hand long before this plugin ever wrote one.
 *
 * Hence `OWNED_META`: the list of keys the last projection wrote. A key in
 * that list is ours — we rewrite it, and we DROP it when the vendor's data no
 * longer produces it, which is how a retired specification and a renamed
 * label's old key disappear. A key not in that list is somebody else's and is
 * never touched.
 *
 * The one exception is deliberate and provable: if an unrecorded key already
 * holds an attribute IDENTICAL to the one we are about to write, writing ours
 * changes nothing, so adopting it is a no-op rather than a guess. That is the
 * product `alpha.42` projected before this record existed. Anything else
 * under a key we want is a COLLISION: theirs stays, ours is not written, and
 * the key is recorded in `CONFLICT_META` so a person can look. «نام مشابه
 * اثبات مالکیت نیست» — and the safe direction when ownership is unknown is
 * the one that deletes nothing.
 */
final class ProjectedAttributes
{
    /** @var string The attribute keys this plugin last wrote on a product. */
    public const OWNED_META = '_tmc_attribute_keys';

    /** @var string Keys we wanted but did not write, because somebody else holds them. */
    public const CONFLICT_META = '_tmc_attribute_conflicts';

    /**
     * The key WooCommerce will file an attribute under, computed the same way.
     *
     * One function, called by everything that needs the key, so «what we
     * write» and «what we look for» cannot drift apart.
     */
    public static function keyFor(string $name): string
    {
        return (string) sanitize_title($name);
    }

    /**
     * Ours over ours, theirs left alone, and nothing of ours forced over theirs.
     *
     * @param array<string,\WC_Product_Attribute|null> $current  what the product holds now
     * @param array<string,\WC_Product_Attribute>      $ours     keyed by keyFor(name)
     * @param list<string>                             $ownedKeys what we wrote last time
     * @return array{attributes:array<string,\WC_Product_Attribute>, owned:list<string>, conflicts:list<string>}
     */
    public static function merge(array $current, array $ours, array $ownedKeys): array
    {
        $owned = array_fill_keys(array_map('strval', $ownedKeys), true);
        $theirs = [];
        foreach ($current as $key => $attribute) {
            $key = (string) $key;
            if (!$attribute instanceof \WC_Product_Attribute) {
                // A `null` left by an earlier `set_attributes()`: the data
                // store reads it as «unset», so carrying it forward would be
                // carrying forward a deletion nobody asked for twice.
                continue;
            }
            if (isset($owned[$key])) {
                continue;       // ours — re-added below only if we still write it
            }
            if (isset($ours[$key]) && self::same($attribute, $ours[$key])) {
                continue;       // ours in all but the record; writing it changes nothing
            }
            $theirs[$key] = $attribute;
        }

        $attributes = $theirs;
        $nowOwned = [];
        $conflicts = [];
        foreach ($ours as $key => $attribute) {
            $key = (string) $key;
            if (isset($theirs[$key])) {
                $conflicts[] = $key;
                continue;
            }
            $attributes[$key] = $attribute;
            $nowOwned[] = $key;
        }

        return ['attributes' => $attributes, 'owned' => $nowOwned, 'conflicts' => $conflicts];
    }

    /**
     * Whether writing `$ours` over `$theirs` would change nothing at all.
     *
     * Every field the data store persists is compared, position included: a
     * check that let a difference through would turn «this is already ours»
     * into «overwrite whatever is there», which is the one thing the record
     * exists to prevent. Options are compared by VALUE sequence, because
     * `wc_get_text_attributes()` preserves the keys of whatever it filtered.
     */
    private static function same(\WC_Product_Attribute $theirs, \WC_Product_Attribute $ours): bool
    {
        return (string) $theirs->get_name() === (string) $ours->get_name()
            && array_values((array) $theirs->get_options()) === array_values((array) $ours->get_options())
            && (bool) $theirs->get_visible() === (bool) $ours->get_visible()
            && (bool) $theirs->get_variation() === (bool) $ours->get_variation()
            && (int) $theirs->get_position() === (int) $ours->get_position()
            && $theirs->is_taxonomy() === $ours->is_taxonomy();
    }
}
