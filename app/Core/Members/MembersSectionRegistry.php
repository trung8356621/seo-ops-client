<?php

declare(strict_types=1);

namespace App\Core\Members;

/**
 * Registry of addon Members form section contributors.
 */
final class MembersSectionRegistry
{
    /** @var array<string, MembersSectionContributor> */
    private array $contributors = [];

    public function register(MembersSectionContributor $contributor): void
    {
        $this->contributors[$contributor->addonSlug()] = $contributor;
    }

    public function has(string $addonSlug): bool
    {
        return isset($this->contributors[$addonSlug]);
    }

    /**
     * @return list<MembersSectionContributor>
     */
    public function available(): array
    {
        $items = array_values(array_filter(
            $this->contributors,
            static fn (MembersSectionContributor $c): bool => $c->isAvailable(),
        ));

        usort(
            $items,
            static fn (MembersSectionContributor $a, MembersSectionContributor $b): int => $a->sort() <=> $b->sort(),
        );

        return $items;
    }

    /**
     * @return list<\Filament\Forms\Components\Component>
     */
    public function formSections(): array
    {
        $sections = [];
        foreach ($this->available() as $contributor) {
            foreach ($contributor->formSections() as $section) {
                $sections[] = $section;
            }
        }

        return $sections;
    }

    /**
     * @return list<\Filament\Forms\Components\Component>
     */
    public function customizeModalSchema(): array
    {
        $fields = [];
        foreach ($this->available() as $contributor) {
            foreach ($contributor->customizeModalSchema() as $field) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    public function fillCustomizeModal(\App\Models\User $user): array
    {
        $data = [];
        foreach ($this->available() as $contributor) {
            $data = array_merge($data, $contributor->fillCustomizeModal($user));
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $formState
     */
    public function afterUserSaved(\App\Models\User $user, array $formState): void
    {
        foreach ($this->available() as $contributor) {
            $contributor->afterUserSaved($user, $formState);
        }
    }
}
