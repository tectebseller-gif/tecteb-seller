<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Support;

/**
 * The one place a stored UTC timestamp becomes a time a person reads, and a
 * date a person typed becomes a boundary in UTC.
 *
 * ⛔ **Measured on staging, and the reason this class exists.** The audit page
 * printed order 12299's capture about three and a half hours off Iran time,
 * because it printed `created_at` exactly as stored — UTC — with no
 * conversion at all (`$record->createdAtUtc->format('Y-m-d H:i')`). The other
 * half was quieter and worse: the «از تاریخ» filter put the typed date
 * straight into the query as `… 00:00:00`, so on a +03:30 site «from the 9th»
 * silently dropped every event between midnight and 03:30 local on the 9th
 * and silently included the last three and a half hours of the 8th.
 *
 * **Stored in UTC, rendered in the site's zone, filtered through the same
 * zone.** All three are the same decision and this class is the only place it
 * is made, so a screen and a query cannot disagree about which day a row is
 * on.
 *
 * ⛔ **Iran's offset is nowhere in this file, by design.** «timezone ایران
 * به‌صورت ثابت در کد نوشته نشود» — the zone arrives as a `DateTimeZone` the
 * caller read from WordPress's own setting, so a site in another zone is
 * right for the same reason, and a site that changes its setting is right
 * from the next request. `utc()` is the honest default for a build that has
 * no setting to read: it reproduces every release before this one rather than
 * guessing a country.
 *
 * **DST is handled by not being special-cased.** A local date whose midnight
 * does not exist — a spring-forward morning — is resolved by PHP's own
 * constructor, which is the only thing in this process that knows the
 * transition table. Iran abolished DST in 2022; this class does not know that
 * and does not need to.
 */
final class SiteTime
{
    public function __construct(private readonly \DateTimeZone $zone)
    {
    }

    /** The zone a build with no setting to read falls back to. */
    public static function utc(): self
    {
        return new self(new \DateTimeZone('UTC'));
    }

    public function zone(): \DateTimeZone
    {
        return $this->zone;
    }

    /** A stored UTC instant, in the site's own zone. */
    public function render(\DateTimeImmutable $utc, string $format = 'Y-m-d H:i'): string
    {
        return $utc->setTimezone($this->zone)->format($format);
    }

    /**
     * The first instant of a local date, as a UTC `DATETIME` string.
     *
     * `null` when the text is not a date this class can place. Not a
     * fallback to «today» or to the epoch: a filter built from an unparseable
     * date would answer about a window nobody asked for, and the caller's job
     * is to leave the filter off instead.
     */
    public function dayStartUtc(string $localDate): ?string
    {
        return $this->boundary($localDate, '00:00:00');
    }

    /**
     * The last instant of a local date, as a UTC `DATETIME` string.
     *
     * `23:59:59` rather than an exclusive next-day bound because the column
     * is a `DATETIME` with second precision (migration 1), so the two are the
     * same set of rows and this one keeps the query's existing `<=`.
     */
    public function dayEndUtc(string $localDate): ?string
    {
        return $this->boundary($localDate, '23:59:59');
    }

    private function boundary(string $localDate, string $time): ?string
    {
        $date = trim($localDate);
        // The shape an `<input type="date">` sends, and the only shape
        // accepted: anything else is a caller's guess about what somebody
        // meant, and `strtotime()`-style leniency on a date filter turns a
        // typo into a window.
        //
        // ⛔ **MEASURED UNREACHABLE, and kept.** The falsification loop of
        // `alpha.99` removed this check and nothing went red, so it was
        // measured rather than argued: every input it rejects is also
        // rejected below, because the round-trip test demands
        // `format('Y-m-d') === $date` and only a `Y-m-d` string can satisfy
        // that — anything else either throws in the constructor or comes back
        // formatted differently. It stays because it is the cheap half and it
        // says what the contract IS, and because the two halves fail
        // differently: this one refuses a shape, the one below refuses a date
        // that is not on the calendar. A later round that reads this as dead
        // code and deletes it loses the statement of intent, so
        // `DateFiltersRefuseLenientInputTest` asserts both halves in the
        // source — the `alpha.95`/`alpha.77` answer to a double guard whose
        // other half is the rule itself.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }
        try {
            $local = new \DateTimeImmutable($date . ' ' . $time, $this->zone);
        } catch (\Exception) {
            return null;
        }
        // A real calendar date, not a rolled-over one: PHP accepts
        // `2026-02-30` and answers 2 March, which would filter a window the
        // person did not type.
        if ($local->format('Y-m-d') !== $date) {
            return null;
        }
        return $local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
