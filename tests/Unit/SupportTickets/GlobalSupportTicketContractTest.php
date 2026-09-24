<?php

declare(strict_types=1);

namespace Tests\Unit\SupportTickets;

use App\Core\ClientCoreServiceProvider;
use App\Core\Workspace\ServiceTopbarRouter;
use App\Filament\Resources\SupportTicketResource;
use App\Http\Controllers\SupportTicketController;
use App\Models\User;
use App\Services\SupportTickets\SupportTicketAttachmentService;
use App\Services\SupportTickets\SupportTicketSubmitService;
use ReflectionClass;
use Tests\TestCase;

final class GlobalSupportTicketContractTest extends TestCase
{
    public function test_core_registers_global_ticket_header_once(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ClientCoreServiceProvider::class))->getFileName()
        );

        self::assertStringContainsString('registerSupportTicketHeaderHook', $source);
        self::assertStringContainsString('PanelsRenderHook::USER_MENU_BEFORE', $source);
        self::assertStringContainsString('support-ticket-header', $source);
        self::assertStringContainsString('ServiceTopbarRouter::PANEL_IDS', $source);
    }

    public function test_global_ticket_assets_and_i18n_exist(): void
    {
        self::assertFileExists(resource_path('views/filament/hooks/support-ticket-header.blade.php'));
        self::assertFileExists(resource_path('js/support-ticket/headerTicketComposer.js'));
        self::assertFileExists(resource_path('css/support-ticket-header.css'));
        self::assertFileExists(lang_path('en/support_ticket.php'));
        self::assertFileExists(lang_path('vi/support_ticket.php'));

        $blade = (string) file_get_contents(resource_path('views/filament/hooks/support-ticket-header.blade.php'));
        self::assertStringContainsString('route(\'support-tickets.store\')', $blade);
        self::assertStringContainsString('data-support-ticket-header', $blade);
        self::assertStringContainsString('global-header-ticket-root', $blade);
        self::assertStringNotContainsString('seo.support-tickets.store', $blade);

        $js = (string) file_get_contents(resource_path('js/support-ticket/headerTicketComposer.js'));
        self::assertStringContainsString('files[]', $js);
        self::assertStringContainsString('clipboardData', $js);
        self::assertStringContainsString('paste', $js);
        self::assertStringContainsString('service', $js);

        app()->setLocale('en');
        self::assertSame('Ticket', __('support_ticket.trigger'));
        self::assertSame('Ticket submitted successfully.', __('support_ticket.messages.submitted'));

        app()->setLocale('vi');
        self::assertSame('Đã gửi ticket thành công.', __('support_ticket.messages.submitted'));
    }

    public function test_admin_resource_is_read_only_for_owner_admin(): void
    {
        self::assertFalse(SupportTicketResource::canCreate());
        self::assertFalse(SupportTicketResource::canDeleteAny());

        $source = (string) file_get_contents(
            (new ReflectionClass(SupportTicketResource::class))->getFileName()
        );
        self::assertStringContainsString('ROLE_OWNER', $source);
        self::assertStringContainsString('ROLE_ADMIN', $source);
        self::assertStringContainsString('ViewAction', $source);
        self::assertStringNotContainsString('CreateAction', $source);
        self::assertStringNotContainsString('EditAction', $source);
        self::assertStringNotContainsString('DeleteAction', $source);
        self::assertSame('support-tickets', (new ReflectionClass(SupportTicketResource::class))->getStaticPropertyValue('slug'));
    }

    public function test_submit_service_and_controller_are_client_owned(): void
    {
        self::assertTrue(class_exists(SupportTicketSubmitService::class));
        self::assertTrue(class_exists(SupportTicketAttachmentService::class));
        self::assertTrue(class_exists(SupportTicketController::class));

        $controller = (string) file_get_contents(
            (new ReflectionClass(SupportTicketController::class))->getFileName()
        );
        self::assertStringNotContainsString('SupportTicketDeliveryService', $controller);
        self::assertStringNotContainsString('SeoConnectionContext', $controller);
        self::assertStringNotContainsString('SeoAccessControl', $controller);

        $service = (string) file_get_contents(
            (new ReflectionClass(SupportTicketSubmitService::class))->getFileName()
        );
        self::assertStringContainsString('STATUS_QUEUED', $service);
        self::assertStringContainsString('support_ticket.messages.submitted', $service);
        self::assertStringNotContainsString('attemptDelivery', $service);
    }

    public function test_web_routes_register_global_support_tickets(): void
    {
        $routes = (string) file_get_contents(base_path('routes/web.php'));
        self::assertStringContainsString("prefix('api/support-tickets')", $routes);
        self::assertStringContainsString('support-tickets.store', $routes);
        self::assertStringContainsString('SupportTicketController::class', $routes);
    }

    public function test_panel_ids_cover_admin_seo_seeding(): void
    {
        self::assertSame(['admin', 'seo', 'seo-main', 'seeding'], ServiceTopbarRouter::PANEL_IDS);
    }

    public function test_unauthorized_role_cannot_access_admin_ticket_resource(): void
    {
        $user = new User;
        $user->role = User::ROLE_STAFF;
        $this->actingAs($user);
        self::assertFalse(SupportTicketResource::canAccess());
    }
}
