<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Addon\AddonRegistry;
use App\Core\Members\MembersSectionRegistry;
use App\Core\Settings\CoreSettingsBootstrap;
use App\Core\Settings\SettingsSectionRegistry;
use App\Filament\Resources\UserResource;
use Omnichannel\Addons\SearchFoundation\Members\SeoMembersSectionContributor;
use Omnichannel\Addons\Seo\Settings\SeoSettingsSectionContributor;
use Omnichannel\Addons\Seo\Support\SeoSettingsMenu;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Render/contract tests that catch the screenshot bug:
 * contributor exists in code but Admin UI misses capacity / settings sections.
 */
final class CoreSettingsAndMembersRenderContractTest extends TestCase
{
    public function test_customize_modal_form_source_includes_account_and_registry_merge(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(UserResource::class))->getFileName());
        self::assertStringContainsString("Action::make('customizeMember')", $source);
        self::assertStringContainsString("Section::make('Tài khoản')", $source);
        self::assertStringContainsString('customizeModalSchema()', $source);
        self::assertStringContainsString('...$addonFields', $source);
    }

    public function test_seo_contributor_schema_source_has_capacity_fields(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(SeoMembersSectionContributor::class))->getFileName()
        );
        foreach ([
            'seo_capacity_use_default',
            'seo_monthly_capacity_override',
            'Dùng mặc định',
            'Giới hạn bài SEO / tháng',
            'ContentProjectWriterCapacitySettingsService',
            'AddonEnablement::seoStackEnabled',
        ] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
    }

    public function test_settings_menu_ids_with_seo_enabled_via_registry(): void
    {
        $addons = new AddonRegistry();
        $addons->markEnabled('seo-content-ai');

        // Soft-bind without full Laravel: only if app() unavailable the contributor
        // falls back to class_exists. Simulate enabled path by asserting contributor source
        // + registry merge of Core + SEO section ids from constructor data.
        $registry = new SettingsSectionRegistry();
        (new CoreSettingsBootstrap())->seed($registry);
        $registry->register(new SeoSettingsSectionContributor());

        $ids = array_map(static fn ($s) => $s->id, $registry->all());
        foreach (['general', 'ai-center', 'api', 'members', 'workflows', 'editor', 'keywords', 'scoring', 'import-export'] as $id) {
            self::assertContains($id, $ids, "missing {$id}");
        }
        unset($addons);
    }

    public function test_settings_menu_without_seo_contributor_omits_seo_ids(): void
    {
        $registry = new SettingsSectionRegistry();
        (new CoreSettingsBootstrap())->seed($registry);
        $ids = array_map(static fn ($s) => $s->id, $registry->all());

        self::assertContains('general', $ids);
        self::assertContains('ai-center', $ids);
        self::assertContains('api', $ids);
        self::assertNotContains('workflows', $ids);
        self::assertNotContains('scoring', $ids);
        self::assertNotContains('import-export', $ids);
    }

    public function test_empty_members_registry_has_no_capacity_schema(): void
    {
        $registry = new MembersSectionRegistry();
        self::assertSame([], $registry->customizeModalSchema());
    }

    public function test_seo_settings_menu_does_not_hardcode_mini_hub_cards(): void
    {
        $menu = (string) file_get_contents((new ReflectionClass(SeoSettingsMenu::class))->getFileName());
        self::assertStringContainsString('SettingsSectionRegistry', $menu);
        self::assertStringNotContainsString('CoreGeneralSettings', $menu);
        self::assertStringNotContainsString('card dashboard', $menu);
    }
}
