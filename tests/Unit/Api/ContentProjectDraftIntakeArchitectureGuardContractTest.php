<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use Omnichannel\Addons\ContentProjects\Http\Controllers\ServiceApi\ContentProjectDraftIntakeController;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ServiceApi\ServiceApiDraftIntakeService;
use ReflectionClass;
use Tests\TestCase;

final class ContentProjectDraftIntakeArchitectureGuardContractTest extends TestCase
{
    public function test_controller_and_gateway_boundary(): void
    {
        $controller = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectDraftIntakeController::class))->getFileName()
        );
        $gateway = (string) file_get_contents(
            (string) (new ReflectionClass(ServiceApiDraftIntakeService::class))->getFileName()
        );
        $routes = (string) file_get_contents(base_path('addons/content-projects/routes/api-services.php'));

        foreach ([$controller, $gateway, $routes] as $src) {
            $code = preg_replace('#/\*.*?\*/#s', '', $src) ?? $src;
            $code = preg_replace('#//.*$#m', '', $code) ?? $code;

            self::assertStringNotContainsString('GenerateProjectItemsCommand', $code);
            self::assertStringNotContainsString('StartReviewCommand', $code);
            self::assertStringNotContainsString('ApproveProjectItemsCommand', $code);
            self::assertStringNotContainsString('ScheduleProjectItemsCommand', $code);
            self::assertStringNotContainsString('AutoScheduleProjectItemsCommand', $code);
            self::assertStringNotContainsString('PublishProjectItemsNowCommand', $code);
            self::assertStringNotContainsString('RetryProjectItemPublishingCommand', $code);
            self::assertStringNotContainsString('CancelProjectItemPublishingCommand', $code);
            self::assertStringNotContainsString('ArchiveContentProjectCommand', $code);
            self::assertStringNotContainsString('RestoreContentProjectCommand', $code);
            self::assertStringNotContainsString('AgentWorkspace', $code);
            self::assertStringNotContainsString('->service_key', $code);
            self::assertStringNotContainsString('SiteService', $code);
            self::assertStringNotContainsString('settings.api_key', $code);
            self::assertStringNotContainsString('TemporaryServiceAccess', $code);
            self::assertStringNotContainsString('TemporaryMcpAccess', $code);
        }

        self::assertStringNotContainsString('SeoProjectTask::query()->create', $controller);
        self::assertStringNotContainsString('SeoProjectTask::create', $controller);
        self::assertStringContainsString('ServiceApiDraftIntakeService', $controller);
        self::assertStringContainsString('PlanningDraftIntakeService', $gateway);
        self::assertStringContainsString('AddContentProjectItemsCommand', $gateway);
        self::assertStringContainsString('content-projects:draft:write', $routes);
        self::assertStringContainsString('EnsureSeoServiceApi', $routes);
        self::assertStringNotContainsString('auth:sanctum', $routes);
    }

    public function test_temporary_access_routes_do_not_include_draft_intake(): void
    {
        $temp = base_path('addons/seo/routes/api-access-temporary.php');
        self::assertFileExists($temp);
        $src = (string) file_get_contents($temp);
        self::assertStringNotContainsString('draft/intake', $src);
        self::assertStringNotContainsString('content-projects', $src);
    }
}
