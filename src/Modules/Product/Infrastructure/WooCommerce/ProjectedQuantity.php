<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Modules\Product\Domain\PurchaseQuantity;

/**
 * The purchase-quantity rule on the shop's own product, and who owns it.
 *
 * Three meta rows, written by this plugin and readable by anything: the
 * quantity panel in WooCommerce admin, the filters that enforce the rule, and
 * a manager with a database client. They are the SHOP'S COPY — the source of
 * truth is the marketplace row (ADR-008 puts the vendor's selling rules on the
 * marketplace side, as it does price), and this is the projection of it.
 *
 * **Ownership is bound to the value we wrote, not to the key.** That is
 * `alpha.44`'s rule arriving at a second field, deliberately and for the same
 * reason: a record saying «this plugin wrote this row» says nothing about
 * whether anybody has touched it since, and a projection that reads it as a
 * licence overwrites a manager's edit without a word. So the record is
 * `key => fingerprint`, taken from the product READ BACK after the save
 * (WooCommerce filters on the way in — the `alpha.26` rule), and a row whose
 * current value does not match its fingerprint is somebody else's.
 *
 * Four answers, one per state:
 *
 *  - **no row at all** — nobody has claimed it. Written.
 *  - **recorded, and the shop still holds what we wrote** — ours. Written.
 *  - **recorded, and the shop holds something else** — a manager changed it.
 *    NOT written, and the key is reported in `CONFLICT_META`.
 *  - **a row with no record** — a product projected by `alpha.44` or earlier
 *    cannot have one, because nothing wrote these rows then; so in practice
 *    this is a row somebody else put there. Written only when it is already
 *    byte-identical to what we would write, because then writing is a no-op
 *    and taking the key costs nothing. **That is an argument about the write
 *    being harmless, not a proof of historical ownership** — accidental
 *    equality is still equality, and what makes it safe is that nothing
 *    happens either way.
 *
 * The way out is the manager's own: delete the row in WooCommerce and the next
 * projection writes a fresh one that this plugin owns.
 */
final class ProjectedQuantity
{
    public const META_MIN = '_tmc_min_purchase';
    public const META_MAX = '_tmc_max_purchase';
    public const META_STEP = '_tmc_purchase_step';

    /** `meta key => fingerprint of the value this plugin last wrote there`. */
    public const OWNED_META = '_tmc_quantity_written';

    /** Keys a manager holds, so the review screen can say so rather than guess. */
    public const CONFLICT_META = '_tmc_quantity_conflicts';

    /**
     * The manager's explicit grant that THIS MARKETPLACE ENFORCES THIS
     * PRODUCT'S PURCHASE-QUANTITY RULE — and nothing else about the product.
     *
     * Written only by the Min Max handover's `enforce()` action, on a product
     * whose rule this plugin wrote but does not enforce, because
     * `QuantityGuard::ruleFor()` returns before reading anything when
     * `PurchaseGuard::ask()` says the product is not ours. Those three rows
     * are the `written_unenforced` state: present, correct, and read by
     * nothing.
     *
     * It is a key of its own rather than a widening of ownership, and the
     * difference is the whole point: «این مجوز نباید خودبه‌خود فروشنده،
     * مالکیت محصول یا چرخهٔ تأیید آن را تغییر دهد». A granted product stays
     * `NOT_OURS` to `PurchasePolicy`, so the purchase guard, the storefront
     * stop, the projector, the vendor's catalogue and the approval cycle all
     * go on ignoring it exactly as before. The ONE thing that changes is that
     * the quantity rule written on it is now applied.
     *
     * Read by `QuantityGuard` straight off the product object it was handed,
     * so it costs no query; a variation with no grant of its own takes its
     * parent's, because a parent-level Min Max rule is granted on the parent.
     */
    public const ENFORCE_META = '_tmc_quantity_enforced';

    /** The record itself would not store — reported, never left stale. */
    public const RECORD_NOT_STORED = '_record_not_stored';

    /** @return list<string> */
    public static function keys(): array
    {
        return [self::META_MIN, self::META_MAX, self::META_STEP];
    }

    /**
     * The rule as the three meta rows express it.
     *
     * An empty maximum is `''` and not `'0'`: zero is a quantity and would be
     * read back as «nothing may be bought», while an empty string is how
     * «no maximum» has to look in a column that holds strings.
     *
     * @return array<string,string>
     */
    public static function values(PurchaseQuantity $rule): array
    {
        return [
            self::META_MIN => (string) $rule->min,
            self::META_MAX => $rule->max === null ? '' : (string) $rule->max,
            self::META_STEP => (string) $rule->step,
        ];
    }

    /**
     * The rule the three meta rows of a shop product express.
     *
     * A missing or empty minimum reads as 1 and a missing step as 1, which are
     * the semantics `PurchaseQuantity` writes down — so a product this plugin
     * has never projected answers «unrestricted» rather than «min 0», and
     * nothing downstream has to re-check.
     *
     * @param array<string,string> $meta
     */
    public static function ruleFrom(array $meta): PurchaseQuantity
    {
        $max = trim((string) ($meta[self::META_MAX] ?? ''));
        return PurchaseQuantity::of(
            (int) ($meta[self::META_MIN] ?? 1),
            $max === '' ? null : (int) $max,
            (int) ($meta[self::META_STEP] ?? 1)
        );
    }

    public static function fingerprint(string $value): string
    {
        return hash('sha256', $value);
    }

    /**
     * The fingerprint of a WHOLE RULE — the three values together.
     *
     * The enforcement grant is stored as this rather than as `'1'`, and that
     * is the difference between «the manager allowed this marketplace to
     * enforce 4/10/2» and «the manager allowed it to enforce whatever these
     * rows say next week». `alpha.49`'s rule, one object down: an override is
     * about the values that were shown, and anything else is a permission
     * nobody gave.
     *
     * A separator that cannot occur in a quantity value, so `1|12|1` and
     * `11|2|1` cannot collide.
     *
     * @param array<string,string> $meta the three rows, keyed by meta key
     */
    public static function ruleFingerprint(array $meta): string
    {
        $parts = [];
        foreach (self::keys() as $key) {
            $parts[] = trim((string) ($meta[$key] ?? ''));
        }
        return self::fingerprint(implode('|', $parts));
    }

    /**
     * The record as it is stored, in the one shape the readers expect.
     *
     * A value that is not a map of `key => fingerprint` — anything a hand or
     * an older build could have left — is read as «no record», which is the
     * conservative answer: it makes every existing row fall to the
     * byte-identical test rather than to a licence.
     *
     * @return array<string,string>
     */
    public static function normalise(mixed $stored): array
    {
        if (!is_array($stored)) {
            return [];
        }
        $out = [];
        foreach ($stored as $key => $fingerprint) {
            if (is_string($key) && in_array($key, self::keys(), true) && is_string($fingerprint)) {
                $out[$key] = $fingerprint;
            }
        }
        return $out;
    }

    /**
     * Which of the three rows this projection may write, and which it must not.
     *
     * @param array<string,string> $current what the shop holds, `key => value`,
     *                                      with ABSENT keys left out — «no row»
     *                                      and «a row holding nothing» are
     *                                      different answers and the caller
     *                                      must not flatten them
     * @param array<string,string> $ours    what this projection wants to write
     * @param array<string,string> $record  the stored `key => fingerprint`
     * @return array{values:array<string,string>, wrote:list<string>, held:list<string>}
     */
    public static function merge(array $current, array $ours, array $record): array
    {
        $values = [];
        $wrote = [];
        $held = [];
        foreach ($ours as $key => $want) {
            if (!array_key_exists($key, $current)) {
                $values[$key] = $want;      // nobody has claimed this row
                $wrote[] = $key;
                continue;
            }
            $theirs = $current[$key];
            if (isset($record[$key])) {
                if (hash_equals($record[$key], self::fingerprint($theirs))) {
                    $values[$key] = $want;  // ours, and still as we left it
                    $wrote[] = $key;
                    continue;
                }
                $held[] = $key;             // recorded, and changed since
                continue;
            }
            // No usable record. Only a write that changes nothing is safe.
            if (hash_equals(self::fingerprint($theirs), self::fingerprint($want))) {
                $values[$key] = $want;
                $wrote[] = $key;
                continue;
            }
            $held[] = $key;
        }
        return ['values' => $values, 'wrote' => $wrote, 'held' => $held];
    }
}
