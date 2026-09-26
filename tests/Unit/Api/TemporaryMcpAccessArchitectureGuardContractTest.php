<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use Tests\TestCase;

/**
 * Temporary MCP access must stay on Core Service API plane — no Agent runtime.
 */
final class TemporaryMcpAccessArchitectureGuardContractTest extends TestCase
{
    public function test_temporary_mcp_core_does_not_depend_on_agent_or_legacy_keys(): void
    {
        $files = [
            app_path('Api/Mcp/TemporaryMcpAccessManager.php'),
            app_path('Api/Mcp/TemporaryMcpAccessResolver.php'),
            app_path('Api/Mcp/TemporaryMcpAccessContext.php'),
            app_path('Api/Mcp/TemporaryMcpAccessIssueResult.php'),
            app_path('Api/Middleware/ResolveTemporaryMcpAccess.php'),
        ];

        foreach ($files as $path) {
            self::assertFileExists($path);
            $src = (string) file_get_contents($path);
            self::assertStringNotContainsString('AgentWorkspace', $src, $path);
            self::assertStringNotContainsString('AgentConfirmationToken', $src, $path);
            self::assertStringNotContainsString('Omnichannel\\Addons\\Agent', $src, $path);
            self::assertStringNotContainsString('->service_key', $src, $path);
            self::assertStringNotContainsString('SiteService', $src, $path);
            self::assertStringNotContainsString('settings.api_key', $src, $path);
            self::assertStringNotContainsString("settings['api_key']", $src, $path);
        }
    }

    public function test_temporary_seo_http_reuses_mcp_stack_without_duplicating_providers(): void
    {
        $controller = (string) file_get_contents(base_path(
            'addons/seo/src/Http/Controllers/ServiceApi/TemporaryMcpAccessController.php'
        ));
        $support = (string) file_get_contents(base_path(
            'addons/seo/src/Http/Controllers/ServiceApi/SeoMcpHttpSupport.php'
        ));

        self::assertStringContainsString('SeoMcpHttpSupport', $controller);
        self::assertStringContainsString('McpRouterRegistry', $support);
        self::assertStringContainsString('McpRouterReader', $support);
        self::assertStringNotContainsString('ContextSliceProvider', $controller);
        self::assertStringNotContainsString('AgentWorkspace', $controller);
        self::assertStringNotContainsString('AuthenticateServiceApi', $controller);
    }

    public function test_temporary_routes_registered_without_permanent_bearer_middleware(): void
    {
        $routes = (string) file_get_contents(base_path('addons/seo/routes/api-mcp-temporary.php'));
        $boot = (string) file_get_contents(base_path('bootstrap/app.php'));
        $permanent = (string) file_get_contents(base_path('addons/seo/routes/api-services.php'));

        self::assertStringContainsString('ResolveTemporaryMcpAccess', $routes);
        self::assertStringContainsString('throttle:temporary-mcp', $routes);
        self::assertStringNotContainsString("'service.api'", $routes);
        self::assertStringNotContainsString('service.api.scope', $routes);
        self::assertDoesNotMatchRegularExpression(
            "/middleware:\\s*\\[[^\\]]*AuthenticateServiceApi/",
            $routes,
        );
        self::assertStringContainsString('api/v1/mcp/access', $boot);
        self::assertStringContainsString('mcp/access', $permanent);
        self::assertStringContainsString('{service}/mcp', $permanent);
    }

    public function test_app_api_mcp_folder_has_no_control_or_agent_imports(): void
    {
        $dir = app_path('Api/Mcp');
        if (! is_dir($dir)) {
            self::fail('app/Api/Mcp missing');
        }

        $iterator = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)),
            '/\.php$/',
        );

        foreach ($iterator as $file) {
            $src = (string) file_get_contents($file->getPathname());
            self::assertStringNotContainsString('App\\Control\\', $src, $file->getPathname());
            self::assertStringNotContainsString('Addons\\Agent', $src, $file->getPathname());
        }
    }
}
