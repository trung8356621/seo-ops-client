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
        self::assertStringContainsString('ServiceWidgetPolicy::allows(auth()->user())', $source);
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
        self::assertStringContainsString('absolute: false', $blade);
        self::assertStringContainsString('headerServiceWidget.js', $blade);
        self::assertStringContainsString('data-ops-service-widget-host', $blade);
        self::assertStringContainsString('wire:ignore', $blade);
    }

    #[Test]
    public function lazy_loader_does_not_fetch_until_click_handler(): void
    {
        $js = (string) file_get_contents(resource_path('js/service-widget/headerServiceWidget.js'));
        self::assertStringContainsString('No OPS-SERVER network until the user clicks', $js);
        self::assertStringContainsString('fetchSession', $js);
        self::assertStringContainsString('loadScriptOnce', $js);
        self::assertStringContainsString('useMock: false', $js);
        self::assertStringContainsString('setAuthContext', $js);
        self::assertStringContainsString('installationId: session.installationId', $js);
        self::assertStringContainsString("method: 'POST'", $js);
        self::assertStringContainsString("redirect: 'error'", $js);
        self::assertStringContainsString("startsWith('/')", $js);
        // boot only binds click handlers — must not call fetch/session at import time.
        self::assertDoesNotMatchRegularExpression('/boot\(\)[\s\S]*fetchSession\(/', $js);
    }

    #[Test]
    public function header_uses_body_only_embed_contract(): void
    {
        $js = (string) file_get_contents(resource_path('js/service-widget/headerServiceWidget.js'));

        self::assertStringNotContainsString('clickWidgetTrigger', $js);
        self::assertStringNotContainsString('ops-sw__trigger', $js);
        self::assertStringNotContainsString('ops-sw__popover', $js);
        self::assertStringNotContainsString('data-ops-sw-header-hide-trigger', $js);
        self::assertStringNotContainsString('dispatchEvent(new MouseEvent', $js);

        self::assertStringContainsString('livewire:navigated', $js);
        self::assertStringContainsString('tokenNeedsRefresh', $js);
        self::assertStringContainsString('Hide only', $js);
        self::assertStringContainsString('Do not unmount on session refresh', $js);
        self::assertStringContainsString('applyHostFillLayout', $js);
        self::assertStringContainsString('Never show Laravel Retry alongside the React body', $js);
        self::assertStringContainsString('portalDropdown', $js);
        self::assertStringContainsString("document.body.appendChild(dropdown)", $js);
    }

    #[Test]
    public function header_css_hides_shell_layers_and_fills_dropdown_width(): void
    {
        $css = (string) file_get_contents(resource_path('css/service-widget-header.css'));
        self::assertStringContainsString('display: none !important', $css);
        self::assertStringContainsString('.service-widget-header__error:not([hidden])', $css);
        self::assertStringContainsString('width: 440px', $css);
        self::assertStringContainsString('position: fixed', $css);
        self::assertStringContainsString('[data-ops-service-widget-host]:not([hidden])', $css);
        self::assertStringContainsString('min-width: 0', $css);
        self::assertStringNotContainsString('transform:', $css);
        self::assertStringNotContainsString('scale(', $css);
    }

    #[Test]
    public function vendor_bundle_is_body_only_ops_build(): void
    {
        $bundle = (string) file_get_contents(public_path('vendor/ops-service-widget/ops-service-widget.js'));
        self::assertStringContainsString('OpsServiceWidget', $bundle);
        self::assertStringContainsString('setAuthContext', $bundle);
        self::assertStringContainsString('ops-service-widget-body', $bundle);
        // Old dual-trigger chrome must not ship in the host vendor copy.
        self::assertStringNotContainsString('ops-sw__trigger', $bundle);
        self::assertStringNotContainsString('ops-sw__popover', $bundle);
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
