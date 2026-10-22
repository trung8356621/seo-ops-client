<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Addon\AddonManifest;
use App\Core\Addon\AddonReadinessValidator;
use App\Core\Capability\CapabilityRegistry;
use App\Core\Addon\AddonDependencyValidator;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class AddonFaultIsolationTest extends TestCase
{
    private AddonReadinessValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $capabilities = new CapabilityRegistry;
        $capabilities->register('search.keyword', (object) ['id' => 'search.keyword'], 'search-foundation');

        $this->validator = new AddonReadinessValidator(
            new AddonDependencyValidator($capabilities)
        );
    }

    public function test_explicit_deployment_ready_false_fails_validation(): void
    {
        $manifest = new AddonManifest(
            slug: 'unfinished-addon',
            name: 'Unfinished Addon',
            version: '0.1.0',
            provider: 'App\\Providers\\AppServiceProvider',
            path: '/path/to/addon',
            raw: ['deployment_ready' => false],
        );

        $result = $this->validator->validateManifest($manifest);

        self::assertFalse($result->isReady());
        self::assertStringContainsString('not marked or configured as deployment_ready', $result->diagnostic());
    }

    public function test_unlisted_addon_without_explicit_ready_fails_validation(): void
    {
        Config::set('addons.deployment_ready_slugs', ['seo', 'search-foundation']);

        $manifest = new AddonManifest(
            slug: 'unknown-addon',
            name: 'Unknown Addon',
            version: '0.1.0',
            provider: 'App\\Providers\\AppServiceProvider',
            path: '/path/to/addon',
            raw: [],
        );

        $result = $this->validator->validateManifest($manifest);

        self::assertFalse($result->isReady());
        self::assertStringContainsString('not marked or configured as deployment_ready', $result->diagnostic());
    }

    public function test_missing_composer_namespace_fails_readiness(): void
    {
        $manifest = new AddonManifest(
            slug: 'non-autoloaded-addon',
            name: 'Non Autoloaded Addon',
            version: '0.1.0',
            provider: 'Omnichannel\\Addons\\NonExistent\\NonExistentServiceProvider',
            path: '/path/to/addon',
            raw: ['deployment_ready' => true],
        );

        $result = $this->validator->validateManifest($manifest);

        self::assertFalse($result->isReady());
        self::assertStringContainsString('Main provider class', $result->diagnostic());
        self::assertStringContainsString('not autoloadable', $result->diagnostic());
    }

    public function test_missing_panel_provider_fails_readiness(): void
    {
        $manifest = new AddonManifest(
            slug: 'broken-panel-addon',
            name: 'Broken Panel Addon',
            version: '0.1.0',
            provider: 'App\\Providers\\AppServiceProvider',
            path: '/path/to/addon',
            panelProvider: 'Omnichannel\\Addons\\NonExistent\\BrokenPanelProvider',
            raw: ['deployment_ready' => true],
        );

        $result = $this->validator->validateManifest($manifest);

        self::assertFalse($result->isReady());
        self::assertStringContainsString('Panel provider class', $result->diagnostic());
        self::assertStringContainsString('not autoloadable', $result->diagnostic());
    }

    public function test_missing_required_dependency_fails_readiness(): void
    {
        $manifest = new AddonManifest(
            slug: 'needs-missing-cap',
            name: 'Needs Missing Cap',
            version: '0.1.0',
            provider: 'App\\Providers\\AppServiceProvider',
            path: '/path/to/addon',
            requires: ['completely.nonexistent.capability'],
            raw: ['deployment_ready' => true],
        );

        $result = $this->validator->validateManifest($manifest);

        self::assertFalse($result->isReady());
        self::assertStringContainsString('Missing declared dependencies', $result->diagnostic());
    }

    public function test_route_inspector_detects_missing_controller_class(): void
    {
        $routeContent = <<<'PHP'
<?php
use Illuminate\Support\Facades\Route;
use Omnichannel\Addons\NonExistent\Http\Controllers\MissingController;

Route::get('test', [MissingController::class, 'index']);
PHP;

        $violations = $this->validator->inspectRouteContent($routeContent);

        self::assertNotEmpty($violations);
        self::assertStringContainsString('Controller class [Omnichannel\Addons\NonExistent\Http\Controllers\MissingController] does not exist', $violations[0]);
    }

    public function test_route_inspector_detects_missing_controller_action(): void
    {
        $routeContent = <<<'PHP'
<?php
use Illuminate\Support\Facades\Route;
use App\Api\Http\Controllers\ServiceApiStatusController;

Route::get('test', [ServiceApiStatusController::class, 'nonExistentActionMethod']);
PHP;

        $violations = $this->validator->inspectRouteContent($routeContent);

        self::assertNotEmpty($violations);
        self::assertStringContainsString('Controller method', $violations[0]);
        self::assertStringContainsString('nonExistentActionMethod', $violations[0]);
    }

    public function test_route_inspector_detects_missing_invokable_method(): void
    {
        $routeContent = <<<'PHP'
<?php
use Illuminate\Support\Facades\Route;
use App\Models\User;

Route::get('test', User::class);
PHP;

        $violations = $this->validator->inspectRouteContent($routeContent);

        self::assertNotEmpty($violations);
        self::assertStringContainsString('not invokable (missing __invoke)', $violations[0]);
    }

    public function test_route_inspector_passes_valid_route_definitions(): void
    {
        $routeContent = <<<'PHP'
<?php
use Illuminate\Support\Facades\Route;
use App\Api\Http\Controllers\ServiceApiStatusController;

Route::get('{service}/status', ServiceApiStatusController::class);
PHP;

        $violations = $this->validator->inspectRouteContent($routeContent);

        self::assertEmpty($violations);
    }

    public function test_route_file_readiness_validates_existing_seo_service_api(): void
    {
        Config::set('addons.deployment_ready_slugs', ['seo']);

        $path = base_path('addons/seo/routes/api-services.php');
        if (! is_file($path)) {
            $path = dirname(base_path()).'/omnichannel-addons/seo/routes/api-services.php';
        }

        self::assertFileExists($path);
        $result = $this->validator->validateRouteFile('seo', $path);

        self::assertTrue($result->isReady(), $result->diagnostic());
    }
}
