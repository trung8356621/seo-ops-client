<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filament\Pages\ControlServer;
use App\Filament\Pages\ManageServices;
use App\Filament\Resources\SeoDatabaseConnectionResource;
use App\Filament\Resources\SiteServiceResource;
use App\Filament\Resources\UserResource;
use App\Models\Service;
use App\Models\SiteService;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Omnichannel\Addons\Agent\Filament\Pages\AutomationFlowsPage;
use Omnichannel\Addons\Agent\Filament\Pages\AutomationSettings;
use Omnichannel\Addons\Agent\Filament\Pages\AutomationWorkflowBuilder;
use Omnichannel\Addons\Agent\Filament\Resources\AutomationExecutionResource;
use Omnichannel\Addons\Agent\Filament\Resources\AutomationRuleResource;
use Omnichannel\Addons\SearchFoundation\Filament\Pages\AutomationOperationsDashboard;
use Tests\TestCase;

final class AdminPanelBoundaryTest extends TestCase
{
    public function test_owner_can_access_admin_panel(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertTrue($this->userWithRole(User::ROLE_OWNER)->canAccessPanel($panel));
    }

    public function test_legacy_admin_role_can_access_admin_panel(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertTrue($this->userWithRole(User::ROLE_ADMIN)->canAccessPanel($panel));
    }

    public function test_manager_and_staff_cannot_access_admin_panel(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertFalse($this->userWithRole(User::ROLE_MANAGER, parentId: 10)->canAccessPanel($panel));
        $this->assertFalse($this->userWithRole(User::ROLE_STAFF, parentId: 10)->canAccessPanel($panel));
    }

    public function test_automation_is_not_registered_in_admin_panel(): void
    {
        $panel = Filament::getPanel('admin');
        $pages = $panel->getPages();
        $resources = $panel->getResources();

        foreach ([
            AutomationFlowsPage::class,
            AutomationOperationsDashboard::class,
            AutomationSettings::class,
            AutomationWorkflowBuilder::class,
        ] as $page) {
            $this->assertNotContains($page, $pages, $page.' must not be registered in /admin');
        }

        foreach ([
            AutomationRuleResource::class,
            AutomationExecutionResource::class,
        ] as $resource) {
            $this->assertNotContains($resource, $resources, $resource.' must not be registered in /admin');
        }

        $provider = (string) file_get_contents(app_path('Providers/Filament/AdminPanelProvider.php'));
        $this->assertStringNotContainsString('AutomationFlowsPage', $provider);
        $this->assertStringNotContainsString('RestrictAdminAutomationOnlyUsers', $provider);
    }

    public function test_manage_services_and_activated_services_are_inaccessible(): void
    {
        $this->actingAs($this->userWithRole(User::ROLE_OWNER));

        $this->assertFalse(ManageServices::canAccess());
        $this->assertFalse(ManageServices::shouldRegisterNavigation());
        $this->assertFalse(SiteServiceResource::canAccess());
        $this->assertFalse(SiteServiceResource::shouldRegisterNavigation());
        $this->assertFalse(SiteServiceResource::canViewAny());
        $this->assertFalse(SiteServiceResource::canCreate());
        $this->assertFalse(SiteServiceResource::canEdit(new SiteService));
        $this->assertFalse(SiteServiceResource::canDelete(new SiteService));
        $this->assertFalse(SiteServiceResource::canView(new SiteService));
    }

    public function test_owner_keeps_dashboard_users_and_seo_database_connections(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertContains(Dashboard::class, $panel->getPages());
        $this->assertContains(UserResource::class, $panel->getResources());
        $this->assertContains(SeoDatabaseConnectionResource::class, $panel->getResources());

        $this->actingAs($this->userWithRole(User::ROLE_OWNER));
        $this->assertTrue(UserResource::canAccess());
        $this->assertTrue(ControlServer::canAccess());
        $this->assertContains(ControlServer::class, $panel->getPages());
        $this->assertTrue(\App\Filament\Pages\HelpTopicsAdmin::canAccess());
        $this->assertContains(\App\Filament\Pages\HelpTopicsAdmin::class, $panel->getPages());
        $this->assertContains(\App\Filament\Pages\HelpTopicEdit::class, $panel->getPages());
        $this->assertContains(\App\Filament\Pages\HelpTopicCreate::class, $panel->getPages());

        $this->actingAs($this->userWithRole(User::ROLE_ADMIN));
        $this->assertTrue(\App\Filament\Pages\HelpTopicsAdmin::canAccess());
        $this->assertTrue(\App\Filament\Pages\HelpTopicsAdmin::shouldRegisterNavigation());
        $this->assertFalse(\App\Filament\Pages\HelpTopicEdit::shouldRegisterNavigation());
    }

    public function test_service_runtime_catalog_and_provider_boot_remain(): void
    {
        $this->assertTrue(class_exists(Service::class));
        $this->assertTrue(class_exists(SiteService::class));
        $this->assertFileExists(database_path('migrations/2026_02_07_125524_create_services_table.php'));
        $this->assertFileExists(database_path('migrations/2026_02_07_133604_create_site_services_table.php'));

        $provider = (string) file_get_contents(app_path('Providers/AppServiceProvider.php'));
        $this->assertStringContainsString("Service::where('is_active', true)", $provider);
        $this->assertStringContainsString('registerActiveAddonProviders', $provider);
    }

    public function test_no_database_drop_migration_added_for_saas_or_runtime_tables(): void
    {
        foreach ([
            '2026_02_07_125322_create_wallets_table.php',
            '2026_02_07_125430_create_transactions_table.php',
            '2026_02_07_125524_create_services_table.php',
            '2026_02_07_125525_create_service_plans_table.php',
            '2026_02_07_125527_create_orders_table.php',
            '2026_02_07_125530_create_invoices_table.php',
            '2026_02_07_133137_create_subscriptions_table.php',
            '2026_02_07_133138_create_usage_logs_table.php',
            '2026_02_07_133604_create_site_services_table.php',
        ] as $migration) {
            $this->assertFileExists(database_path('migrations/'.$migration));
        }

        $migrations = glob(database_path('migrations/*.php')) ?: [];
        foreach ($migrations as $file) {
            $base = basename((string) $file);
            $this->assertDoesNotMatchRegularExpression(
                '/drop_.*(wallets|transactions|orders|invoices|subscriptions|usage_logs|service_plans|services_table|site_services)/',
                $base,
            );
        }
    }

    private function userWithRole(string $role, ?int $parentId = null): User
    {
        $user = new User([
            'role' => $role,
            'status' => User::STATUS_NORMAL,
            'parent_id' => $parentId,
        ]);
        $user->id = 1;

        return $user;
    }
}
