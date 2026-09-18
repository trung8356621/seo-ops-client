<?php

declare(strict_types=1);

namespace App\System\Agent\Api;

use App\System\Agent\Contracts\SystemAgentClient;
use App\System\Agent\Dto\AgentMessageRequest;
use App\System\Support\SystemApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AgentConversationController
{
    public function __construct(
        private readonly SystemAgentClient $agent,
    ) {}

    public function storeConversation(Request $request): JsonResponse
    {
        $context = is_array($request->input('context')) ? $request->input('context') : [];

        return SystemApiEnvelope::ok($this->agent->createConversation($context), status: 201);
    }

    public function storeMessage(Request $request, string $id): JsonResponse
    {
        $payload = array_merge($request->all(), ['conversation_id' => $id]);
        $messageRequest = AgentMessageRequest::fromArray($payload);
        if ($messageRequest->message === '') {
            return SystemApiEnvelope::error('validation_error', 'message is required', 422);
        }

        $result = $this->agent->sendMessage($messageRequest);
        $status = $result->status === 'failed' ? 422 : 200;

        return SystemApiEnvelope::ok($result->toArray(), status: $status);
    }

    public function showRun(string $id): JsonResponse
    {
        $result = $this->agent->getRun($id);
        if ($result === null) {
            return SystemApiEnvelope::error('not_found', 'Agent run not found', 404);
        }

        return SystemApiEnvelope::ok($result->toArray());
    }

    /**
     * SSE-friendly stream contract. Events designed for future token streaming.
     */
    public function streamRun(string $id): StreamedResponse
    {
        $result = $this->agent->getRun($id);

        return response()->stream(function () use ($result): void {
            if ($result === null) {
                echo "event: run.failed\n";
                echo 'data: '.json_encode(['code' => 'not_found'], JSON_UNESCAPED_UNICODE)."\n\n";

                return;
            }

            foreach ($result->events as $event) {
                $name = (string) ($event['event'] ?? 'message');
                $data = $event['data'] ?? [];
                echo "event: {$name}\n";
                echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n";
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
