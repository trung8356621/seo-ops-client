<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Api\Auth\ServiceApiCredentialManager;
use App\Models\Service;
use App\Models\Site;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ServiceApi\ServiceApiDraftIntakeResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ServiceApi\ServiceApiDraftIntakeService;
use Omnichannel\Addons\Seo\Services\SeoAudit\Agent\SeoAuditAgentReadService;
use Tests\TestCase;
use Tests\Unit\Api\UsesServiceApiCredentialSchema;

final class SeoToolApiServiceApiHttpTest extends TestCase
{
    use UsesServiceApiCredentialSchema;

    private Service $seo;
    private Service $media;

    private string $seoReadKey = '';
    private string $draftWriteKey = '';
    private string $wildcardKey = '';
    private string $mediaKey = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootServiceApiCredentialSchema();
        $this->bootSitesSchema();

        $this->seo = $this->makeService('SEO', 'seo');
        $this->media = $this->makeService('Media', 'media');

        $manager = app(ServiceApiCredentialManager::class);

        $this->seoReadKey = $manager->create(
            $this->seo,
            'SEO Read Key',
            ['seo:read']
        )->rawKey;

        $this->draftWriteKey = $manager->create(
            $this->seo,
            'Draft Write Key',
            ['content-projects:draft:write']
        )->rawKey;

        $this->wildcardKey = $manager->create(
            $this->seo,
            'All Scopes Key',
            ['*']
        )->rawKey;

        $this->mediaKey = $manager->create(
            $this->media,
            'Media Service Key',
            ['*']
        )->rawKey;

        Site::query()->forceCreate([
            'id' => 123,
            'user_id' => 1,
            'domain' => 'example.test',
            'status' => 'active',
            'ssl' => true,
        ]);
    }

    public function test_tools_index_requires_auth(): void
    {
        $res = $this->getJson('/api/v1/services/seo/tools');
        $res->assertStatus(401);
    }

    public function test_tools_index_rejects_wrong_service(): void
    {
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->mediaKey)
            ->getJson('/api/v1/services/seo/tools');
        $res->assertStatus(403);
    }

    public function test_tools_index_filters_by_caller_scopes(): void
    {
        // 1. Caller with only seo:read
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->seoReadKey)
            ->getJson('/api/v1/services/seo/tools');

        $res->assertStatus(200);
        $tools = $res->json('data.tools');
        $this->assertIsArray($tools);
        $keys = array_column($tools, 'key');
        $this->assertContains('seo_audit.list', $keys);
        $this->assertNotContains('content_project.draft_intake', $keys);
        $this->assertFalse($tools[0]['availability']['available']);

        $withSite = $this->withHeader('Authorization', 'Bearer ' . $this->seoReadKey)
            ->withHeader('X-Site-Ref', 'site:123')
            ->getJson('/api/v1/services/seo/tools');
        $withSite->assertStatus(200)->assertJsonPath('data.tools.0.availability.available', true);

        // 2. Caller with wildcard * sees both tools
        $resAdmin = $this->withHeader('Authorization', 'Bearer ' . $this->wildcardKey)
            ->getJson('/api/v1/services/seo/tools');

        $resAdmin->assertStatus(200);
        $adminTools = $resAdmin->json('data.tools');
        $adminKeys = array_column($adminTools, 'key');
        $this->assertContains('seo_audit.list', $adminKeys);
        $this->assertContains('content_project.draft_intake', $adminKeys);
    }

    public function test_tool_execute_not_found(): void
    {
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->wildcardKey)
            ->postJson('/api/v1/services/seo/tools/nonexistent.tool/execute', []);

        $res->assertStatus(404);
        $res->assertJsonPath('error.code', 'tool_not_found');
    }

    public function test_tool_execute_forbidden_scope(): void
    {
        // Calling draft intake with only seo:read
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->seoReadKey)
            ->postJson('/api/v1/services/seo/tools/content_project.draft_intake/execute', [
                'context' => ['site_ref' => 'site:123'],
                'input' => ['items' => [
                    ['keyword' => 'test', 'type' => 'new'],
                ]],
                'confirmed' => true,
            ]);

        $res->assertStatus(403);
        $res->assertJsonPath('error.code', 'scope_denied');
    }

    public function test_tool_execute_missing_context(): void
    {
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->seoReadKey)
            ->postJson('/api/v1/services/seo/tools/seo_audit.list/execute', [
                'input' => ['limit' => 10],
            ]);

        $res->assertStatus(422);
        $res->assertJsonPath('error.code', 'missing_context');
    }

    public function test_tool_execute_rejects_mismatched_site_context(): void
    {
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->seoReadKey)
            ->withHeader('X-Site-Ref', 'site:123')
            ->postJson('/api/v1/services/seo/tools/seo_audit.list/execute', [
                'context' => ['site_ref' => 'site:999'],
                'input' => [],
            ]);

        $res->assertStatus(422)->assertJsonPath('error.code', 'context_mismatch');
    }

    public function test_tool_execute_rejects_site_id_in_public_input(): void
    {
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->draftWriteKey)
            ->withHeader('X-Site-Ref', 'site:123')
            ->postJson('/api/v1/services/seo/tools/content_project.draft_intake/execute', [
                'input' => [
                    'site_id' => 999,
                    'items' => [['keyword' => 'test', 'type' => 'new']],
                ],
                'confirmed' => true,
            ]);

        $res->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_tool_execute_confirmation_required(): void
    {
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->draftWriteKey)
            ->postJson('/api/v1/services/seo/tools/content_project.draft_intake/execute', [
                'context' => ['site_ref' => 'site:123'],
                'input' => ['items' => [
                    ['keyword' => 'test', 'type' => 'new'],
                ]],
                'confirmed' => false,
            ]);

        $res->assertStatus(422);
        $res->assertJsonPath('error.code', 'confirmation_required');
    }

    public function test_tool_execute_read_success_delegates(): void
    {
        $mockAuditService = $this->createMock(SeoAuditAgentReadService::class);
        $mockAuditService->expects($this->once())
            ->method('listArticles')
            ->willReturn([
                'items' => [
                    ['article_ref' => 'article:1', 'title' => 'Sample Article', 'score' => 70],
                ],
                'total' => 1,
                'post_type' => null,
            ]);

        $this->app->instance(SeoAuditAgentReadService::class, $mockAuditService);

        $res = $this->withHeader('Authorization', 'Bearer ' . $this->seoReadKey)
            ->withHeader('X-Site-Ref', 'site:123')
            ->postJson('/api/v1/services/seo/tools/seo_audit.list/execute', [
                'input' => ['limit' => 20],
            ]);

        $res->assertStatus(200);
        $res->assertJsonPath('data.tool', 'seo_audit.list');
        $res->assertJsonPath('data.result.total', 1);
        $res->assertJsonPath('data.result.items.0.title', 'Sample Article');
    }

    public function test_tool_execute_write_confirmed_success_delegates(): void
    {
        $mockDraftService = $this->createMock(ServiceApiDraftIntakeService::class);
        $mockDraftService->expects($this->once())
            ->method('intake')
            ->willReturn(new ServiceApiDraftIntakeResult(
                draftRef: 'project:50',
                siteRef: 'site:123',
                submitted: 1,
                added: 1,
                alreadyInDraft: 0,
                failed: 0,
                items: []
            ));

        $this->app->instance(ServiceApiDraftIntakeService::class, $mockDraftService);

        $res = $this->withHeader('Authorization', 'Bearer ' . $this->draftWriteKey)
            ->postJson('/api/v1/services/seo/tools/content_project.draft_intake/execute', [
                'context' => ['site_ref' => 'site:123'],
                'input' => ['items' => [
                    ['keyword' => 'brand new keyword', 'type' => 'new'],
                ]],
                'confirmed' => true,
            ]);

        $res->assertStatus(200);
        $res->assertJsonPath('data.tool', 'content_project.draft_intake');
        $res->assertJsonPath('data.result.draft_ref', 'project:50');
        $res->assertJsonPath('data.result.added', 1);
    }

    private function bootSitesSchema(): void
    {
        \Illuminate\Support\Facades\Schema::dropIfExists('sites');
        \Illuminate\Support\Facades\Schema::create('sites', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->string('domain');
            $table->string('status')->default('active');
            $table->boolean('ssl')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function makeService(string $name, string $slug, bool $active = true): Service
    {
        return Service::query()->create([
            'name' => $name,
            'slug' => $slug,
            'addon_namespace' => 'Omnichannel\\Addons\\Seo',
            'db_connection' => 'mysql',
            'is_active' => $active,
            'config' => [],
            'service_key' => 'provisioned-internal-key',
        ]);
    }
}
