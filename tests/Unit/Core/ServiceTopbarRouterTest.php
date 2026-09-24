<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\ClientCoreServiceProvider;
use App\Core\Workspace\ServiceTopbarRouter;
use App\Core\Workspace\WorkspaceDestination;
use App\Core\Workspace\WorkspaceDestinationRegistry;
use App\Models\User;
use ReflectionClass;
use Tests\TestCase;

final class ServiceTopbarRouterTest extends TestCase
{
    public function test_core_registers_shared_topbar_hook_once(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ClientCoreServiceProvider::class))->getFileName()
        );

        self::assertStringContainsString('registerServiceTopbarRouterHook', $source);
        self::assertStringContainsString('registerSupportTicketHeaderHook', $source);
        self::assertStringContainsString('PanelsRenderHook::TOPBAR_START', $source);
        self::assertStringContainsString('service-topbar-router', $source);
        self::assertStringContainsString('support-ticket-header', $source);
        self::assertStringContainsString("key: 'admin'", $source);
        self::assertStringContainsString("url('/admin')", $source);
        self::assertStringContainsString('ServiceTopbarRouter::class', $source);
    }

    public function test_shared_view_and_i18n_exist(): void
    {
        self::assertFileExists(resource_path('views/filament/hooks/service-topbar-router.blade.php'));
        self::assertFileExists(lang_path('en/services.php'));
        self::assertFileExists(lang_path('vi/services.php'));

        $view = (string) file_get_contents(resource_path('views/filament/hooks/service-topbar-router.blade.php'));
        self::assertStringContainsString('data-service-router', $view);
        self::assertStringContainsString('__(\'services.admin\')', (string) file_get_contents(
            (new ReflectionClass(ServiceTopbarRouter::class))->getFileName()
        ));
        self::assertSame('Admin', __('services.admin'));
        self::assertSame('SEO', __('services.seo'));
        self::assertSame('Seeding', __('services.seeding'));
    }

    public function test_router_urls_are_canonical_service_roots(): void
    {
        $registry = new WorkspaceDestinationRegistry;
        $registry->register(new WorkspaceDestination(
            key: 'admin',
            label: 'Admin',
            url: url('/admin'),
            sort: 0,
            panelId: 'admin',
            canAccess: static fn (User $user): bool => true,
        ));
        $registry->register(new WorkspaceDestination(
            key: 'seo',
            label: 'SEO',
            url: url('/seo'),
            sort: 10,
            panelId: 'seo',
            canAccess: static fn (User $user): bool => true,
        ));
        $registry->register(new WorkspaceDestination(
            key: 'seeding',
            label: 'Seeding',
            url: url('/seeding'),
            sort: 20,
            panelId: 'seeding',
            canAccess: static fn (User $user): bool => true,
        ));

        $user = new User;
        $user->status = User::STATUS_NORMAL;
        $user->role = User::ROLE_OWNER;

        $router = new ServiceTopbarRouter($registry);
        $links = $router->links($user);
        $byKey = [];
        foreach ($links as $link) {
            $byKey[$link['key']] = $link;
        }

        self::assertSame(url('/admin'), $byKey['admin']['url']);
        self::assertSame(url('/seo'), $byKey['seo']['url']);
        self::assertSame(url('/seeding'), $byKey['seeding']['url']);
        self::assertSame(['admin', 'seo', 'seeding'], array_column($links, 'key'));
    }

    public function test_active_key_follows_request_path(): void
    {
        $router = new ServiceTopbarRouter(new WorkspaceDestinationRegistry);

        $this->app->instance('request', \Illuminate\Http\Request::create('/seeding/workspace', 'GET'));
        self::assertSame('seeding', $router->activeKey());

        $this->app->instance('request', \Illuminate\Http\Request::create('/seo/articles', 'GET'));
        self::assertSame('seo', $router->activeKey());

        $this->app->instance('request', \Illuminate\Http\Request::create('/admin', 'GET'));
        self::assertSame('admin', $router->activeKey());
    }

    public function test_inaccessible_services_are_omitted(): void
    {
        $registry = new WorkspaceDestinationRegistry;
        $registry->register(new WorkspaceDestination(
            key: 'admin',
            label: 'Admin',
            url: url('/admin'),
            sort: 0,
            panelId: 'admin',
            canAccess: static fn (User $user): bool => false,
        ));
        $registry->register(new WorkspaceDestination(
            key: 'seo',
            label: 'SEO',
            url: url('/seo'),
            sort: 10,
            panelId: 'seo',
            canAccess: static fn (User $user): bool => true,
        ));
        $registry->register(new WorkspaceDestination(
            key: 'seeding',
            label: 'Seeding',
            url: url('/seeding'),
            sort: 20,
            panelId: 'seeding',
            canAccess: static fn (User $user): bool => true,
        ));

        $user = new User;
        $user->status = User::STATUS_NORMAL;
        $user->role = User::ROLE_STAFF;
        $user->parent_id = 1;

        $router = new ServiceTopbarRouter($registry);
        $keys = array_column($router->links($user), 'key');

        self::assertSame(['seo', 'seeding'], $keys);
        self::assertNotContains('admin', $keys);
    }

    public function test_seeding_addon_does_not_own_global_router(): void
    {
        $seedingPanel = dirname(base_path()).'/omnichannel-addons/seeding/src/Providers/SeedingPanelProvider.php';
        if (! is_file($seedingPanel)) {
            $seedingPanel = base_path('addons/seeding/src/Providers/SeedingPanelProvider.php');
        }
        self::assertFileExists($seedingPanel);
        $source = (string) file_get_contents($seedingPanel);
        self::assertStringNotContainsString('service-topbar-router', $source);
        self::assertStringNotContainsString('ServiceTopbarRouter', $source);
        self::assertStringNotContainsString('data-service-router', $source);

        $workspace = dirname(base_path()).'/omnichannel-addons/seeding/resources/js/seeding/SeedingWorkspace.jsx';
        if (! is_file($workspace)) {
            $workspace = base_path('addons/seeding/resources/js/seeding/SeedingWorkspace.jsx');
        }
        if (is_file($workspace)) {
            $js = (string) file_get_contents($workspace);
            self::assertStringNotContainsString('data-service-router', $js);
            self::assertStringNotContainsString('/admin', $js);
        }
    }

    public function test_language_switch_config_remains_in_app_provider(): void
    {
        $source = (string) file_get_contents(app_path('Providers/AppServiceProvider.php'));
        self::assertStringContainsString('LanguageSwitch::configureUsing', $source);
        self::assertStringContainsString("->locales(['vi', 'en'])", $source);
    }

    public function test_view_marks_active_service_without_duplicate_chrome(): void
    {
        $view = (string) file_get_contents(resource_path('views/filament/hooks/service-topbar-router.blade.php'));
        self::assertStringContainsString('is-active', $view);
        self::assertStringContainsString('aria-current="page"', $view);
        self::assertStringContainsString('.fi-topbar > nav > .ms-auto', $view);
        self::assertStringContainsString('.fi-topbar > nav > .ms-auto ~ *', $view);
        self::assertStringContainsString('order: 2', $view);
        self::assertStringNotContainsString('LanguageSwitch', $view);
        self::assertStringNotContainsString('user-menu', $view);
        self::assertStringNotContainsString('logo.png', $view);
    }

    public function test_panel_ids_cover_admin_seo_seeding(): void
    {
        self::assertSame(
            ['admin', 'seo', 'seo-main', 'seeding'],
            ServiceTopbarRouter::PANEL_IDS,
        );
        self::assertSame(['admin', 'seo', 'seeding'], ServiceTopbarRouter::KEYS);
    }
}
