<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\ServiceApi\ServiceApiDraftIntakeItemResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ServiceApi\ServiceApiDraftIntakeResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ServiceApi\ServiceApiDraftIntakeService;
use ReflectionClass;
use Tests\TestCase;

final class ServiceApiDraftIntakeServiceTest extends TestCase
{
    public function test_constants_and_result_envelope(): void
    {
        self::assertSame('content_project.draft.intake', ServiceApiDraftIntakeService::ACTION);
        self::assertSame('content-projects:draft:write', ServiceApiDraftIntakeService::SCOPE);
        self::assertSame(100, ServiceApiDraftIntakeService::MAX_BATCH);
        self::assertSame(['new', 'rewrite'], ServiceApiDraftIntakeService::ALLOWED_TYPES);

        $result = new ServiceApiDraftIntakeResult(
            draftRef: 'cpj_x',
            siteRef: 'site:12',
            submitted: 2,
            added: 1,
            alreadyInDraft: 1,
            failed: 0,
            items: [
                new ServiceApiDraftIntakeItemResult(0, 'added', 'cpi_1'),
                new ServiceApiDraftIntakeItemResult(1, 'already_in_draft', 'cpi_2'),
            ],
            idempotentReplay: true,
        );

        $arr = $result->toArray();
        self::assertSame('cpj_x', $arr['draft_ref']);
        self::assertSame('site:12', $arr['site_ref']);
        self::assertSame(1, $arr['added']);
        self::assertTrue($arr['idempotent_replay']);
        self::assertSame('added', $arr['items'][0]['status']);
    }

    public function test_gateway_wires_canonical_services_not_lifecycle(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ServiceApiDraftIntakeService::class))->getFileName()
        );

        self::assertStringContainsString('PlanningDraftIntakeService', $src);
        self::assertStringContainsString('AddContentProjectItemsCommand', $src);
        self::assertStringContainsString('ContentProjectIdempotencyStore', $src);
        self::assertStringContainsString('SeoContentProjectItemOrigin', $src);
        self::assertStringContainsString('ContentProjectItemIdentity', $src);
        self::assertStringContainsString('TYPE_CREATE', $src);
        self::assertStringContainsString('TYPE_REWRITE', $src);
        self::assertStringNotContainsString('GenerateProjectItemsCommand', $src);
        self::assertStringNotContainsString('PublishProjectItemsNowCommand', $src);
        self::assertStringNotContainsString('ScheduleProjectItemsCommand', $src);
        self::assertStringNotContainsString('ArchiveContentProjectCommand', $src);
    }
}
