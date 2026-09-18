<?php

declare(strict_types=1);

namespace App\System\Ai\Transport;

use App\System\Ai\Dto\AiExecutionRequest;
use App\System\Ai\Dto\AiExecutionResult;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * HTTP transport to System AI API (same or remote process).
 */
final class RemoteHttpAiTransport implements AiTransport
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds = 120,
    ) {}

    public function execute(AiExecutionRequest $request): AiExecutionResult
    {
        $base = rtrim($this->baseUrl, '/');
        if ($base === '') {
            throw new RuntimeException('SYSTEM_API_BASE_URL is required for remote AI transport.');
        }

        $response = Http::timeout($this->timeoutSeconds)
            ->acceptJson()
            ->post($base.'/api/system/v1/ai/executions', $request->toArray());

        if (! $response->successful()) {
            return new AiExecutionResult(
                id: '',
                status: 'failed',
                capability: $request->capability,
                errorCode: 'remote_http_error',
                errorMessage: 'Remote AI execution failed with HTTP '.$response->status(),
                meta: ['body' => $response->json()],
            );
        }

        $json = $response->json();
        $data = is_array($json['data'] ?? null) ? $json['data'] : (is_array($json) ? $json : []);

        return AiExecutionResult::fromArray($data);
    }

    public function getExecution(string $id): ?AiExecutionResult
    {
        $base = rtrim($this->baseUrl, '/');
        if ($base === '') {
            return null;
        }

        $response = Http::timeout($this->timeoutSeconds)
            ->acceptJson()
            ->get($base.'/api/system/v1/ai/executions/'.$id);

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
