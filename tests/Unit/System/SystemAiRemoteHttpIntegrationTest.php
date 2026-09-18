<?php

declare(strict_types=1);

namespace Tests\Unit\System;

use App\System\Ai\Client\DefaultSystemAiClient;
use App\System\Ai\Dto\AiExecutionRequest;
use App\System\Ai\Transport\LegacyLocalAiTransport;
use App\System\Ai\Transport\RemoteHttpAiTransport;
use App\System\Capability\SystemCapabilityDefinition;
use App\System\Capability\SystemCapabilityHandler;
use App\System\Capability\SystemCapabilityRegistry;
use App\System\Http\Middleware\SystemApiTokenAuth;
use App\System\Support\CapabilityModeResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SystemAiRemoteHttpIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'system.http.service_token' => 'test-system-token',
            'system.http.base_url' => 'http://system-ai.test',
            // Flat bag — mirrors config/system.php (dotted keys must not nest).
            'system.capabilities' => [
                'article.content.generate' => 'legacy',
            ],
            'system.modules.ai' => 'legacy',
            'system.default_mode' => 'legacy',
        ]);
    }

    private function setArticleContentMode(string $mode): void
    {
        config([
            'system.capabilities' => [
                'article.content.generate' => $mode,
            ],
        ]);
    }

    public function test_remote_mode_fails_closed_when_transport_unbound(): void
    {
        $this->setArticleContentMode('remote');

        $registry = new SystemCapabilityRegistry();
        $client = new DefaultSystemAiClient(
            modes: new CapabilityModeResolver(),
            local: new LegacyLocalAiTransport($registry),
            remote: null,
            capabilities: $registry,
        );

        $result = $client->execute(new AiExecutionRequest(
            capability: 'article.content.generate',
            input: ['prompt_id' => 1],
        ));

        self::assertSame('failed', $result->status);
        self::assertSame('remote_transport_unavailable', $result->errorCode);
        self::assertNotSame('completed', $result->status);
    }

    public function test_legacy_mode_works_without_remote_url(): void
    {
        $this->setArticleContentMode('legacy');
        config(['system.http.base_url' => '']);

        $registry = new SystemCapabilityRegistry();
        $registry->register(new SystemCapabilityDefinition(
            key: 'article.content.generate',
            owner: 'ai-prompt',
            handler: new class implements SystemCapabilityHandler
            {
                public function handle(array $input, array $context = []): array
                {
                    return ['output' => 'legacy-ok', 'via_http_api' => false];
                }
            },
            sideEffectFree: true,
        ));

        $client = new DefaultSystemAiClient(
            modes: new CapabilityModeResolver(),
            local: new LegacyLocalAiTransport($registry),
            remote: null,
            capabilities: $registry,
        );

        $result = $client->execute(new AiExecutionRequest(
            capability: 'article.content.generate',
            input: [],
        ));

        self::assertSame('completed', $result->status);
        self::assertSame('legacy-ok', $result->output['output'] ?? null);
    }

    public function test_remote_http_transport_sends_bearer_and_marks_via_http_api(): void
    {
        Http::fake([
            'http://system-ai.test/api/system/v1/ai/executions' => Http::response([
                'ok' => true,
                'data' => [
                    'id' => 'ai_remote_proof',
                    'status' => 'completed',
                    'capability' => 'article.content.generate',
                    'output' => ['output' => 'from-http', 'via_http_api' => true],
                    'meta' => ['via_http_api' => true],
                    'trace' => ['transport' => 'http_local'],
                ],
            ], 200),
        ]);

        $transport = new RemoteHttpAiTransport(
            baseUrl: 'http://system-ai.test',
            serviceToken: 'test-system-token',
        );

        $result = $transport->execute(new AiExecutionRequest(
            capability: 'article.content.generate',
            input: ['prompt_id' => 1],
            correlation: ['article_id' => 8553, 'project_item_id' => 8799],
        ));

        Http::assertSent(static function ($request): bool {
            return $request->url() === 'http://system-ai.test/api/system/v1/ai/executions'
                && $request->hasHeader('Authorization', 'Bearer test-system-token')
                && $request['capability'] === 'article.content.generate';
        });

        self::assertSame('completed', $result->status);
        self::assertTrue((bool) ($result->meta['via_http_api'] ?? false));
        self::assertSame('remote_http', $result->meta['transport'] ?? null);
        self::assertSame('from-http', $result->output['output'] ?? null);
    }

    public function test_remote_http_422_business_failure_parses_execution_envelope(): void
    {
        Http::fake([
            'http://system-ai.test/api/system/v1/ai/executions' => Http::response([
                'ok' => true,
                'data' => [
                    'id' => 'ai_biz_fail',
                    'status' => 'failed',
                    'capability' => 'article.content.generate',
                    'output' => [],
                    'trace' => ['transport' => 'legacy_local'],
                    'meta' => [],
                    'error' => [
                        'code' => 'execution_failed',
                        'message' => 'No active model supports "text.generate" for profile "text.longform".',
                    ],
                ],
            ], 422),
        ]);

        $transport = new RemoteHttpAiTransport(
            baseUrl: 'http://system-ai.test',
            serviceToken: 'test-system-token',
        );

        $result = $transport->execute(new AiExecutionRequest(
            capability: 'article.content.generate',
            input: ['prompt_id' => 5],
        ));

        self::assertSame('failed', $result->status);
        self::assertSame('ai_biz_fail', $result->id);
        self::assertSame('execution_failed', $result->errorCode);
        self::assertStringContainsString('text.longform', (string) $result->errorMessage);
        self::assertSame('remote_http', $result->meta['transport'] ?? null);
        self::assertNotSame('remote_http_error', $result->errorCode);
    }

    public function test_remote_http_wrong_token_returns_auth_failure(): void
    {
        Http::fake([
            'http://system-ai.test/api/system/v1/ai/executions' => Http::response([
                'ok' => false,
                'error' => ['code' => 'unauthorized', 'message' => 'bad'],
            ], 401),
        ]);

        $transport = new RemoteHttpAiTransport(
            baseUrl: 'http://system-ai.test',
            serviceToken: 'wrong-token',
        );

        $result = $transport->execute(new AiExecutionRequest(
            capability: 'article.content.generate',
            input: [],
        ));

        self::assertSame('failed', $result->status);
        self::assertSame('remote_auth_failed', $result->errorCode);
    }

    public function test_remote_empty_base_url_fails_closed(): void
    {
        $transport = new RemoteHttpAiTransport(baseUrl: '', serviceToken: 'x');
        $result = $transport->execute(new AiExecutionRequest(
            capability: 'article.content.generate',
            input: [],
        ));

        self::assertSame('failed', $result->status);
        self::assertSame('remote_transport_unavailable', $result->errorCode);
    }

    public function test_system_api_endpoint_requires_service_token_and_sets_via_http_api(): void
    {
        $registry = app(SystemCapabilityRegistry::class);
        $registry->register(new SystemCapabilityDefinition(
            key: 'demo.http.ping',
            owner: 'test',
            handler: new class implements SystemCapabilityHandler
            {
                public function handle(array $input, array $context = []): array
                {
                    return [
                        'pong' => true,
                        'saw_via_http' => (bool) ($context['via_http_api'] ?? false),
                    ];
                }
            },
            sideEffectFree: true,
        ));

        $this->postJson('/api/system/v1/ai/executions', [
            'capability' => 'demo.http.ping',
            'input' => [],
        ])->assertStatus(401);

        $response = $this->withHeader('Authorization', 'Bearer test-system-token')
            ->postJson('/api/system/v1/ai/executions', [
                'capability' => 'demo.http.ping',
                'input' => ['x' => 1],
                'correlation' => ['id' => 'corr-http'],
            ]);

        $response->assertOk();
        $data = $response->json('data');
        self::assertIsArray($data);
        self::assertSame('completed', $data['status'] ?? null);
        self::assertTrue((bool) ($data['output']['saw_via_http'] ?? false));
        self::assertTrue((bool) ($data['output']['via_http_api'] ?? false));
        self::assertTrue((bool) ($data['meta']['via_http_api'] ?? false));

        $id = (string) ($data['id'] ?? '');
        self::assertNotSame('', $id);

        $show = $this->withHeader('Authorization', 'Bearer test-system-token')
            ->getJson('/api/system/v1/ai/executions/'.$id);
        $show->assertOk();
        self::assertSame($id, $show->json('data.id'));
    }

    public function test_via_http_api_context_never_selects_remote_transport(): void
    {
        config(['system.capabilities.demo.http.ping' => 'remote']);

        $registry = new SystemCapabilityRegistry();
        $registry->register(new SystemCapabilityDefinition(
            key: 'demo.http.ping',
            owner: 'test',
            handler: new class implements SystemCapabilityHandler
            {
                public function handle(array $input, array $context = []): array
                {
                    return ['ok' => true, 'via_http_api' => (bool) ($context['via_http_api'] ?? false)];
                }
            },
            sideEffectFree: true,
        ));

        Http::fake(); // any remote call would be stray if selected

        $client = new DefaultSystemAiClient(
            modes: new CapabilityModeResolver(),
            local: new LegacyLocalAiTransport($registry),
            remote: new RemoteHttpAiTransport('http://system-ai.test', 'test-system-token'),
            capabilities: $registry,
        );

        $result = $client->execute(new AiExecutionRequest(
            capability: 'demo.http.ping',
            input: [],
            context: ['via_http_api' => true],
        ));

        self::assertSame('completed', $result->status);
        self::assertTrue((bool) ($result->meta['via_http_api'] ?? false));
        Http::assertNothingSent();
    }

    public function test_shadow_keeps_legacy_authority_when_remote_unavailable(): void
    {
        config(['system.capabilities.demo.shadow' => 'shadow']);

        $registry = new SystemCapabilityRegistry();
        $registry->register(new SystemCapabilityDefinition(
            key: 'demo.shadow',
            owner: 'test',
            handler: new class implements SystemCapabilityHandler
            {
                public function handle(array $input, array $context = []): array
                {
                    return ['authority' => true];
                }
            },
            sideEffectFree: true,
        ));

        $client = new DefaultSystemAiClient(
            modes: new CapabilityModeResolver(),
            local: new LegacyLocalAiTransport($registry),
            remote: null,
            capabilities: $registry,
        );

        $result = $client->execute(new AiExecutionRequest(
            capability: 'demo.shadow',
            input: [],
        ));

        self::assertSame('completed', $result->status);
        self::assertTrue((bool) ($result->output['authority'] ?? false));
    }

    public function test_execution_get_uses_cache_not_only_process_memory(): void
    {
        $registry = new SystemCapabilityRegistry();
        $registry->register(new SystemCapabilityDefinition(
            key: 'demo.cache',
            owner: 'test',
            handler: new class implements SystemCapabilityHandler
            {
                public function handle(array $input, array $context = []): array
                {
                    return ['cached' => true];
                }
            },
            sideEffectFree: true,
        ));

        $transportA = new LegacyLocalAiTransport($registry);
        $result = $transportA->execute(new AiExecutionRequest(
            capability: 'demo.cache',
            input: [],
        ));

        $transportB = new LegacyLocalAiTransport($registry);
        $loaded = $transportB->getExecution($result->id);

        self::assertNotNull($loaded);
        self::assertSame($result->id, $loaded->id);
        self::assertTrue((bool) ($loaded->output['cached'] ?? false));
        self::assertTrue(Cache::has(LegacyLocalAiTransport::EXECUTION_CACHE_PREFIX.$result->id));
    }

    public function test_middleware_constant_time_compare_reject_empty_token_config(): void
    {
        config(['system.http.service_token' => '']);
        $middleware = new SystemApiTokenAuth();
        $request = Request::create('/api/system/v1/ai/executions', 'POST');
        $response = $middleware->handle($request, static fn () => response('ok'));
        self::assertSame(401, $response->getStatusCode());
    }
}
