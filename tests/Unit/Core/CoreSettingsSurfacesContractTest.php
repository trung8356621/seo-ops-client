<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Settings\CoreSettingsBootstrap;
use App\Core\Settings\SettingsSectionRegistry;
use App\Filament\Pages\CoreSettingsHub;
use App\Providers\Filament\AdminPanelProvider;
use Omnichannel\Addons\Seo\Settings\SeoSettingsSectionContributor;
use Omnichannel\Addons\Seo\Support\SeoSettingsMenu;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CoreSettingsSurfacesContractTest extends TestCase
{
    public function test_settings_hub_redirects_not_card_dashboard(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(CoreSettingsHub::class))->getFileName());
        self::assertStringContainsString('redirect(', $source);
        self::assertStringNotContainsString("id: 'members'", $source);
        self::assertStringContainsString('menuItems()', $source);
    }

    public function test_admin_panel_registers_full_settings_shell_pages(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(AdminPanelProvider::class))->getFileName());
        foreach ([
            'SeoSettingsGeneral',
            'SeoSettingsWorkflows',
            'SeoSettingsEditor',
            'SeoSettingsKeywords',
            'SeoSettingsScoring',
            'SeoSettingsConfigurationTransfer',
            'SeoSettingsAiCenter',
            'AiConnectionResource',
        ] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
    }

    public function test_registry_with_seo_contributor_has_full_menu_ids(): void
    {
        $registry = new SettingsSectionRegistry();
        (new CoreSettingsBootstrap())->seed($registry);
        $registry->register(new SeoSettingsSectionContributor());

        $ids = array_map(static fn ($s) => $s->id, $registry->all());

        foreach (['general', 'workflows', 'ai-center', 'editor', 'keywords', 'api', 'scoring', 'import-export', 'members'] as $id) {
            self::assertContains($id, $ids, "missing settings section {$id}");
        }
    }

    public function test_seo_menu_reads_from_settings_registry(): void
    {
        $menu = (string) file_get_contents((new ReflectionClass(SeoSettingsMenu::class))->getFileName());
        self::assertStringContainsString('SettingsSectionRegistry', $menu);
        self::assertStringContainsString('CoreSettingsBootstrap', $menu);
        self::assertStringContainsString('menuItems()', $menu);
        self::assertStringNotContainsString('CoreGeneralSettings', $menu);
    }

    public function test_seo_service_provider_registers_settings_contributor(): void
    {
        $provider = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Seo\SeoServiceProvider::class))->getFileName()
        );
        self::assertStringContainsString('SeoSettingsSectionContributor', $provider);
        self::assertStringContainsString('SettingsSectionRegistry', $provider);
    }
}
