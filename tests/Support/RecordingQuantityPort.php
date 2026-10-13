<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Modules\Migration\Application\ShopQuantityPortInterface;

/**
 * The destination's three quantity rows, in memory, with one refusal a test
 * can name.
 *
 * **Why a double here and the real port on real WordPress.** The question this
 * suite asks is about the UNIT OF WORK: when the destination write, the
 * ownership record, the history row or the commit refuses, what is in the
 * database afterwards and what is the caller told. That needs a real MariaDB
 * and a real transaction — which the suite has — and a destination whose
 * failure a test can choose, which `postmeta` through WordPress cannot give it
 * inside a suite.
 *
 * The real `WpShopQuantityPort` is measured the other way, on real WordPress
 * with the failure injected into WordPress's own `update_post_metadata`
 * short-circuit (`tools/alpha54-reproduction.sh`). Neither half is presented
 * as the other: this file is named a double and says so.
 *
 * `null` means THE ROW IS ABSENT and `''` means «a row holding an empty
 * string» — the distinction the whole handover exists to keep (`alpha.28`), so
 * the double keeps it too rather than flattening it to a string.
 *
 * **One thing this cannot model, stated rather than papered over:** the real
 * port's writes go through `$wpdb`, the same connection the transaction was
 * opened on, so they take part in it and a `ROLLBACK` undoes them. This writes
 * to an array, which MariaDB cannot roll back. So a test here may assert what
 * the LEDGER holds and what the caller was told after a refusal, and must not
 * assert that the destination values came back — that is the probe's question,
 * on real WordPress, read with straight SQL.
 */
class RecordingQuantityPort implements ShopQuantityPortInterface
{
    /** @var array<int,array{min:?string,max:?string,step:?string,record:?string}> */
    public array $rows = [];

    /** @var list<string> every call that wrote, in order */
    public array $writes = [];

    public bool $refuseWrite = false;

    /** The meta key `restore()` should refuse, or `''` for none. */
    public string $refuseRestore = '';

    public bool $refuseLock = false;

    /** Whether `readRows()` should answer «the read itself failed». */
    public bool $refuseRowRead = false;

    /**
     * Duplicate rows `readRows()` should report, as `meta_key => extras`.
     *
     * `wp_postmeta` has no unique index on `(post_id, meta_key)`, so this is a
     * state the real table can be in and the double has to be able to be in.
     *
     * @var array<int,array<string,int>>
     */
    public array $duplicates = [];

    /** Every call that locked rows, in order — the lock ORDER is a guarantee. */
    public array $locks = [];

    public int $forgotten = 0;

    /** @var array<int,bool> post id => whether the marketplace owns it */
    public array $owned = [];

    /** @var array<int,bool> post id => whether the shop has it at all */
    public array $present = [];

    /** @var array<int,bool> post id => whether the present rows are somebody else's */
    public array $held = [];

    public function seed(int $postId, ?string $min, ?string $max, ?string $step, ?string $record = null): void
    {
        $this->rows[$postId] = ['min' => $min, 'max' => $max, 'step' => $step, 'record' => $record];
        $this->present[$postId] = true;
        $this->owned[$postId] ??= true;
    }

    public function read(int $postId): array
    {
        $row = $this->rows[$postId] ?? ['min' => null, 'max' => null, 'step' => null, 'record' => null];
        return [
            'min' => $row['min'],
            'max' => $row['max'],
            'step' => $row['step'],
            'held' => $this->held[$postId] ?? false,
            'record' => $row['record'],
            'exists' => $this->present[$postId] ?? false,
        ];
    }

    /**
     * The same answer as `read()`, plus the two things only a row read can
     * say: whether the read worked at all, and how many duplicate rows there
     * are.
     *
     * The double cannot model the thing that makes the real one necessary —
     * there is no object cache here to be stale — so what it models is the
     * CALLER's side: a refused read and a duplicated key each have to produce
     * their own named outcome. The cache half is the probe's question, on real
     * WordPress, with the cache deliberately warmed first.
     */
    public function readRows(int $postId): array
    {
        if ($this->refuseRowRead) {
            return [
                'min' => null, 'max' => null, 'step' => null, 'held' => false,
                'record' => null, 'exists' => false, 'read' => false, 'duplicates' => [],
            ];
        }
        return $this->read($postId) + [
            'read' => true,
            'duplicates' => $this->duplicates[$postId] ?? [],
        ];
    }

    public function write(int $postId, string $min, string $max, string $step): array
    {
        if ($this->refuseWrite) {
            // Refused the way the real port refuses: nothing claimed, and the
            // values it reports are whatever the shop holds.
            $row = $this->rows[$postId] ?? ['min' => null, 'max' => null, 'step' => null];
            return [
                'min' => (string) ($row['min'] ?? ''),
                'max' => (string) ($row['max'] ?? ''),
                'step' => (string) ($row['step'] ?? ''),
                'stored' => false,
            ];
        }
        $this->writes[] = 'write:' . $postId;
        $this->rows[$postId] = [
            'min' => $min,
            'max' => $max,
            'step' => $step,
            'record' => '{"_tmc_min_purchase":"x","_tmc_max_purchase":"y","_tmc_purchase_step":"z"}',
        ];
        return ['min' => $min, 'max' => $max, 'step' => $step, 'stored' => true];
    }

    public function restore(int $postId, ?string $min, ?string $max, ?string $step, ?string $record): string
    {
        if ($this->refuseRestore !== '') {
            // The real port returns BEFORE finishing, so the double does too:
            // whatever it had already written stays written, which is exactly
            // the half-done state the caller's transaction has to undo.
            $this->rows[$postId]['min'] = $min;
            $this->writes[] = 'restore-partial:' . $postId;
            return $this->refuseRestore;
        }
        $this->writes[] = 'restore:' . $postId;
        $this->rows[$postId] = ['min' => $min, 'max' => $max, 'step' => $step, 'record' => $record];
        return '';
    }

    public function lockQuantityRows(int $postId): bool
    {
        if ($this->refuseLock) {
            return false;
        }
        $this->locks[] = 'lock:' . $postId;
        return true;
    }

    public function forgetCachedQuantities(int $postId): void
    {
        $this->forgotten++;
    }

    /** Post id => whether the enforcement grant is on it. */
    public array $granted = [];

    /** The grant write refuses when this is true. */
    public bool $refuseGrant = false;

    public function grantEnforcement(int $postId): bool
    {
        if ($this->refuseGrant) {
            return false;
        }
        $this->writes[] = 'grant:' . $postId;
        $this->granted[$postId] = true;
        return true;
    }

    public function revokeEnforcement(int $postId): bool
    {
        if ($this->refuseGrant) {
            return false;
        }
        $this->writes[] = 'revoke:' . $postId;
        unset($this->granted[$postId]);
        return true;
    }

    public function enforcementGranted(int $postId): bool
    {
        return $this->granted[$postId] ?? false;
    }

    public function exists(int $postId): bool
    {
        return $this->present[$postId] ?? false;
    }

    public function marketplaceOwns(int $postId): bool
    {
        return $this->owned[$postId] ?? false;
    }

    /** `min/max/step` as the evidence scripts print it, with absence named. */
    public function describe(int $postId): string
    {
        $row = $this->rows[$postId] ?? ['min' => null, 'max' => null, 'step' => null];
        $parts = [];
        foreach (['min', 'max', 'step'] as $part) {
            $parts[] = $row[$part] === null ? '__absent__' : (string) $row[$part];
        }
        return implode('/', $parts);
    }
}
