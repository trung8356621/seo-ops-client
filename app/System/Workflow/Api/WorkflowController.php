<?php

declare(strict_types=1);

namespace App\System\Workflow\Api;

use App\System\Support\SystemApiEnvelope;
use App\System\Workflow\Contracts\SystemWorkflowClient;
use App\System\Workflow\Dto\WorkflowRunRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkflowController
{
    public function __construct(
        private readonly SystemWorkflowClient $workflows,
    ) {}

    public function validate(Request $request): JsonResponse
    {
        $definition = $request->input('definition');
        if (! is_array($definition)) {
            return SystemApiEnvelope::error('validation_error', 'definition object is required', 422);
        }

        return SystemApiEnvelope::ok($this->workflows->validate($definition));
    }

    public function storeRun(Request $request): JsonResponse
    {
        $runRequest = WorkflowRunRequest::fromArray($request->all());
        $result = $this->workflows->run($runRequest);
        $status = $result->status === 'failed' ? 422 : 202;

        return SystemApiEnvelope::ok($result->toArray(), status: $status);
    }

    public function showRun(string $id): JsonResponse
    {
        $result = $this->workflows->getRun($id);
        if ($result === null) {
            return SystemApiEnvelope::error('not_found', 'Workflow run not found', 404);
        }

        return SystemApiEnvelope::ok($result->toArray());
    }

    public function cancel(string $id): JsonResponse
    {
        return SystemApiEnvelope::ok($this->workflows->cancel($id)->toArray());
    }

    public function retry(string $id): JsonResponse
    {
        return SystemApiEnvelope::ok($this->workflows->retry($id)->toArray());
    }

    public function resume(string $id): JsonResponse
    {
        return SystemApiEnvelope::ok($this->workflows->resume($id)->toArray());
    }
}
