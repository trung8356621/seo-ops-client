<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use Tests\TestCase;

/**
 * Temporary Service Access must stay on Core Service API plane — no Agent runtime.
 */
final class TemporaryServiceAccessArchitectureGuardContractTest extends TestCase
{
    public function test_temporary_access_core_does_not_depend_on_agent_or_legacy_keys(): void
    {
        $files = [
            app_path('Api/Access/TemporaryServiceAccessManager.php'),
            app_path('Api/Access/TemporaryServiceAccessResolver.php'),
            app_path('Api/Access/TemporaryServiceAccessContext.php'),
            app_path('Api/Access/TemporaryServiceAccessIssueResult.php'),
            app_path('Api/Middleware/ResolveTemporaryServiceAccess.php'),
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

    public function test_temporary_seo_http_is_read_only_and_curated(): void
    {
        $controller = (string) file_get_contents(base_path(
            'addons/seo/src/Http/Controllers/ServiceApi/TemporarySeoAccessController.php'
        ));
        $routes = (string) file_get_contents(base_path('addons/seo/routes/api-access-temporary.php'));

        self::assertStringNotContainsString('AuthenticateServiceApi', $controller);
        self::assertStringNotContainsString('AgentWorkspace', $controller);
        self::assertStringNotContainsString('DraftIntake', $controller);
        self::assertStringNotContainsString('Publish', $controller);
        self::assertStringNotContainsString('GenerateProject', $controller);
        self::assertStringNotContainsString('McpRouterReader', $controller);
        self::assertStringContainsString('throttle:temporary-access', $routes);
        self::assertStringNotContainsString("'service.api'", $routes);
        self::assertStringNotContainsString('draft/intake', $routes);
        self::assertDoesNotMatchRegularExpression(
            "/middleware:\\s*\\[[^\\]]*AuthenticateServiceApi/",
            $routes,
        );
    }

    public function test_access_routes_registered_without_permanent_bearer_middleware(): void
    {
        $routes = (string) file_get_contents(base_path('addons/seo/routes/api-access-temporary.php'));
        $boot = (string) file_get_contents(base_path('bootstrap/app.php'));
        $permanent = (string) file_get_contents(base_path('addons/seo/routes/api-services.php'));

        self::assertStringContainsString('ResolveTemporaryServiceAccess', $routes);
        self::assertStringContainsString('api/v1/access', $boot);
        self::assertStringContainsString('{service}/access', $permanent);
        self::assertStringContainsString('seo:read', $permanent);
        self::assertStringNotContainsString('{service}/mcp', $permanent);
        self::assertFileDoesNotExist(base_path('addons/seo/routes/api-mcp-temporary.php'));
    }

    public function test_app_api_access_folder_has_no_control_or_agent_imports(): void
    {
        $dir = app_path('Api/Access');
        if (! is_dir($dir)) {
            self::fail('app/Api/Access missing');
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

    public function test_temporary_access_plane_does_not_call_write_surfaces(): void
    {
        $controller = (string) file_get_contents(base_path(
            'addons/seo/src/Http/Controllers/ServiceApi/TemporarySeoAccessController.php'
        ));
        $code = preg_replace('#/\*.*?\*/#s', '', $controller) ?? $controller;
        $code = preg_replace('#//.*$#m', '', $code) ?? $code;

        foreach ([
            'ContentProjectDraftIntake',
            'PlanningDraftIntake',
            'AddContentProjectItemsCommand',
            'PublishProjectItems',
            'GenerateProjectItems',
            'WordPressPublish',
            'AgentWorkspace',
            'AgentExecute',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $code, $forbidden);
        }
    }
}
