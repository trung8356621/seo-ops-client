<?php

declare(strict_types=1);

namespace App\Core\Members;

use App\Models\User;

/**
 * Addon contributes Filament form tabs/sections for Core Members (UserResource)
 * without Core owning addon business.
 */
interface MembersSectionContributor
{
    public function addonSlug(): string;

    /**
     * Tab label on the Edit User form (e.g. "SEO", "Seeding").
     */
    public function tabLabel(): string;

    /**
     * Optional Heroicon name for the tab (e.g. heroicon-o-magnifying-glass).
     */
    public function tabIcon(): ?string;

    /**
     * Sort order among addon tabs (lower first). Core "Tài khoản" tab stays first.
     */
    public function sort(): int;

    public function isAvailable(): bool;

    /**
     * Form state keys that must never be written onto the User model.
     *
     * @return list<string>
     */
    public function formOnlyStateKeys(): array;

    /**
     * Schema for the addon tab on the full Edit User form.
     * Return [] to skip rendering a tab.
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
