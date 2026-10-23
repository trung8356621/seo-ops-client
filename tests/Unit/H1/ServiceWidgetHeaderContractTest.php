<?php

declare(strict_types=1);

namespace Tests\Unit\H1;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ServiceWidgetHeaderContractTest extends TestCase
{
    #[Test]
    public function core_registers_service_widget_header_hook(): void
    {
        $source = (string) file_get_contents(app_path('Core/ClientCoreServiceProvider.php'));
        self::assertStringContainsString('registerServiceWidgetHeaderHook', $source);
        self::assertStringContainsString("view('filament.hooks.service-widget-header')", $source);
        self::assertStringContainsString('PanelsRenderHook::USER_MENU_BEFORE', $source);
    }

    #[Test]
    public function header_blade_and_assets_exist(): void
    {
        self::assertFileExists(resource_path('views/filament/hooks/service-widget-header.blade.php'));
        self::assertFileExists(resource_path('js/service-widget/headerServiceWidget.js'));
        self::assertFileExists(resource_path('css/service-widget-header.css'));
        self::assertFileExists(public_path('vendor/ops-service-widget/ops-service-widget.js'));

        $blade = (string) file_get_contents(resource_path('views/filament/hooks/service-widget-header.blade.php'));
        self::assertStringContainsString('Dịch vụ', $blade);
        self::assertStringContainsString('data-service-widget-header', $blade);
        self::assertStringContainsString('service-widget.session', $blade);
        self::assertStringContainsString('headerServiceWidget.js', $blade);
    }

    #[Test]
    public function lazy_loader_does_not_fetch_until_click_handler(): void
    {
        $js = (string) file_get_contents(resource_path('js/service-widget/headerServiceWidget.js'));
        self::assertStringContainsString('No OPS-SERVER network until the user clicks', $js);
        self::assertStringContainsString('fetchSession', $js);
        self::assertStringContainsString('loadScriptOnce', $js);
        self::assertStringContainsString("useMock: false", $js);
        // boot only binds click handlers — must not call fetch/session at import time.
        self::assertDoesNotMatchRegularExpression('/boot\(\)[\s\S]*fetchSession\(/', $js);
    }

    #[Test]
    public function session_route_is_registered(): void
    {
        self::assertTrue(
            collect(\Illuminate\Support\Facades\Route::getRoutes())->contains(
                static fn ($route): bool => $route->getName() === 'service-widget.session'
            )
        );
    }

    #[Test]
    public function widget_js_never_embeds_h1_secret_keys(): void
    {
        $js = (string) file_get_contents(resource_path('js/service-widget/headerServiceWidget.js'));
        self::assertStringNotContainsString('secretBase64Url', $js);
        self::assertStringNotContainsString('machine_secret', $js);
        self::assertStringNotContainsString('enrollmentToken', $js);
        self::assertStringNotContainsString('H1Credential', $js);
    }
}
