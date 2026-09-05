<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use App\Filament\Pages\ServiceConfigure;
use App\Filament\Pages\ServiceStatusOverview;
use App\Filament\Resources\SeedingDatabaseConnectionResource;
use App\Filament\Resources\SeoDatabaseConnectionResource;
use App\Filament\Widgets\ServiceQuickShortcutsWidget;
use App\Models\User;
use Filament\Facades\Filament;
use Tests\TestCase;

final class ServiceCentricAdminUiContractTest extends TestCase
{
    public function test_admin_registers_service_pages_not_connection_nav(): void
    {
        $panel = Filament::getPanel('admin');

        self::assertContains(ServiceStatusOverview::class, $panel->getPages());
        self::assertContains(ServiceConfigure::class, $panel->getPages());
        self::assertContains(ServiceQuickShortcutsWidget::class, $panel->getWidgets());

        self::assertSame('services', ServiceStatusOverview::getSlug());
        self::assertFalse(SeoDatabaseConnectionResource::shouldRegisterNavigation());
        self::assertFalse(SeedingDatabaseConnectionResource::shouldRegisterNavigation());
    }

    public function test_service_configure_urls_and_no_raw_key_in_view(): void
    {
        $owner = new User([
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);
        $this->actingAs($owner);

        $seoUrl = ServiceConfigure::getUrl(['service' => 'seo']);
        $seedUrl = ServiceConfigure::getUrl(['service' => 'seeding']);
        self::assertStringContainsString('/admin/services/seo', $seoUrl);
        self::assertStringContainsString('/admin/services/seeding', $seedUrl);

        $view = (string) file_get_contents(resource_path('views/filament/pages/service-configure.blade.php'));
        self::assertStringContainsString('key_provisioned', $view);
        self::assertStringNotContainsString('service_key', $view);
        self::assertStringNotContainsString('{{ $svc->service_key', $view);
    }

    public function test_overview_has_no_entitlement_controls(): void
    {
        $blade = (string) file_get_contents(resource_path('views/filament/pages/service-status-overview.blade.php'));
        $src = (string) file_get_contents(app_path('Filament/Pages/ServiceStatusOverview.php'));

        self::assertStringContainsString('Cấu hình', $blade);
        self::assertStringContainsString('canAccess', $src);
        self::assertDoesNotMatchRegularExpression('/\b(Activate|Deactivate|Install Service|Delete Service)\b/', $src);
        self::assertStringNotContainsString('wire:click', $blade);
        self::assertStringContainsString('ops-server provision', $blade);
    }
}
