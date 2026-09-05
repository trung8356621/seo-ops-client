<?php

declare(strict_types=1);

namespace App\Core\Settings;

/**
 * Core Settings shell registry — Core owns navigation ordering; addons contribute sections.
 */
final class SettingsSectionRegistry
{
    /** @var array<string, SettingsSectionContributor> */
    private array $contributors = [];

    /** @var array<string, SettingsSection> */
    private array $coreSections = [];

    private bool $coreSeeded = false;

    public function registerCore(SettingsSection $section): void
    {
        $this->coreSections[$section->id] = $section;
    }

    public function register(SettingsSectionContributor $contributor): void
    {
        $this->contributors[$contributor->ownerSlug()] = $contributor;
    }

    public function hasContributor(string $ownerSlug): bool
    {
        return isset($this->contributors[$ownerSlug]);
    }

    public function markCoreSeeded(): void
    {
        $this->coreSeeded = true;
    }

    public function isCoreSeeded(): bool
    {
        return $this->coreSeeded;
    }

    /**
     * @return list<SettingsSection>
     */
    public function all(): array
    {
        $sections = array_values($this->coreSections);
        foreach ($this->contributors as $contributor) {
            foreach ($contributor->sections() as $section) {
                $sections[] = $section;
            }
        }

        usort(
            $sections,
            static fn (SettingsSection $a, SettingsSection $b): int => $a->sort <=> $b->sort,
        );

        // Deduplicate by id (last wins by sort order preference — keep first after sort).
        $byId = [];
        foreach ($sections as $section) {
            if (! isset($byId[$section->id])) {
                $byId[$section->id] = $section;
            }
        }

        return array_values($byId);
    }

    /**
     * @return list<array{id: string, label: string, icon: string, url: string}>
     */
    public function menuItems(): array
    {
        $out = [];
        foreach ($this->all() as $section) {
            $out[] = [
                'id' => $section->id,
                'label' => $section->label,
                'icon' => $section->icon,
                'url' => $section->url,
            ];
        }

        return $out;
    }

    /**
     * @return list<SettingsSection>
     */
    public function sharedOnly(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (SettingsSection $s): bool => $s->coreShared,
        ));
    }
}
