<?php

declare(strict_types=1);

namespace Tests\Unit\Help;

use App\Core\ClientCoreServiceProvider;
use App\Core\Workspace\ServiceTopbarRouter;
use App\Help\HelpContextResolver;
use App\Help\HelpGroupRegistry;
use App\Help\HelpRuntimePayloadBuilder;
use App\Help\HelpUi;
use ReflectionClass;
use Tests\TestCase;

final class GlobalHelpContractTest extends TestCase
{
    public function test_global_help_hooks_are_client_owned_for_service_panels(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ClientCoreServiceProvider::class))->getFileName()
        );

        self::assertStringContainsString('registerGlobalHelpHeaderHook', $source);
        self::assertStringContainsString('registerGlobalHelpDrawerHook', $source);
        self::assertStringContainsString('registerGlobalHelpAssetsHook', $source);
        self::assertStringContainsString('PanelsRenderHook::USER_MENU_BEFORE', $source);
        self::assertStringContainsString('PanelsRenderHook::BODY_END', $source);
        self::assertStringContainsString('PanelsRenderHook::HEAD_END', $source);
        self::assertStringContainsString('ServiceTopbarRouter::PANEL_IDS', $source);
        self::assertStringContainsString("view('filament.hooks.global-help-trigger')", $source);
        self::assertStringContainsString("view('filament.hooks.global-help-drawer')", $source);
        self::assertStringContainsString("view('filament.hooks.global-help-assets')", $source);
        self::assertSame(1, substr_count($source, "view('filament.hooks.global-help-trigger')"));
        self::assertSame(1, substr_count($source, "view('filament.hooks.global-help-drawer')"));
        self::assertSame(1, substr_count($source, "view('filament.hooks.global-help-assets')"));
        self::assertStringNotContainsString("request()->is('seo'", $source);
        self::assertSame(['admin', 'seo', 'seo-main', 'seeding'], ServiceTopbarRouter::PANEL_IDS);
    }

    public function test_seo_panel_no_longer_mounts_global_help_shell(): void
    {
        $provider = (string) file_get_contents(
            base_path('addons/seo-content-ai-compat/Providers/SeoPanelProvider.php')
        );

        self::assertStringNotContainsString('global-help-trigger', $provider);
        self::assertStringNotContainsString('global-help-modal', $provider);
        self::assertStringNotContainsString('global-help-drawer', $provider);
        self::assertStringNotContainsString('global-help-assets', $provider);
        self::assertFileDoesNotExist(base_path('addons/seo-content-ai-compat/resources/views/filament/hooks/global-help-trigger.blade.php'));
        self::assertFileDoesNotExist(base_path('addons/seo-content-ai-compat/resources/views/filament/hooks/global-help-modal.blade.php'));
        self::assertFileDoesNotExist(base_path('addons/seo-content-ai-compat/resources/views/filament/hooks/global-help-assets.blade.php'));
    }

    public function test_shell_has_one_trigger_and_one_drawer_host(): void
    {
        $trigger = (string) file_get_contents(resource_path('views/filament/hooks/global-help-trigger.blade.php'));
        $drawer = (string) file_get_contents(resource_path('views/filament/hooks/global-help-drawer.blade.php'));

        self::assertSame(1, substr_count($trigger, 'id="global-help-trigger"'));
        self::assertSame(1, substr_count($drawer, 'id="global-help-drawer"'));
        self::assertStringNotContainsString('id="global-help-modal"', $drawer);
        self::assertStringContainsString('global-help-drawer__panel', $drawer);
        self::assertStringContainsString('global-help-drawer__scrim', $drawer);
        self::assertStringContainsString('Escape', $drawer);
        self::assertStringContainsString('help-group-navigation', $drawer);
        self::assertStringContainsString('class="help-navigation"', (string) file_get_contents(
            resource_path('views/filament/hooks/partials/help-group-navigation.blade.php')
        ));
        self::assertStringContainsString('data-help-empty-group', (string) file_get_contents(
            resource_path('views/filament/hooks/partials/help-topic-accordion.blade.php')
        ));
    }

    public function test_config_declares_empty_admin_and_seeding_groups(): void
    {
        $admin = config('help.groups.admin');
        $seeding = config('help.groups.seeding');

        self::assertIsArray($admin);
        self::assertSame('admin', $admin['id']);
        self::assertSame('Admin', $admin['title']);
        self::assertSame('Admin', $admin['modalTitle']);
        self::assertSame('admin', $admin['context_prefix']);

        self::assertIsArray($seeding);
        self::assertSame('seeding', $seeding['id']);
        self::assertSame('Seeding', $seeding['title']);
        self::assertSame('Seeding', $seeding['modalTitle']);
        self::assertSame('seeding', $seeding['context_prefix']);

        $ids = array_column(HelpGroupRegistry::all(), 'id');
        self::assertContains('admin', $ids);
        self::assertContains('seeding', $ids);
    }

    public function test_payload_includes_canonical_empty_groups_without_topics(): void
    {
        $payload = app(HelpRuntimePayloadBuilder::class)->clientPayload(attemptSync: false);
        $groups = [];
        foreach ($payload['groups'] as $group) {
            $groups[(string) $group['id']] = $group;
        }

        self::assertArrayHasKey('admin', $groups);
        self::assertArrayHasKey('seeding', $groups);
        self::assertSame([], $groups['admin']['topics']);
        self::assertSame([], $groups['seeding']['topics']);
        self::assertSame('Admin', $groups['admin']['title']);
        self::assertSame('Seeding', $groups['seeding']['title']);
        self::assertArrayHasKey('admin', $payload['contexts']);
        self::assertArrayHasKey('seeding', $payload['contexts']);
        self::assertSame('admin', $payload['contexts']['admin']['defaultGroupId']);
        self::assertSame('seeding', $payload['contexts']['seeding']['defaultGroupId']);
    }

    public function test_panel_context_priority_keeps_seo_dashboard(): void
    {
        $contexts = app(HelpRuntimePayloadBuilder::class)->clientPayload(attemptSync: false)['contexts'];

        $dashboard = HelpContextResolver::resolve(
            $contexts,
            'filament.seo.pages.dashboard',
            '/seo',
            '?site_id=1',
            'seo',
        );
        self::assertSame('dashboard', $dashboard['id']);
        self::assertSame('dashboard', $dashboard['defaultGroupId']);

        $admin = HelpContextResolver::resolve(
            $contexts,
            'filament.admin.pages.dashboard',
            '/admin',
            '',
            'admin',
        );
        self::assertSame('admin', $admin['id']);
        self::assertSame('admin', $admin['defaultGroupId']);

        $seeding = HelpContextResolver::resolve(
            $contexts,
            'filament.seeding.pages.dashboard',
            '/seeding',
            '',
            'seeding',
        );
        self::assertSame('seeding', $seeding['id']);
        self::assertSame('seeding', $seeding['defaultGroupId']);

        $adminByPath = HelpContextResolver::resolve($contexts, null, '/admin', '', null);
        self::assertSame('admin', $adminByPath['defaultGroupId']);

        $seoSettingsOnAdmin = HelpContextResolver::resolve(
            $contexts,
            'filament.admin.resources.prompts.index',
            '/admin/prompts',
            '',
            'admin',
        );
        self::assertSame('settings', $seoSettingsOnAdmin['id']);
    }

    public function test_contextual_help_events_and_agent_coordination_stay_compatible(): void
    {
        $assets = (string) file_get_contents(resource_path('views/filament/hooks/global-help-assets.blade.php'));
        $helpUi = (string) file_get_contents((new ReflectionClass(HelpUi::class))->getFileName());
        $editorButton = (string) file_get_contents(
            base_path('addons/content/resources/js/components/ContextHelpButton.jsx')
        );

        self::assertStringContainsString('seo-global-help:open', $assets);
        self::assertStringContainsString('seo-global-help:close', $assets);
        self::assertStringContainsString('article-editor:help-open', $assets);
        self::assertStringContainsString('help-drawer:open', $assets);
        self::assertStringContainsString('help-drawer:close', $assets);
        self::assertStringContainsString('help-drawer:toggle', $assets);
        self::assertStringContainsString('window.__SEO_HELP_PAYLOAD__', $assets);
        self::assertStringContainsString('window.__SEO_HELP_ROUTE_NAME__', $assets);
        self::assertStringContainsString('context_resolution', $assets);
        self::assertStringContainsString("const AGENT_DRAWER_CLOSE = 'agent-drawer:close'", $assets);
        self::assertStringContainsString('agent-drawer:open', $assets);
        self::assertStringContainsString('agent-drawer:toggle', $assets);
        self::assertStringContainsString('closeAgentDrawer()', $assets);
        self::assertStringContainsString('seo-global-help:open', $helpUi);
        self::assertStringContainsString('seo-global-help:open', $editorButton);
        self::assertStringNotContainsString('@vite', $assets);
        self::assertStringNotContainsString('SeoHelpRegistry', $assets);
    }
}
