<?php

declare(strict_types=1);

namespace App\System\Workflow\Transport;

use App\System\Workflow\Dto\WorkflowRunRequest;
use App\System\Workflow\Dto\WorkflowRunResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP transport to System Workflow API (same or remote process).
 * Mirrors RemoteHttpAiTransport — shared SYSTEM_API_* config.
 */
final class RemoteHttpWorkflowTransport
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $serviceToken = '',
        private readonly int $timeoutSeconds = 120,
    ) {}

    public function run(WorkflowRunRequest $request): WorkflowRunResult
    {
        $base = rtrim($this->baseUrl, '/');
        if ($base === '') {
            Log::warning('system.workflow.remote.failure', [
                'error_code' => 'remote_transport_unavailable',
                'reason' => 'empty_base_url',
                'definition_id' => $request->definitionId,
                'execution_mode' => $request->executionMode,
            ]);

            return new WorkflowRunResult(
                id: '',
                status: 'failed',
                errorCode: 'remote_transport_unavailable',
                errorMessage: 'SYSTEM_API_BASE_URL is required for remote Workflow transport.',
                meta: ['transport' => 'remote_http'],
            );
        }

        $started = microtime(true);
        $endpoint = $base.'/api/system/v1/workflow-runs';
        $ownerUserId = $this->ownerUserIdFromRequest($request);

        Log::info('system.workflow.remote.request', [
            'endpoint' => $endpoint,
            'transport' => 'remote_http',
            'definition_id' => $request->definitionId,
            'execution_mode' => $request->executionMode,
            'execution_scope' => $request->executionScope,
            'owner_user_id' => $ownerUserId,
            'correlation_id' => $request->correlation['correlation_id'] ?? $request->correlation['id'] ?? null,
            'source' => $request->context['source'] ?? null,
        ]);

        $pending = Http::timeout($this->timeoutSeconds)->acceptJson();
        $token = trim($this->serviceToken);
        if ($token !== '') {
            $pending = $pending->withToken($token);
        }

        $response = $pending->post($endpoint, $request->toArray());
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        if ($response->status() === 401) {
            Log::warning('system.workflow.remote.failure', [
                'error_code' => 'remote_auth_failed',
                'http_status' => 401,
                'duration_ms' => $durationMs,
                'definition_id' => $request->definitionId,
            ]);

            return new WorkflowRunResult(
                id: '',
                status: 'failed',
                errorCode: 'remote_auth_failed',
                errorMessage: 'Remote Workflow execution unauthorized (service token rejected).',
                meta: ['transport' => 'remote_http', 'duration_ms' => $durationMs],
            );
        }

        $json = $response->json();
        $data = is_array($json['data'] ?? null) ? $json['data'] : null;

        if (is_array($data) && isset($data['status'])) {
            $result = WorkflowRunResult::fromArray($data);
            $meta = array_merge($result->meta, [
                'transport' => 'remote_http',
                'via_http_api' => true,
                'duration_ms' => $durationMs,
                'endpoint' => $endpoint,
                'http_status' => $response->status(),
            ]);

            if (! $response->successful() && $result->status === 'failed') {
                Log::warning('system.workflow.remote.failure', [
                    'error_code' => $result->errorCode ?? 'execution_failed',
                    'http_status' => $response->status(),
                    'duration_ms' => $durationMs,
                    'definition_id' => $request->definitionId,
                    'message' => $result->errorMessage,
                ]);
            }

            return new WorkflowRunResult(
                id: $result->id,
                status: $result->status,
                steps: $result->steps,
                artifacts: $result->artifacts,
                meta: $meta,
                errorCode: $result->errorCode,
                errorMessage: $result->errorMessage,
            );
        }

        if (! $response->successful()) {
            $envelopeError = is_array($json['error'] ?? null) ? $json['error'] : [];
            $message = trim((string) ($envelopeError['message'] ?? ''));
            if ($message === '') {
                $message = 'Remote Workflow execution failed with HTTP '.$response->status();
            }

            Log::warning('system.workflow.remote.failure', [
                'error_code' => 'remote_http_error',
                'http_status' => $response->status(),
                'duration_ms' => $durationMs,
                'definition_id' => $request->definitionId,
                'message' => $message,
            ]);

            return new WorkflowRunResult(
                id: '',
                status: 'failed',
                errorCode: isset($envelopeError['code']) ? (string) $envelopeError['code'] : 'remote_http_error',
                errorMessage: $message,
                meta: ['transport' => 'remote_http', 'duration_ms' => $durationMs, 'http_status' => $response->status()],
            );
        }

        $fallback = is_array($json) ? $json : [];
        $result = WorkflowRunResult::fromArray($fallback);

        return new WorkflowRunResult(
            id: $result->id,
            status: $result->status,
            steps: $result->steps,
            artifacts: $result->artifacts,
            meta: array_merge($result->meta, [
                'transport' => 'remote_http',
                'via_http_api' => true,
                'duration_ms' => $durationMs,
                'endpoint' => $endpoint,
            ]),
            errorCode: $result->errorCode,
            errorMessage: $result->errorMessage,
        );
    }

    /**
     * @param  array{owner_user_id?: int|null}  $context
     */
    public function getRun(string $id, array $context = []): ?WorkflowRunResult
    {
        $base = rtrim($this->baseUrl, '/');
        if ($base === '') {
            return null;
        }

        $ownerUserId = isset($context['owner_user_id']) ? (int) $context['owner_user_id'] : 0;
        $query = [];
        if ($ownerUserId > 0) {
            $query['owner_user_id'] = $ownerUserId;
        }

        $pending = Http::timeout($this->timeoutSeconds)->acceptJson();
        $token = trim($this->serviceToken);
        if ($token !== '') {
            $pending = $pending->withToken($token);
        }

        $url = $base.'/api/system/v1/workflow-runs/'.$id;
        $response = $query === []
            ? $pending->get($url)
            : $pending->get($url, $query);

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();
        $data = is_array($json['data'] ?? null) ? $json['data'] : null;
        if (! is_array($data)) {
            return null;
        }

        return WorkflowRunResult::fromArray($data);
    }

    private function ownerUserIdFromRequest(WorkflowRunRequest $request): ?int
    {
        $fromCorrelation = (int) ($request->correlation['owner_user_id'] ?? 0);
        if ($fromCorrelation > 0) {
            return $fromCorrelation;
        }
        $fromContext = (int) ($request->context['owner_user_id'] ?? 0);

        return $fromContext > 0 ? $fromContext : null;
    }
}
