<?php

declare(strict_types=1);

namespace App\System\Workflow\Client;

use App\System\Support\CapabilityModeResolver;
use App\System\Support\SystemExecutionMode;
use App\System\Workflow\Contracts\SystemWorkflowClient;
use App\System\Workflow\Dto\WorkflowRunRequest;
use App\System\Workflow\Dto\WorkflowRunResult;
use App\System\Workflow\Transport\LegacyLocalWorkflowTransport;
use App\System\Workflow\Transport\RemoteHttpWorkflowTransport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class DefaultSystemWorkflowClient implements SystemWorkflowClient
{
    public function __construct(
        private readonly CapabilityModeResolver $modes,
        private readonly LegacyLocalWorkflowTransport $local,
        private readonly ?RemoteHttpWorkflowTransport $remote = null,
    ) {}

    public function validate(array $definition): array
    {
        return $this->local->validate($definition);
    }

    public function run(WorkflowRunRequest $request): WorkflowRunResult
    {
        $request = $this->withResolvedOwnerIdentity($request);

        // HTTP controller entry must stay in-process to avoid RemoteHttp recursion.
        if ((bool) ($request->context['via_http_api'] ?? false)) {
            return $this->local->run($request);
        }

        $mode = $this->modes->resolve(
            isset($request->correlation['capability']) ? (string) $request->correlation['capability'] : null,
            'workflow',
        );

        return match ($mode) {
            SystemExecutionMode::Legacy => $this->local->run($request),
            SystemExecutionMode::Remote => $this->remoteTransportOrFail($request),
            SystemExecutionMode::Shadow => $this->shadowWithoutDoubleExecution($request),
        };
    }

    public function getRun(string $id, array $context = []): ?WorkflowRunResult
    {
        if ((bool) ($context['via_http_api'] ?? false)) {
            return $this->local->getRun($id, $context);
        }

        $mode = $this->modes->resolve(
            isset($context['capability']) ? (string) $context['capability'] : null,
            'workflow',
        );

        if ($mode === SystemExecutionMode::Remote) {
            if (! $this->remote instanceof RemoteHttpWorkflowTransport) {
                return null;
            }

            return $this->remote->getRun($id, $context);
        }

        return $this->local->getRun($id, $context);
    }

    public function cancel(string $id): WorkflowRunResult
    {
        return $this->local->cancel($id);
    }

    public function retry(string $id): WorkflowRunResult
    {
        return $this->local->retry($id);
    }

    public function resume(string $id): WorkflowRunResult
    {
        return $this->local->resume($id);
    }

    private function remoteTransportOrFail(WorkflowRunRequest $request): WorkflowRunResult
    {
        if ($this->remote instanceof RemoteHttpWorkflowTransport) {
            return $this->remote->run($request);
        }

        Log::warning('system.workflow.remote.failure', [
            'error_code' => 'remote_transport_unavailable',
            'definition_id' => $request->definitionId,
            'execution_mode' => $request->executionMode,
            'correlation_id' => $request->correlation['correlation_id'] ?? $request->correlation['id'] ?? null,
        ]);

        return new WorkflowRunResult(
            id: 'wf_'.Str::lower(Str::random(12)),
            status: 'failed',
            errorCode: 'remote_transport_unavailable',
            errorMessage: 'Remote Workflow transport is unavailable (SYSTEM_API_BASE_URL / remote bind missing).',
            meta: ['mode' => 'remote', 'transport' => 'remote_unavailable'],
        );
    }

    /**
     * Shadow must NOT double-execute side-effectful workflow graphs.
     * Local remains authoritative; shadow execution is explicitly disabled.
     */
    private function shadowWithoutDoubleExecution(WorkflowRunRequest $request): WorkflowRunResult
    {
        $primary = $this->local->run($request);

        Log::info('system.workflow.shadow.disabled', [
            'reason' => 'side_effectful_graph_no_dual_execution',
            'authority_run_id' => $primary->id,
            'definition_id' => $request->definitionId,
            'execution_mode' => $request->executionMode,
        ]);

        return new WorkflowRunResult(
            id: $primary->id,
            status: $primary->status,
            steps: $primary->steps,
            artifacts: $primary->artifacts,
            meta: array_merge($primary->meta, [
                'shadow_execution' => 'disabled',
                'shadow_reason' => 'side_effectful_graph_no_dual_execution',
            ]),
            errorCode: $primary->errorCode,
            errorMessage: $primary->errorMessage,
        );
    }

    /**
     * Ensure generic owner_user_id is present for remote hops / API ownership gates.
     * Prefer explicit correlation/context; else Auth::id() only when mode=remote.
     * Local legacy callers are not forced through ownership injection (BC).
     */
    private function withResolvedOwnerIdentity(WorkflowRunRequest $request): WorkflowRunRequest
    {
        $existing = (int) ($request->correlation['owner_user_id'] ?? $request->context['owner_user_id'] ?? 0);
        if ($existing > 0) {
            return $request;
        }

        // API entry already has via_http_api — owner must arrive from authenticated remote caller.
        if ((bool) ($request->context['via_http_api'] ?? false)) {
            return $request;
        }

        $mode = $this->modes->resolve(
            isset($request->correlation['capability']) ? (string) $request->correlation['capability'] : null,
            'workflow',
        );
        if ($mode !== SystemExecutionMode::Remote) {
            return $request;
        }

        $authId = Auth::id();
        if ($authId === null || (int) $authId <= 0) {
            return $request;
        }

        $ownerUserId = (int) $authId;

        return new WorkflowRunRequest(
            definitionId: $request->definitionId,
            definition: $request->definition,
            input: $request->input,
            context: array_merge($request->context, ['owner_user_id' => $ownerUserId]),
            correlation: array_merge($request->correlation, ['owner_user_id' => $ownerUserId]),
            idempotencyKey: $request->idempotencyKey,
            executionMode: $request->executionMode,
            startNodeId: $request->startNodeId,
            targetNodeId: $request->targetNodeId,
            executionScope: $request->executionScope,
        );
    }
}
