<?php

declare(strict_types=1);

namespace App\System\Ai\Transport;

use App\System\Ai\Dto\AiExecutionRequest;
use App\System\Ai\Dto\AiExecutionResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP transport to System AI API (same or remote process).
 */
final class RemoteHttpAiTransport implements AiTransport
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $serviceToken = '',
        private readonly int $timeoutSeconds = 120,
    ) {}

    public function execute(AiExecutionRequest $request): AiExecutionResult
    {
        $base = rtrim($this->baseUrl, '/');
        if ($base === '') {
            Log::warning('system.ai.remote.failure', [
                'capability' => $request->capability,
                'error_code' => 'remote_transport_unavailable',
                'reason' => 'empty_base_url',
            ]);

            return new AiExecutionResult(
                id: '',
                status: 'failed',
                capability: $request->capability,
                errorCode: 'remote_transport_unavailable',
                errorMessage: 'SYSTEM_API_BASE_URL is required for remote AI transport.',
                meta: ['transport' => 'remote_http'],
            );
        }

        $started = microtime(true);
        $endpoint = $base.'/api/system/v1/ai/executions';

        Log::info('system.ai.remote.request', [
            'capability' => $request->capability,
            'endpoint' => $endpoint,
            'transport' => 'remote_http',
            'correlation_id' => $request->correlation['correlation_id'] ?? $request->correlation['id'] ?? null,
            'article_id' => $request->correlation['article_id'] ?? null,
            'project_item_id' => $request->correlation['project_item_id'] ?? null,
            'run_id' => $request->correlation['run_id'] ?? null,
        ]);

        $pending = Http::timeout($this->timeoutSeconds)->acceptJson();
        $token = trim($this->serviceToken);
        if ($token !== '') {
            $pending = $pending->withToken($token);
        }

        $response = $pending->post($endpoint, $request->toArray());
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        if ($response->status() === 401) {
            Log::warning('system.ai.remote.failure', [
                'capability' => $request->capability,
                'error_code' => 'remote_auth_failed',
                'http_status' => 401,
                'duration_ms' => $durationMs,
            ]);

            return new AiExecutionResult(
                id: '',
                status: 'failed',
                capability: $request->capability,
                errorCode: 'remote_auth_failed',
                errorMessage: 'Remote AI execution unauthorized (service token rejected).',
                meta: ['transport' => 'remote_http', 'duration_ms' => $durationMs],
            );
        }

        $json = $response->json();
        $data = is_array($json['data'] ?? null) ? $json['data'] : null;

        // AiExecutionController returns HTTP 422 when status=failed. That is a business
        // execution result, not a transport outage — parse the envelope and surface it.
        if (is_array($data) && isset($data['status'])) {
            $result = AiExecutionResult::fromArray($data);
            $meta = array_merge($result->meta, [
                'transport' => 'remote_http',
                'via_http_api' => true,
                'duration_ms' => $durationMs,
                'endpoint' => $endpoint,
                'http_status' => $response->status(),
            ]);

            if (! $response->successful() && $result->status === 'failed') {
                Log::warning('system.ai.remote.failure', [
                    'capability' => $request->capability,
                    'error_code' => $result->errorCode ?? 'execution_failed',
                    'http_status' => $response->status(),
                    'duration_ms' => $durationMs,
                    'message' => $result->errorMessage,
                ]);
            }

            return new AiExecutionResult(
                id: $result->id,
                status: $result->status,
                capability: $result->capability !== '' ? $result->capability : $request->capability,
                output: $result->output,
                trace: array_merge($result->trace, ['transport' => 'remote_http']),
                meta: $meta,
                errorCode: $result->errorCode,
                errorMessage: $result->errorMessage,
            );
        }

        if (! $response->successful()) {
            $envelopeError = is_array($json['error'] ?? null) ? $json['error'] : [];
            $message = trim((string) ($envelopeError['message'] ?? ''));
            if ($message === '') {
                $message = 'Remote AI execution failed with HTTP '.$response->status();
            }

            Log::warning('system.ai.remote.failure', [
                'capability' => $request->capability,
                'error_code' => 'remote_http_error',
                'http_status' => $response->status(),
                'duration_ms' => $durationMs,
                'message' => $message,
            ]);

            return new AiExecutionResult(
                id: '',
                status: 'failed',
                capability: $request->capability,
                errorCode: isset($envelopeError['code']) ? (string) $envelopeError['code'] : 'remote_http_error',
                errorMessage: $message,
                meta: ['body' => $json, 'transport' => 'remote_http', 'duration_ms' => $durationMs],
            );
        }

        $fallback = is_array($json) ? $json : [];
        $result = AiExecutionResult::fromArray($fallback);
        $meta = array_merge($result->meta, [
            'transport' => 'remote_http',
            'via_http_api' => true,
            'duration_ms' => $durationMs,
            'endpoint' => $endpoint,
        ]);

        return new AiExecutionResult(
            id: $result->id,
            status: $result->status,
            capability: $result->capability !== '' ? $result->capability : $request->capability,
            output: $result->output,
            trace: array_merge($result->trace, ['transport' => 'remote_http']),
            meta: $meta,
            errorCode: $result->errorCode,
            errorMessage: $result->errorMessage,
        );
    }

    public function getExecution(string $id): ?AiExecutionResult
    {
        $base = rtrim($this->baseUrl, '/');
        if ($base === '') {
            return null;
        }

        $pending = Http::timeout($this->timeoutSeconds)->acceptJson();
        $token = trim($this->serviceToken);
        if ($token !== '') {
            $pending = $pending->withToken($token);
        }

        $response = $pending->get($base.'/api/system/v1/ai/executions/'.$id);

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();
        $data = is_array($json['data'] ?? null) ? $json['data'] : null;
        if (! is_array($data)) {
            return null;
        }

        return AiExecutionResult::fromArray($data);
    }
}
