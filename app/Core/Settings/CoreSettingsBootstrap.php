<?php

declare(strict_types=1);

namespace App\Core\Settings;

/**
 * Seeds Core-owned settings sections once per process.
 * Soft-resolves page classes — no hard Core → SEO imports.
 */
final class CoreSettingsBootstrap
{
    public function seed(SettingsSectionRegistry $registry): void
    {
        if ($registry->isCoreSeeded()) {
            return;
        }
        $registry->markCoreSeeded();

        $registry->registerCore(new SettingsSection(
            id: 'general',
            label: 'seo-content-ai::filament.settings_general.nav',
            icon: 'heroicon-o-cog-6-tooth',
            url: $this->pageUrl(
                'Omnichannel\\Addons\\Seo\\Filament\\Pages\\SeoSettingsGeneral',
                '/admin/settings/general',
            ),
            owner: 'core',
            sort: 10,
            coreShared: true,
        ));

        $registry->registerCore(new SettingsSection(
            id: 'ai-center',
            label: 'AI Center',
            icon: 'heroicon-o-cpu-chip',
            url: $this->pageUrl(
                'Omnichannel\\Addons\\AiPrompt\\Filament\\Pages\\SeoSettingsAiCenter',
                '/admin/settings/ai-center',
            ),
            owner: 'core',
            sort: 30,
            coreShared: true,
        ));

        $registry->registerCore(new SettingsSection(
            id: 'api',
            label: 'API Connections',
            icon: 'heroicon-o-link',
            url: $this->resourceUrl(
                'Omnichannel\\Addons\\AiPrompt\\Filament\\Resources\\AiConnectionResource',
                '/admin/settings/api',
            ),
            owner: 'core',
            sort: 60,
            coreShared: true,
        ));

        $registry->registerCore(new SettingsSection(
            id: 'members',
            label: 'Members',
            icon: 'heroicon-o-users',
            url: $this->resourceUrl(
                'App\\Filament\\Resources\\UserResource',
                '/admin/users',
            ),
            owner: 'core',
            sort: 90,
            coreShared: true,
        ));
    }

    /**
     * @param  class-string  $class
     */
    private function pageUrl(string $class, string $fallback): string
    {
        if (! class_exists($class)) {
            return $this->safeUrl($fallback);
        }

        try {
            return $class::getUrl(panel: 'admin');
        } catch (\Throwable) {
            return $this->safeUrl($fallback);
        }
    }

    /**
     * @param  class-string  $class
     */
    private function resourceUrl(string $class, string $fallback): string
    {
        if (! class_exists($class)) {
            return $this->safeUrl($fallback);
        }

        try {
            return $class::getUrl(panel: 'admin');
        } catch (\Throwable) {
            return $this->safeUrl($fallback);
        }
    }

    private function safeUrl(string $path): string
    {
        try {
            return url($path);
        } catch (\Throwable) {
            return $path;
        }
    }
}
