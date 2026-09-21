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
        $payload = $request->all();
        $runRequest = WorkflowRunRequest::fromArray($payload);

        // Strip/override any caller-supplied via_http_api; force LOCAL after service auth.
        $context = $runRequest->context;
        unset($context['via_http_api']);
        $context['via_http_api'] = true;

        $forced = new WorkflowRunRequest(
            definitionId: $runRequest->definitionId,
            definition: $runRequest->definition,
            input: $runRequest->input,
            context: $context,
            correlation: $runRequest->correlation,
            idempotencyKey: $runRequest->idempotencyKey,
            executionMode: $runRequest->executionMode,
            startNodeId: $runRequest->startNodeId,
            targetNodeId: $runRequest->targetNodeId,
            executionScope: $runRequest->executionScope,
        );

        $result = $this->workflows->run($forced);

        // Align with System AI: sync completed → 200; sync failed → 422.
        // Reserve 202 only for genuinely accepted/deferred/async statuses.
        $status = match ($result->status) {
            'failed' => 422,
            'accepted', 'deferred', 'queued', 'running' => 202,
            default => 200,
        };

        return SystemApiEnvelope::ok($result->toArray(), [
            'correlation' => $runRequest->correlation,
        ], $status);
    }

    public function showRun(Request $request, string $id): JsonResponse
    {
        $ownerUserId = (int) $request->query('owner_user_id', 0);
        if ($ownerUserId <= 0) {
            return SystemApiEnvelope::error(
                'owner_required',
                'owner_user_id query parameter is required for Workflow run GET.',
                422,
            );
        }

        $result = $this->workflows->getRun($id, [
            'owner_user_id' => $ownerUserId,
            'via_http_api' => true,
        ]);
        if ($result === null) {
            return SystemApiEnvelope::error('not_found', 'Workflow run not found', 404);
        }

        return SystemApiEnvelope::ok($result->toArray());
    }

    public function cancel(string $id): JsonResponse
    {
        // Sync local runs complete before return — cancel is not applicable (not_supported).
        return SystemApiEnvelope::ok($this->workflows->cancel($id)->toArray());
    }

    public function retry(string $id): JsonResponse
    {
        // Sync retry = caller submits a new POST run; this endpoint stays not_supported.
        return SystemApiEnvelope::ok($this->workflows->retry($id)->toArray());
    }

    public function resume(string $id): JsonResponse
    {
        // No checkpoint/async resume for sync Workflow — not_supported.
        return SystemApiEnvelope::ok($this->workflows->resume($id)->toArray());
    }
}
