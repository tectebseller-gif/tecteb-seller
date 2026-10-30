<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

use Tecteb\Marketplace\Modules\Finance\Domain\DecimalAmount;

/**
 * Who wins when the PRICE, the SKU or the WEIGHT has been edited by hand in
 * WooCommerce — and the answer is never «the copy we happen to be holding».
 *
 * **The defect this exists for, measured before a line of it was written.**
 * `project()` wrote all three unconditionally
 * (`WooCommerceProjector` lines 213, 217 and 349–356): the only condition any
 * of them had was emptiness. So the sequence the owner described — import a
 * product, edit its price in WooCommerce, let any later projection run — gave
 * `field_price_before=480000 field_price_after=450000
 * field_price_overwritten=yes`, and the same for the SKU and the weight, while
 * the three TEXT fields beside them survived untouched because
 * `ProjectedFieldOwnership` has bound ownership to the value we wrote since
 * `alpha.26`. The audit trail recorded a successful projection. Nothing
 * recorded a loss. Evidence: `docs/evidence/alpha82-audit2/run.txt`.
 *
 * ⛔ **And the repository had already written the right answer down, in a
 * method nobody runs.** `SyncCatalog::state()` says a price edited by hand in
 * wp-admin «is a person's action, and the honest answer is to say the two
 * disagree». `state()` only reports; `project()` one object along overwrote
 * it. Two true sentences about two questions, and a page that did not agree
 * with itself.
 *
 * **What this class is NOT.** It is not a second `ProjectedFieldOwnership`.
 * That class carries a proposal, a manager-owns flag and a decision on the
 * review screen, and the owner's instruction forbids exactly that here:
 * «برای اجرای این اصلاح، مالکیت فیلدها و مجوزهای موجود را به‌صورت کلی تغییر
 * نده» and «قیمت را وارد فرایند تأیید مدیر نکن». So nothing here asks for an
 * approval, nothing here holds a vendor value back as a proposal, and nothing
 * here is added to `ProjectedFieldOwnership::FIELDS`. The rule is about a
 * PROJECTION, not about an approval — which is also why it applies identically
 * to the weight, a field that is still sensitive, and to the price, which from
 * `alpha.83` is not.
 *
 * **Five answers, and the two that write are named apart so a test can pin
 * which arm fired:**
 *
 *  - `WRITE` — no record, and the shop already holds exactly what we would
 *    write. Written, and claimed. That is an argument about the write being a
 *    no-op, **not a proof of historical ownership** — accidental equality is
 *    still equality, and what makes it safe is that nothing happens either
 *    way (`alpha.44`'s rule, in its own words).
 *  - `WRITE_FRESH` — recorded, and the shop still holds what we last wrote.
 *    Nobody else has touched it, so the vendor's value goes out. **This is
 *    the arm that keeps «فروشنده همیشه مجاز است قیمت محصول خودش را تغییر
 *    دهد» true: an unedited shop never restricts the vendor.**
 *  - `WRITE_CONFLICT` — recorded, the shop moved AND our row moved. Both
 *    sides changed, so the fresh vendor value is applied and the conflict is
 *    reported with both numbers: «تغییر تازه باید اعمال شود» together with
 *    «تعارض را گزارش کن، نه اینکه بی‌صدا یکی را انتخاب کنی».
 *    **Also the answer when there is no record at all and the caller says our
 *    value is fresh** — see `decide()`, which is where an upgraded site's
 *    products live until something writes a record for them.
 *  - `KEEP_EDITED` — recorded, the shop moved, and our row is still exactly
 *    what we last sent. Writing it would restore the plugin's OLD copy over a
 *    person's edit, which is the one thing the owner named. Nothing is
 *    written and the difference is reported.
 *  - `KEEP_UNKNOWN` — no record, the two differ, and the caller is a
 *    PROJECTION. We have no evidence of ever writing this field, so we do not
 *    claim it. The way out is the
 *    manager's own and needs no approval: clear the value in WooCommerce and
 *    the next projection writes a fresh one this plugin owns.
 *  - `UNREADABLE` — a value that cannot be compared exactly (see
 *    `canonical()`). Not written, and named. «نمی‌دانم» is not «safe»
 *    (`alpha.68`).
 *  - `NO_VALUE` — we have nothing to write. Neither written nor reported, for
 *    the reason on the constant.
 *
 * **Comparison is by VALUE, not by text.** `'480000.00'` and `'480000'` are
 * one price and `'12.500'` and `'12.5'` are one weight; comparing the strings
 * would report an untouched field as edited on any shop whose decimal
 * settings differ from ours. `canonical()` does that conversion through
 * `DecimalAmount`, which is exact or refuses (`alpha.67`) — so a shop price
 * carrying real precision we cannot express is `UNREADABLE` rather than
 * rounded into agreement.
 */
final class ShopEditMerge
{
    public const PRICE = 'price';
    public const SKU = 'sku';
    public const WEIGHT = 'weight';

    /** @var list<string> */
    public const FIELDS = [self::PRICE, self::SKU, self::WEIGHT];

    public const WRITE = 'write';
    public const WRITE_FRESH = 'write_fresh';
    public const WRITE_CONFLICT = 'write_conflict';
    public const KEEP_EDITED = 'keep_edited';
    public const KEEP_UNKNOWN = 'keep_unknown';
    public const UNREADABLE = 'unreadable';

    /**
     * We have nothing to write into this field.
     *
     * Not a decision about ownership and not a conflict: an absent price, an
     * absent SKU and a zero weight were already «not written» before this
     * class existed, and each is reported where it belongs — `missingFields()`
     * names the price, `PRICE_HELD_META` records that the shop's own was left
     * alone. Reporting it a second time here would put a row in front of a
     * manager about a difference nobody is going to act on.
     */
    public const NO_VALUE = 'no_value';

    /**
     * The one form of a value that two sides can be compared in.
     *
     * The exponents are not a preference — each is the exact inverse of what
     * the projector writes, read off the projector:
     *
     *  - **price** — `set_regular_price((string) $details->priceMinor)`, so
     *    the shop's string IS our integer and exponent 0 is its inverse.
     *    `'480000.00'` converts cleanly (trailing zeros are not significant);
     *    `'480000.50'` does not, and is refused rather than rounded.
     *  - **weight** — `set_weight((string) round($grams / 1000, 3))`, so kg at
     *    three places is the inverse and the canonical form is grams.
     *    `'12.5'` is 12500; `'0.0125'` carries a precision our column cannot
     *    hold and is refused.
     *  - **sku** — text. Trimmed, because WooCommerce trims on save and a
     *    trailing space would otherwise read as somebody's edit.
     *
     * An EMPTY value is a value, not a failure: «this product has no price»
     * and «I could not read the price» are opposite answers, and flattening
     * them is the `alpha.8` fault in a new place. `null` means only the
     * second.
     */
    public static function canonical(string $field, string $value): ?string
    {
        $text = trim($value);
        if ($field === self::SKU) {
            return $text;
        }
        if ($text === '') {
            return '';
        }
        $exponent = $field === self::WEIGHT ? 3 : 0;
        $minor = DecimalAmount::toMinor($text, $exponent);
        return $minor === null ? null : (string) $minor;
    }

    /**
     * Whether a stored value is in the form `canonical()` produces.
     *
     * Not a conversion: see `normalise()` for why converting twice is wrong.
     * The canonical form of a price and a weight is an integer number of
     * minor units or grams — or `''`, which is «this product has none» and a
     * value in its own right. A SKU is text, so anything is in form.
     */
    public static function isCanonical(string $field, string $value): bool
    {
        if ($field === self::SKU) {
            return $value === trim($value);
        }
        return $value === '' || preg_match('/^-?\d+$/', $value) === 1;
    }

    /**
     * The record of one field: what we SENT, and what the shop held when we
     * read it back.
     *
     * Two values and not one, because they answer two different questions and
     * WooCommerce can make them differ — it filters on the way in
     * (`alpha.26`), and a site filter clamping a price would otherwise make
     * every later projection believe a person had edited it. `sent` is
     * compared against our row, `shop` against the shop.
     *
     * Both arguments must already be `canonical()` output. `normalise()`
     * CHECKS that on the way back in and drops a pair that is not — it does
     * not convert, because converting a canonical weight twice turns grams
     * into grams again.
     *
     * @return array{sent:string, shop:string}
     */
    public static function record(string $sentCanonical, string $readBackCanonical): array
    {
        return ['sent' => $sentCanonical, 'shop' => $readBackCanonical];
    }

    /**
     * The record as it is stored, in the one shape the readers expect.
     *
     * Anything else — a hand, an older build, a half-written row — reads as
     * «no record», which is the conservative answer: it sends every existing
     * field to the no-op test rather than to a licence.
     *
     * @return array<string,array{sent:string, shop:string}>
     */
    public static function normalise(mixed $stored): array
    {
        if (!is_array($stored)) {
            return [];
        }
        $out = [];
        foreach ($stored as $field => $pair) {
            if (!is_string($field) || !in_array($field, self::FIELDS, true) || !is_array($pair)) {
                continue;
            }
            $sent = $pair['sent'] ?? null;
            $shop = $pair['shop'] ?? null;
            if (!is_string($sent) || !is_string($shop)) {
                continue;
            }
            // ⛔ VALIDATED, NOT CONVERTED — and the difference is a bug this
            // class had for one draft.
            //
            // The stored pair is already `canonical()` output, and
            // `canonical()` is NOT idempotent for the weight: it converts
            // kilograms to grams, so putting a stored `12500` through it
            // again gives 12,500,000 and every weight reads as edited. The
            // price hides this (exponent 0 is a no-op) and only the weight
            // shows it, which is exactly the kind of defect that ships.
            //
            // So `decide()` compares the stored values directly, and the only
            // thing left to check here is that they LOOK like what this class
            // writes. A pair that does not — a hand, an older build, a
            // half-written row — is DROPPED, which reads as «no record»: the
            // conservative answer, and the one that sends the field to the
            // no-op test rather than to a licence.
            if (!self::isCanonical($field, $sent) || !self::isCanonical($field, $shop)) {
                continue;
            }
            $out[$field] = ['sent' => $sent, 'shop' => $shop];
        }
        return $out;
    }

    /**
     * @param array{sent:string, shop:string}|null $record what we last wrote
     * @param string $shop what WooCommerce holds right now
     * @param string $ours what this projection wants to write
     */
    public static function decide(
        string $field,
        ?array $record,
        string $shop,
        string $ours,
        bool $oursIsFresh = false
    ): string {
        if (trim($ours) === '') {
            return self::NO_VALUE;
        }
        $shopValue = self::canonical($field, $shop);
        $ourValue = self::canonical($field, $ours);
        if ($shopValue === null || $ourValue === null) {
            return self::UNREADABLE;
        }
        if ($record === null) {
            if ($shopValue === $ourValue) {
                // A write that changes nothing is safe either way.
                return self::WRITE;
            }
            // ⛔ No record, and the two differ. The STAMPS cannot tell a value
            // the vendor just typed from a copy this plugin has been holding
            // since before the record existed — but the CALLER can, and the
            // two callers are not the same question:
            //
            //  - a PROJECTION mirrors a row that may be months old, so it
            //    keeps and reports («همگام‌سازی نباید قیمت ویرایش‌شده را با
            //    نسخهٔ قدیمی ذخیره‌شده جایگزین کند»),
            //  - the vendor's OWN price change is written one statement
            //    earlier and pushed immediately, so it is fresh by
            //    construction, and it applies with the conflict recorded
            //    («این اختیار نباید محدود شود» + «تعارض باید ثبت و گزارش
            //    شود»).
            //
            // Without this split every product taken in by an older package
            // and then edited by hand — which is every product on staging —
            // would hold its vendor's price back for ever, and the upgrade
            // check is what measured it.
            return $oursIsFresh ? self::WRITE_CONFLICT : self::KEEP_UNKNOWN;
        }
        // Compared directly: the record arrives canonical from
        // `normalise()`, and canonicalising it a second time would convert
        // the weight's grams to grams again.
        if ($record['shop'] === $shopValue) {
            // Still exactly as we left it, so the vendor's value goes out
            // whether it moved or not — an untouched shop never restricts them.
            return self::WRITE_FRESH;
        }
        if ($record['sent'] === $ourValue) {
            // The shop moved and our row did not: writing would restore the
            // plugin's old copy over a person's edit.
            return self::KEEP_EDITED;
        }
        return self::WRITE_CONFLICT;
    }

    /** Whether an answer writes. */
    public static function writes(string $answer): bool
    {
        return in_array($answer, [self::WRITE, self::WRITE_FRESH, self::WRITE_CONFLICT], true);
    }

    /** Whether an answer is worth putting in front of a person. */
    public static function reports(string $answer): bool
    {
        return in_array($answer, [self::WRITE_CONFLICT, self::KEEP_EDITED, self::KEEP_UNKNOWN, self::UNREADABLE], true);
    }
}
