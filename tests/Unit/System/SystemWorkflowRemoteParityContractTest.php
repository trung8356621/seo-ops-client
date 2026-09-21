<?php

declare(strict_types=1);

namespace Tests\Unit\System;

use App\System\Http\Middleware\SystemApiTokenAuth;
use App\System\Support\CapabilityModeResolver;
use App\System\Workflow\Api\WorkflowController;
use App\System\Workflow\Client\DefaultSystemWorkflowClient;
use App\System\Workflow\Dto\WorkflowRunRequest;
use App\System\Workflow\Dto\WorkflowRunResult;
use App\System\Workflow\Nodes\WorkflowNodeRegistry;
use App\System\Workflow\Transport\LegacyLocalWorkflowTransport;
use App\System\Workflow\Transport\RemoteHttpWorkflowTransport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * STEP 4 — System Workflow remote HTTP / Bearer / anti-recursion / Cache parity.
 */
final class SystemWorkflowRemoteParityContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'system.http.service_token' => 'test-workflow-token',
            'system.http.base_url' => 'http://system-workflow.test',
            'system.modules.workflow' => 'legacy',
            'system.default_mode' => 'legacy',
            'system.capabilities' => [],
        ]);
        Cache::flush();
    }

    public function test_routes_use_bearer_not_web_auth_for_workflow(): void
    {
        $src = (string) file_get_contents(base_path('routes/system.php'));
        self::assertStringContainsString('SystemApiTokenAuth::class', $src);
        // Workflow routes must sit in the Bearer group with AI.
        $aiPos = strpos($src, "Route::post('/ai/executions'");
        $wfPos = strpos($src, "Route::post('/workflow-runs'");
        $webPos = strpos($src, "middleware(['web', 'auth'])");
        self::assertNotFalse($aiPos);
        self::assertNotFalse($wfPos);
        self::assertNotFalse($webPos);
        self::assertLessThan($webPos, $wfPos, 'workflow-runs must be before web+auth group');
        self::assertStringNotContainsString("middleware(['web', 'auth'])->group(function (): void {\n    Route::post('/workflows/validate'", $src);
    }

    public function test_bearer_middleware_rejects_missing_and_wrong_token(): void
    {
        $middleware = new SystemApiTokenAuth();
        $deny = $middleware->handle(
            Request::create('/api/system/v1/workflow-runs', 'POST'),
            static fn () => response('ok'),
        );
        self::assertSame(401, $deny->getStatusCode());

        $wrong = Request::create('/api/system/v1/workflow-runs', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer wrong-token',
        ]);
        $deny2 = $middleware->handle($wrong, static fn () => response('ok'));
        self::assertSame(401, $deny2->getStatusCode());

        $okReq = Request::create('/api/system/v1/workflow-runs', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer test-workflow-token',
        ]);
        $ok = $middleware->handle($okReq, static fn () => response('ok', 200));
        self::assertSame(200, $ok->getStatusCode());
    }

    public function test_remote_mode_fails_closed_when_transport_unbound(): void
    {
        config(['system.modules.workflow' => 'remote']);

        $client = new DefaultSystemWorkflowClient(
            modes: new CapabilityModeResolver(),
            local: new LegacyLocalWorkflowTransport(new WorkflowNodeRegistry()),
            remote: null,
        );

        $result = $client->run(new WorkflowRunRequest(
            definitionId: 1,
            context: ['task_test_context' => ['variables' => ['x' => '1'], 'summary' => 't']],
            correlation: ['owner_user_id' => 1],
        ));

        self::assertSame('failed', $result->status);
        self::assertSame('remote_transport_unavailable', $result->errorCode);
    }

    public function test_via_http_api_forces_local_even_in_remote_mode(): void
    {
        config(['system.modules.workflow' => 'remote']);

        $localCalls = 0;
        $runtime = new class($localCalls) implements \App\System\Workflow\Contracts\WorkflowRuntimePort
        {
            public function __construct(private int &$calls) {}

            public function validate(array $definition): array
            {
                return ['valid' => true, 'errors' => []];
            }

            public function loadDefinition(int $definitionId): ?array
            {
                return ['nodes' => [['id' => 'n1', 'type' => 'end']], 'edges' => []];
            }

            public function run(WorkflowRunRequest $request): WorkflowRunResult
            {
                $this->calls++;
                \PHPUnit\Framework\Assert::assertTrue((bool) ($request->context['via_http_api'] ?? false));

                return new WorkflowRunResult(
                    id: 'wf_forced_local',
                    status: 'completed',
                    meta: ['via_http_api' => true, 'owner_user_id' => 7],
                );
            }

            public function cancel(string $runId): WorkflowRunResult
            {
                return new WorkflowRunResult(id: $runId, status: 'failed', errorCode: 'not_supported');
            }

            public function retry(string $runId): WorkflowRunResult
            {
                return new WorkflowRunResult(id: $runId, status: 'failed', errorCode: 'not_supported');
            }

            public function resume(string $runId): WorkflowRunResult
            {
                return new WorkflowRunResult(id: $runId, status: 'failed', errorCode: 'not_supported');
            }
        };

        $local = new LegacyLocalWorkflowTransport(new WorkflowNodeRegistry(), $runtime);
        $remote = new RemoteHttpWorkflowTransport('http://should-not-be-called.test', 'tok');
        Http::fake(fn () => Http::response(['ok' => false], 500));

        $client = new DefaultSystemWorkflowClient(
            modes: new CapabilityModeResolver(),
            local: $local,
            remote: $remote,
        );

        $result = $client->run(new WorkflowRunRequest(
            definitionId: 1,
            context: [
                'via_http_api' => true,
                'task_test_context' => ['variables' => [], 'summary' => ''],
                'owner_user_id' => 7,
            ],
            correlation: ['owner_user_id' => 7],
        ));

        self::assertSame('wf_forced_local', $result->id);
        self::assertSame(1, $localCalls);
        Http::assertNothingSent();
    }

    public function test_controller_overrides_spoofed_via_http_api_and_forces_true(): void
    {
        $saw = null;
        $client = new class($saw) implements \App\System\Workflow\Contracts\SystemWorkflowClient
        {
            public function __construct(private mixed &$saw) {}

            public function validate(array $definition): array
            {
                return ['valid' => true, 'errors' => []];
            }

            public function run(WorkflowRunRequest $request): WorkflowRunResult
            {
                $this->saw = $request->context['via_http_api'] ?? null;

                return new WorkflowRunResult(id: 'wf_ctrl', status: 'completed', meta: ['owner_user_id' => 1]);
            }

            public function getRun(string $id, array $context = []): ?WorkflowRunResult
            {
                return null;
            }

            public function cancel(string $id): WorkflowRunResult
            {
                return new WorkflowRunResult(id: $id, status: 'failed', errorCode: 'not_supported');
            }

            public function retry(string $id): WorkflowRunResult
            {
                return new WorkflowRunResult(id: $id, status: 'failed', errorCode: 'not_supported');
            }

            public function resume(string $id): WorkflowRunResult
            {
                return new WorkflowRunResult(id: $id, status: 'failed', errorCode: 'not_supported');
            }
        };

        $controller = new WorkflowController($client);
        $controller->storeRun(Request::create('/api/system/v1/workflow-runs', 'POST', [
            'definition_id' => 1,
            'context' => ['via_http_api' => false],
            'correlation' => ['owner_user_id' => 1],
        ]));

        self::assertTrue($saw === true);
    }

    public function test_remote_transport_posts_bearer_and_parses_envelope(): void
    {
        Http::fake([
            'http://system-workflow.test/api/system/v1/workflow-runs' => Http::response([
                'ok' => true,
                'data' => [
                    'id' => 'wf_remote_1',
                    'status' => 'completed',
                    'steps' => ['n1' => ['status' => 'completed']],
                    'artifacts' => [],
                    'meta' => ['owner_user_id' => 3, 'execution_mode' => 'full_run'],
                    'error' => null,
                ],
            ], 200),
        ]);

        $transport = new RemoteHttpWorkflowTransport(
            baseUrl: 'http://system-workflow.test',
            serviceToken: 'test-workflow-token',
            timeoutSeconds: 30,
        );

        $result = $transport->run(new WorkflowRunRequest(
            definitionId: 9,
            context: ['task_test_context' => ['variables' => ['a' => '1'], 'summary' => 's']],
            correlation: ['owner_user_id' => 3, 'capability' => 'workflow.test'],
            executionMode: 'full_run',
            executionScope: 'outline_vocabulary',
        ));

        self::assertSame('wf_remote_1', $result->id);
        self::assertSame('completed', $result->status);
        self::assertTrue((bool) ($result->meta['via_http_api'] ?? false));

        Http::assertSent(function ($request): bool {
            return $request->hasHeader('Authorization', 'Bearer test-workflow-token')
                && $request->url() === 'http://system-workflow.test/api/system/v1/workflow-runs'
                && ($request['execution_scope'] ?? null) === 'outline_vocabulary'
                && ($request['correlation']['owner_user_id'] ?? null) === 3;
        });
    }

    public function test_run_cache_survives_new_transport_instance(): void
    {
        $t1 = new LegacyLocalWorkflowTransport(new WorkflowNodeRegistry());
        $result = new WorkflowRunResult(
            id: 'wf_cache_cross',
            status: 'completed',
            steps: ['a' => ['status' => 'ok']],
            meta: ['owner_user_id' => 11, 'definition_id' => 5],
        );

        $ref = new \ReflectionClass($t1);
        $remember = $ref->getMethod('remember');
        $remember->setAccessible(true);
        $remember->invoke($t1, $result);

        $t2 = new LegacyLocalWorkflowTransport(new WorkflowNodeRegistry());
        $loaded = $t2->getRun('wf_cache_cross', ['owner_user_id' => 11]);
        self::assertNotNull($loaded);
        self::assertSame('wf_cache_cross', $loaded->id);
        self::assertSame('completed', $loaded->status);

        $blocked = $t2->getRun('wf_cache_cross', ['owner_user_id' => 99]);
        self::assertNull($blocked);
    }

    public function test_show_run_requires_owner_and_enforces_scope(): void
    {
        $stored = new WorkflowRunResult(
            id: 'wf_get_1',
            status: 'completed',
            meta: ['owner_user_id' => 4],
        );
        Cache::put(LegacyLocalWorkflowTransport::RUN_CACHE_PREFIX.'wf_get_1', $stored->toArray(), 60);

        $client = new DefaultSystemWorkflowClient(
            modes: new CapabilityModeResolver(),
            local: new LegacyLocalWorkflowTransport(new WorkflowNodeRegistry()),
            remote: null,
        );
        $controller = new WorkflowController($client);

        $missing = $controller->showRun(Request::create('/api/system/v1/workflow-runs/wf_get_1', 'GET'), 'wf_get_1');
        self::assertSame(422, $missing->getStatusCode());

        $wrong = $controller->showRun(
            Request::create('/api/system/v1/workflow-runs/wf_get_1?owner_user_id=99', 'GET'),
            'wf_get_1',
        );
        self::assertSame(404, $wrong->getStatusCode());

        $ok = $controller->showRun(
            Request::create('/api/system/v1/workflow-runs/wf_get_1?owner_user_id=4', 'GET'),
            'wf_get_1',
        );
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame('wf_get_1', $ok->getData(true)['data']['id']);
    }

    public function test_shadow_mode_does_not_call_remote(): void
    {
        config(['system.modules.workflow' => 'shadow']);
        Http::fake();

        $runtime = new class implements \App\System\Workflow\Contracts\WorkflowRuntimePort
        {
            public function validate(array $definition): array
            {
                return ['valid' => true, 'errors' => []];
            }

            public function loadDefinition(int $definitionId): ?array
            {
                return ['nodes' => [['id' => 'n1', 'type' => 'end']], 'edges' => []];
            }

            public function run(WorkflowRunRequest $request): WorkflowRunResult
            {
                return new WorkflowRunResult(id: 'wf_shadow_auth', status: 'completed', meta: ['local' => true]);
            }

            public function cancel(string $runId): WorkflowRunResult
            {
                return new WorkflowRunResult(id: $runId, status: 'failed', errorCode: 'not_supported');
            }

            public function retry(string $runId): WorkflowRunResult
            {
                return new WorkflowRunResult(id: $runId, status: 'failed', errorCode: 'not_supported');
            }

            public function resume(string $runId): WorkflowRunResult
            {
                return new WorkflowRunResult(id: $runId, status: 'failed', errorCode: 'not_supported');
            }
        };

        $client = new DefaultSystemWorkflowClient(
            modes: new CapabilityModeResolver(),
            local: new LegacyLocalWorkflowTransport(new WorkflowNodeRegistry(), $runtime),
            remote: new RemoteHttpWorkflowTransport('http://system-workflow.test', 'tok'),
        );

        $result = $client->run(new WorkflowRunRequest(
            definitionId: 1,
            context: ['task_test_context' => ['variables' => [], 'summary' => '']],
        ));

        self::assertSame('wf_shadow_auth', $result->id);
        self::assertSame('disabled', $result->meta['shadow_execution'] ?? null);
        Http::assertNothingSent();
    }

    public function test_remote_http_transport_class_exists_and_provider_binds(): void
    {
        self::assertTrue(class_exists(RemoteHttpWorkflowTransport::class));
        $src = (string) file_get_contents((new \ReflectionClass(\App\System\SystemServiceProvider::class))->getFileName());
        self::assertStringContainsString('RemoteHttpWorkflowTransport::class', $src);
        self::assertStringContainsString('DefaultSystemWorkflowClient', $src);
    }
}
