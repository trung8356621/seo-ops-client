<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Addon\AddonRegistry;
use App\Core\Members\MembersSectionRegistry;
use App\Core\Settings\CoreSettingsBootstrap;
use App\Core\Settings\SettingsSectionRegistry;
use Omnichannel\Addons\SearchFoundation\Members\SeoMembersSectionContributor;
use Omnichannel\Addons\Seo\Settings\SeoSettingsSectionContributor;
use Tests\TestCase;

/**
 * Boots full Laravel — catches Admin registration lifecycle bugs that unit source tests miss.
 *
 * Does not depend on live services.is_active rows: marks enablement + registers contributors
 * the same way SeoServiceProvider does on a real Admin request.
 */
final class CoreMembersAndSettingsRuntimeTest extends TestCase
{
    public function test_members_customize_schema_includes_capacity_when_seo_enabled(): void
    {
        /** @var AddonRegistry $addons */
        $addons = app(AddonRegistry::class);
        $addons->markEnabled('seo-content-ai');

        /** @var MembersSectionRegistry $members */
        $members = app(MembersSectionRegistry::class);
        $contributor = app(SeoMembersSectionContributor::class);
        if (! $members->has($contributor->addonSlug())) {
            $members->register($contributor);
        }

        self::assertTrue($contributor->isAvailable());
        self::assertTrue($members->has('seo-members'));

        $schema = $members->customizeModalSchema();
        self::assertNotSame([], $schema);

        $names = $this->collectComponentNames($schema);
        self::assertContains('seo_capacity_use_default', $names);
        self::assertContains('seo_monthly_capacity_override', $names);
    }

    public function test_members_customize_schema_omits_capacity_when_seo_disabled(): void
    {
        /** @var AddonRegistry $addons */
        $addons = app(AddonRegistry::class);
        $ref = new \ReflectionClass($addons);
        $prop = $ref->getProperty('enabled');
        $prop->setAccessible(true);
        $prop->setValue($addons, []);

        /** @var MembersSectionRegistry $members */
        $members = new MembersSectionRegistry();
        $members->register(new SeoMembersSectionContributor());

        self::assertFalse((new SeoMembersSectionContributor())->isAvailable());
        self::assertSame([], $members->customizeModalSchema());
    }

    public function test_user_resource_style_form_merges_name_and_capacity(): void
    {
        app(AddonRegistry::class)->markEnabled('seo-content-ai');

        /** @var MembersSectionRegistry $members */
        $members = app(MembersSectionRegistry::class);
        $contributor = app(SeoMembersSectionContributor::class);
        if (! $members->has($contributor->addonSlug())) {
            $members->register($contributor);
        }

        $form = [
            \Filament\Forms\Components\Section::make('Tài khoản')->schema([
                \Filament\Forms\Components\TextInput::make('name'),
            ]),
            ...$members->customizeModalSchema(),
        ];

        $names = $this->collectComponentNames($form);
        self::assertContains('name', $names);
        self::assertContains('seo_capacity_use_default', $names);
        self::assertContains('seo_monthly_capacity_override', $names);
    }

    public function test_settings_navigation_includes_seo_sections_when_enabled(): void
    {
        app(AddonRegistry::class)->markEnabled('seo-content-ai');

        /** @var SettingsSectionRegistry $settings */
        $settings = app(SettingsSectionRegistry::class);
        app(CoreSettingsBootstrap::class)->seed($settings);
        if (! $settings->hasContributor('seo')) {
            $settings->register(app(SeoSettingsSectionContributor::class));
        }

        $ids = array_map(static fn ($s) => $s->id, $settings->all());
        foreach (['general', 'ai-center', 'api', 'workflows', 'editor', 'keywords', 'scoring', 'import-export'] as $id) {
            self::assertContains($id, $ids, "missing settings section {$id}");
        }
    }

    public function test_settings_navigation_omits_seo_sections_when_disabled(): void
    {
        /** @var AddonRegistry $addons */
        $addons = app(AddonRegistry::class);
        $ref = new \ReflectionClass($addons);
        $prop = $ref->getProperty('enabled');
        $prop->setAccessible(true);
        $prop->setValue($addons, []);

        $settings = new SettingsSectionRegistry();
        (new CoreSettingsBootstrap())->seed($settings);
        $settings->register(new SeoSettingsSectionContributor());

        $ids = array_map(static fn ($s) => $s->id, $settings->all());
        self::assertContains('general', $ids);
        self::assertContains('ai-center', $ids);
        self::assertNotContains('workflows', $ids);
        self::assertNotContains('scoring', $ids);
        self::assertNotContains('import-export', $ids);
    }

    /**
     * @param  list<\Filament\Forms\Components\Component>  $components
     * @return list<string>
     */
    private function collectComponentNames(array $components): array
    {
        $names = [];
        foreach ($components as $component) {
            if (method_exists($component, 'getName') && is_string($component->getName()) && $component->getName() !== '') {
                $names[] = $component->getName();
            }
            if (method_exists($component, 'getChildComponents')) {
                foreach ($this->collectComponentNames($component->getChildComponents()) as $child) {
                    $names[] = $child;
                }
            }
        }

        return $names;
    }
}
