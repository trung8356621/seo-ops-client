<?php

declare(strict_types=1);

namespace App\Core\Workspace;

use App\Models\User;

/**
 * Addon/Core register Access Hub destinations without Core hardcoding SEO/Seeding cards.
 */
final class WorkspaceDestinationRegistry
{
    /** @var array<string, WorkspaceDestination> */
    private array $destinations = [];

    public function register(WorkspaceDestination $destination): void
    {
        $this->destinations[$destination->key] = $destination;
    }

    public function has(string $key): bool
    {
        return isset($this->destinations[$key]);
    }

    /**
     * @return list<WorkspaceDestination>
     */
    public function all(): array
    {
        $items = array_values($this->destinations);
        usort(
            $items,
            static fn (WorkspaceDestination $a, WorkspaceDestination $b): int => $a->sort <=> $b->sort,
        );

        return $items;
    }

    /**
     * Destinations the user is authorized to open.
     *
     * @return list<WorkspaceDestination>
     */
    public function visibleFor(?User $user): array
    {
        if (! $user instanceof User) {
            return [];
        }

        return array_values(array_filter(
            $this->all(),
            static fn (WorkspaceDestination $d): bool => $d->allows($user),
        ));
    }
}
