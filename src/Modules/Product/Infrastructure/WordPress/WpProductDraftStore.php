<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductDraftStoreInterface;

/**
 * Drafts in user meta — one row per person, holding every product they have
 * unfinished.
 *
 * **User meta and not a transient.** A transient expires, and the whole point
 * of a draft is to still be there tomorrow when somebody comes back to the tab
 * their laptop went to sleep on. It is also not a table, because a draft has
 * exactly one owner and WordPress already indexes by user.
 *
 * **Bounded, and the oldest goes first.** Without a cap, a vendor who starts
 * and abandons products accumulates rows in one meta value that is read whole
 * on every autosave. Twenty is far more than anybody has open and small enough
 * that the read stays trivial.
 *
 * **Deleted on a successful save, not on a successful autosave.** A draft that
 * cleared itself when the form was submitted-and-refused would throw the work
 * away at exactly the moment it was needed.
 */
final class WpProductDraftStore implements ProductDraftStoreInterface
{
    public const META_KEY = 'tmc_product_drafts';

    private const MAX_DRAFTS = 20;

    /** Bytes one draft may occupy once encoded; beyond it the draft is refused. */
    private const MAX_BYTES = 64 * 1024;

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function put(int $userId, int $productId, array $payload, string $revision): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || strlen($encoded) > self::MAX_BYTES) {
            // Refused rather than truncated: half a draft restored into a form
            // looks like a complete one, and the missing half is invisible.
            return false;
        }
        $drafts = $this->all($userId);
        $drafts[(string) $productId] = [
            'payload' => $payload,
            'revision' => $revision,
            'saved_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ];
        if (count($drafts) > self::MAX_DRAFTS) {
            uasort($drafts, static fn (array $a, array $b): int => strcmp((string) $a['saved_at'], (string) $b['saved_at']));
            $drafts = array_slice($drafts, -self::MAX_DRAFTS, null, true);
        }
        return update_user_meta($userId, self::META_KEY, $drafts) !== false;
    }

    public function get(int $userId, int $productId): ?array
    {
        $draft = $this->all($userId)[(string) $productId] ?? null;
        if (!is_array($draft) || !isset($draft['payload']) || !is_array($draft['payload'])) {
            return null;
        }
        return [
            'payload' => $draft['payload'],
            'revision' => (string) ($draft['revision'] ?? ''),
            'saved_at' => (string) ($draft['saved_at'] ?? ''),
        ];
    }

    public function forget(int $userId, int $productId): bool
    {
        $drafts = $this->all($userId);
        if (!isset($drafts[(string) $productId])) {
            return true;                         // already gone is the wanted state
        }
        unset($drafts[(string) $productId]);
        if ($drafts === []) {
            return delete_user_meta($userId, self::META_KEY);
        }
        return update_user_meta($userId, self::META_KEY, $drafts) !== false;
    }

    public function listFor(int $userId): array
    {
        $out = [];
        foreach ($this->all($userId) as $productId => $draft) {
            $out[] = ['product_id' => (int) $productId, 'saved_at' => (string) ($draft['saved_at'] ?? '')];
        }
        return $out;
    }

    /** @return array<string,array<string,mixed>> */
    private function all(int $userId): array
    {
        $raw = get_user_meta($userId, self::META_KEY, true);
        return is_array($raw) ? $raw : [];
    }
}
