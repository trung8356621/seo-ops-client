<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use App\Api\Middleware\AuthenticateServiceApi;
use App\Control\Commands\Handlers\ServicesApplyHandler;
use App\Filament\Pages\ServiceConfigure;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use Tests\TestCase;

/**
 * Guardrails: Service API credential plane stays independent of service_key provisioning.
 */
final class ServiceApiArchitectureGuardContractTest extends TestCase
{
    public function test_authenticate_middleware_does_not_read_service_key_or_site_service_api_key(): void
    {
        $src = (string) file_get_contents(app_path('Api/Middleware/AuthenticateServiceApi.php'));
        self::assertStringNotContainsString('->service_key', $src);
        self::assertStringNotContainsString("['service_key']", $src);
        self::assertStringNotContainsString('SiteService', $src);
        self::assertStringNotContainsString('settings.api_key', $src);
        self::assertStringNotContainsString("settings['api_key']", $src);
        self::assertStringContainsString('ApiKeyResolver', $src);
        self::assertStringContainsString('ServiceApiCredential', $src);
    }

    public function test_credential_table_has_no_plaintext_key_columns(): void
    {
        $migration = (string) file_get_contents(database_path(
            'migrations/2026_09_26_100000_create_service_api_credentials_table.php'
        ));
        self::assertStringContainsString('key_prefix', $migration);
        self::assertStringContainsString('key_hash', $migration);
        self::assertStringNotContainsString("'raw_key'", $migration);
        self::assertStringNotContainsString("'api_key'", $migration);
        self::assertStringNotContainsString('->string(\'api_key\'', $migration);
        self::assertStringNotContainsString('->text(\'api_key\'', $migration);
    }

    public function test_services_apply_handler_does_not_write_api_credentials(): void
    {
        $src = (string) file_get_contents(app_path('Control/Commands/Handlers/ServicesApplyHandler.php'));
        self::assertStringNotContainsString('ServiceApiCredential', $src);
        self::assertStringNotContainsString('service_api_credentials', $src);
        self::assertStringNotContainsString('ApiKeyGenerator', $src);
        self::assertSame(ServicesApplyHandler::class, ServicesApplyHandler::class);
    }

    public function test_service_api_controllers_do_not_import_control_handlers(): void
    {
        $dir = app_path('Api');
        if (! is_dir($dir)) {
            self::fail('app/Api missing');
        }

        $iterator = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)),
            '/\.php$/',
        );

        foreach ($iterator as $file) {
            $src = (string) file_get_contents($file->getPathname());
            self::assertStringNotContainsString(
                'App\\Control\\Commands\\Handlers',
                $src,
                $file->getPathname(),
            );
            self::assertStringNotContainsString(
                'ServicesApplyHandler',
                $src,
                $file->getPathname(),
            );
        }
    }

    public function test_admin_view_never_renders_hash_or_service_key(): void
    {
        $view = (string) file_get_contents(resource_path('views/filament/pages/service-configure.blade.php'));
        $page = (string) file_get_contents(app_path('Filament/Pages/ServiceConfigure.php'));

        self::assertStringContainsString('api_access_section_title', $view);
        self::assertStringContainsString('revealedApiKey', $view);
        self::assertStringNotContainsString('key_hash', $view);
        self::assertStringNotContainsString('service_key', $view);
        self::assertStringNotContainsString('{{ $svc->service_key', $view);
        self::assertStringNotContainsString('$cred->key_hash', $view);
        self::assertStringNotContainsString('$cred->service_key', $view);

        self::assertStringContainsString('revealedApiKey', $page);
        self::assertStringNotContainsString('$cred->key_hash', $page);
        self::assertStringNotContainsString('->service_key', $page);
        self::assertDoesNotMatchRegularExpression('/\becho\b.*key_hash/', $page);
        self::assertSame(ServiceConfigure::class, ServiceConfigure::class);
        self::assertSame(AuthenticateServiceApi::class, AuthenticateServiceApi::class);
    }

    public function test_scope_middleware_and_route_registered(): void
    {
        $routes = (string) file_get_contents(base_path('routes/api-services.php'));
        $boot = (string) file_get_contents(base_path('bootstrap/app.php'));

        self::assertStringContainsString('service.api.scope:service:read', $routes);
        self::assertStringContainsString('{service}/status', $routes);
        self::assertStringContainsString('api-services.php', $boot);
        self::assertStringContainsString('api/v1/services', $boot);
        self::assertStringContainsString('AuthenticateServiceApi', $boot);
    }
}
