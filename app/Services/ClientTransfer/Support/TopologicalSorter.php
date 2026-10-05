<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Support;

use App\Services\ClientTransfer\Exceptions\DependencyCycleException;

final class TopologicalSorter
{
    /**
     * @param  array<string, list<string>>  $dependencyGraph  Map of [nodeKey => [dependencyKeys]]
     * @return list<string> Sorted keys in dependency order (least dependent first)
     */
    public static function sort(array $dependencyGraph): array
    {
        $visited = [];
        $visiting = [];
        $sorted = [];

        $visit = function (string $node) use (&$visit, &$visited, &$visiting, &$sorted, $dependencyGraph): void {
            if (isset($visiting[$node])) {
                throw new DependencyCycleException("Dependency cycle detected involving dataset [{$node}].");
            }

            if (isset($visited[$node])) {
                return;
            }

            $visiting[$node] = true;

            $deps = $dependencyGraph[$node] ?? [];
            foreach ($deps as $dep) {
                // If a dependency is declared that isn't in the graph, we skip or handle as root
                if (isset($dependencyGraph[$dep])) {
                    $visit($dep);
                }
            }

            unset($visiting[$node]);
            $visited[$node] = true;
            $sorted[] = $node;
        };

        foreach (array_keys($dependencyGraph) as $node) {
            if (! isset($visited[$node])) {
                $visit($node);
            }
        }

        return $sorted;
    }
}
