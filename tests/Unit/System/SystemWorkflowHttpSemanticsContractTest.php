<?php

declare(strict_types=1);

namespace Tests\Unit\System;

use App\System\Workflow\Api\WorkflowController;
use App\System\Workflow\Contracts\SystemWorkflowClient;
use App\System\Workflow\Dto\WorkflowExecutionMode;
use App\System\Workflow\Dto\WorkflowRunRequest;
use App\System\Workflow\Dto\WorkflowRunResult;
use Illuminate\Http\Request;
use Tests\TestCase;

final class SystemWorkflowHttpSemanticsContractTest extends TestCase
{
    public function test_workflow_run_request_serializes_execution_mode_fields(): void
    {
        $req = WorkflowRunRequest::fromArray([
            'definition_id' => 42,
            'execution_mode' => WorkflowExecutionMode::FromNode->value,
            'start_node_id' => 'n_mid',
            'target_node_id' => 'n_one',
        ]);

        $arr = $req->toArray();
        self::assertSame(42, $arr['definition_id']);
        self::assertSame('from_node', $arr['execution_mode']);
        self::assertSame('n_mid', $arr['start_node_id']);
        self::assertSame('n_one', $arr['target_node_id']);
        self::assertSame('full_run', WorkflowRunRequest::fromArray([])->executionMode);
    }

    public function test_store_run_returns_200_for_synchronous_completed(): void
    {
        $client = new class implements SystemWorkflowClient
        {
            public function validate(array $definition): array
            {
                return ['valid' => true, 'errors' => []];
            }

            public function run(WorkflowRunRequest $request): WorkflowRunResult
            {
                return new WorkflowRunResult(
                    id: 'wf_sync_ok',
                    status: 'completed',
                    steps: ['n1' => ['node_id' => 'n1', 'status' => 'ok']],
                    meta: ['execution_mode' => $request->executionMode],
                );
            }

            public function getRun(string $id): ?WorkflowRunResult
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
        $response = $controller->storeRun(Request::create('/api/system/v1/workflow-runs', 'POST', [
            'definition_id' => 1,
            'execution_mode' => 'full_run',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $json = $response->getData(true);
        self::assertTrue($json['ok']);
        self::assertSame('completed', $json['data']['status']);
        self::assertNotEmpty($json['data']['steps']);
    }

    public function test_store_run_returns_422_for_synchronous_failed(): void
    {
        $client = new class implements SystemWorkflowClient
        {
            public function validate(array $definition): array
            {
                return ['valid' => true, 'errors' => []];
            }

            public function run(WorkflowRunRequest $request): WorkflowRunResult
            {
                return new WorkflowRunResult(
                    id: 'wf_sync_fail',
                    status: 'failed',
                    errorCode: 'invalid_execution_mode',
                    errorMessage: 'bad mode',
                );
            }

            public function getRun(string $id): ?WorkflowRunResult
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
        $response = $controller->storeRun(Request::create('/api/system/v1/workflow-runs', 'POST', [
            'execution_mode' => 'nope',
        ]));

        self::assertSame(422, $response->getStatusCode());
        $json = $response->getData(true);
        self::assertSame('failed', $json['data']['status']);
    }

    public function test_store_run_reserves_202_for_deferred_async_statuses(): void
    {
        $client = new class implements SystemWorkflowClient
        {
            public function validate(array $definition): array
            {
                return ['valid' => true, 'errors' => []];
            }

            public function run(WorkflowRunRequest $request): WorkflowRunResult
            {
                return new WorkflowRunResult(
                    id: 'wf_deferred',
                    status: 'accepted',
                    steps: [],
                    meta: ['execution' => 'deferred'],
                );
            }

            public function getRun(string $id): ?WorkflowRunResult
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
        $response = $controller->storeRun(Request::create('/api/system/v1/workflow-runs', 'POST', []));

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('accepted', $response->getData(true)['data']['status']);
    }
}
