<?php

declare(strict_types=1);

namespace App\Core\Members;

use App\Models\User;

/**
 * Addon contributes Filament form sections for Core Members (UserResource) without Core owning addon business.
 */
interface MembersSectionContributor
{
    public function addonSlug(): string;

    /**
     * Sort order among addon sections (lower first). Core account section stays first.
     */
    public function sort(): int;

    public function isAvailable(): bool;

    /**
     * Sections for full Edit User form.
     *
     * @return list<\Filament\Forms\Components\Component>
     */
    public function formSections(): array;

    /**
     * Fields for the lightweight "Tùy chỉnh" modal (no nested Section required).
     *
     * @return list<\Filament\Forms\Components\Component>
     */
    public function customizeModalSchema(): array;

    /**
     * @return array<string, mixed>
     */
    public function fillCustomizeModal(User $user): array;

    /**
     * Persist addon-owned side state after Core User save (capacity, etc.).
     *
     * @param  array<string, mixed>  $formState
     */
    public function afterUserSaved(User $user, array $formState): void;
}
