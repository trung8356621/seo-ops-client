<?php

declare(strict_types=1);

namespace App\System\Ai\Api;

use App\System\Ai\Contracts\SystemAiClient;
use App\System\Ai\Dto\AiExecutionRequest;
use App\System\Support\SystemApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AiExecutionController
{
    public function __construct(
        private readonly SystemAiClient $ai,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $payload = $request->all();
        $executionRequest = AiExecutionRequest::fromArray($payload);

        if (trim($executionRequest->capability) === '') {
            return SystemApiEnvelope::error(
                code: 'validation_error',
                message: 'capability is required',
                status: 422,
                correlationId: isset($payload['correlation']['id']) ? (string) $payload['correlation']['id'] : null,
            );
        }

        // Force in-process when called via HTTP so RemoteHttp does not recurse.
        $forced = new AiExecutionRequest(
            capability: $executionRequest->capability,
            input: $executionRequest->input,
            context: array_merge($executionRequest->context, [
                'via_http_api' => true,
            ]),
            requirements: $executionRequest->requirements,
            correlation: $executionRequest->correlation,
            idempotencyKey: $executionRequest->idempotencyKey,
        );

        $result = $this->ai->execute($forced);
        $status = $result->status === 'failed' ? 422 : 200;

        return SystemApiEnvelope::ok($result->toArray(), [
            'correlation' => $executionRequest->correlation,
        ], $status);
    }

    public function show(string $id): JsonResponse
    {
        $result = $this->ai->getExecution($id);
        if ($result === null) {
            return SystemApiEnvelope::error('not_found', 'Execution not found', 404);
        }

        return SystemApiEnvelope::ok($result->toArray());
    }
}
