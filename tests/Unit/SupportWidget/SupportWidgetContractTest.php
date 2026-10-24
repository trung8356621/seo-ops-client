<?php

declare(strict_types=1);

namespace Tests\Unit\SupportWidget;

use App\Core\ClientCoreServiceProvider;
use App\Http\Controllers\SupportWidgetSessionController;
use App\Policies\SupportWidgetPolicy;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

final class SupportWidgetContractTest extends TestCase
{
    #[Test]
    public function header_hook_and_session_route_exist_without_local_ticket_model(): void
    {
        $provider = (string) file_get_contents(
            (new ReflectionClass(ClientCoreServiceProvider::class))->getFileName()
        );
        self::assertStringContainsString('registerSupportWidgetHeaderHook', $provider);
        self::assertStringContainsString('support-widget-header', $provider);
        self::assertStringNotContainsString('registerSupportTicketHeaderHook', $provider);

        self::assertFileExists(resource_path('views/filament/hooks/support-widget-header.blade.php'));
        self::assertFileExists(resource_path('js/support-widget/headerSupportWidget.js'));
        self::assertFileExists((new ReflectionClass(SupportWidgetSessionController::class))->getFileName());
        self::assertFileExists((new ReflectionClass(SupportWidgetPolicy::class))->getFileName());

        self::assertFileDoesNotExist(app_path('Models/SupportTicket.php'));
        self::assertFileDoesNotExist(app_path('Http/Controllers/SupportTicketController.php'));
        self::assertFileDoesNotExist(app_path('Services/SupportTickets/SupportTicketSubmitService.php'));

        $routes = (string) file_get_contents(base_path('routes/web.php'));
        self::assertStringContainsString('support-widget.session', $routes);
        self::assertStringNotContainsString('support-tickets.store', $routes);
        self::assertStringNotContainsString('api/support-tickets', $routes);
    }

    #[Test]
    public function header_js_lazy_loads_ops_support_widget_and_handles_offline(): void
    {
        $js = (string) file_get_contents(resource_path('js/support-widget/headerSupportWidget.js'));
        self::assertStringContainsString('OpsSupportWidget', $js);
        self::assertStringContainsString('loadScriptOnce', $js);
        self::assertStringContainsString('Hỗ trợ tạm thời không khả dụng', $js);
        self::assertStringContainsString('server_offline', $js);
        self::assertStringNotContainsString('support-tickets.store', $js);
        self::assertStringNotContainsString('/api/support-tickets', $js);
    }
}
