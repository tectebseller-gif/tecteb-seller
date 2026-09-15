<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Modules;

/**
 * Stable topological ordering with explicit cycle reporting.
 * Missing dependencies must be filtered out by the caller first.
 */
final class DependencyGraph
{
    /**
     * @param array<string, list<string>> $deps id => dependency ids (all present in $deps keys)
     * @return array{order: list<string>, cycles: array<string, list<string>>} cycles: id => cycle path
     */
    public static function order(array $deps): array
    {
        $ids = array_keys($deps);
        $indegree = [];
        $dependents = [];
        foreach ($ids as $id) {
            $indegree[$id] = 0;
            $dependents[$id] = [];
        }
        foreach ($deps as $id => $list) {
            foreach ($list as $dep) {
                $indegree[$id]++;
                $dependents[$dep][] = $id;
            }
        }
        // Kahn with a stable queue (insertion order preserved).
        $queue = [];
        foreach ($ids as $id) {
            if ($indegree[$id] === 0) {
                $queue[] = $id;
            }
        }
        $order = [];
        while ($queue !== []) {
            $id = array_shift($queue);
            $order[] = $id;
            foreach ($dependents[$id] as $child) {
                $indegree[$child]--;
                if ($indegree[$child] === 0) {
                    $queue[] = $child;
                }
            }
        }
        $cycles = [];
        if (count($order) !== count($ids)) {
            $remaining = array_values(array_diff($ids, $order));
            foreach ($remaining as $id) {
                $cycles[$id] = self::findCyclePath($id, $deps, $remaining);
            }
        }
        return ['order' => $order, 'cycles' => $cycles];
    }

    /**
     * Returns a path like [a, b, a] when $start sits on a cycle, or the path
     * to a cycle when it merely depends on one.
     *
     * @param array<string, list<string>> $deps
     * @param list<string> $candidates
     * @return list<string>
     */
    private static function findCyclePath(string $start, array $deps, array $candidates): array
    {
        $candidateSet = array_flip($candidates);
        $stack = [];
        $visited = [];
        $walk = function (string $node) use (&$walk, &$stack, &$visited, $deps, $candidateSet): ?array {
            if (isset($visited[$node])) {
                return null;
            }
            $stack[] = $node;
            foreach ($deps[$node] ?? [] as $dep) {
                if (!isset($candidateSet[$dep])) {
                    continue;
                }
                $pos = array_search($dep, $stack, true);
                if ($pos !== false) {
                    return array_merge(array_slice($stack, $pos), [$dep]);
                }
                $found = $walk($dep);
                if ($found !== null) {
                    return $found;
                }
            }
            array_pop($stack);
            $visited[$node] = true;
            return null;
        };
        return $walk($start) ?? [$start];
    }
}
