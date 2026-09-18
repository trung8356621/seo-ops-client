<?php

declare(strict_types=1);

namespace App\System\Agent\Dto;

final class AgentRunResult
{
    /**
     * @param  list<array{event: string, data?: array<string, mixed>}>  $events
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $conversationId,
        public readonly string $status,
        public readonly array $events = [],
        public readonly array $output = [],
        public readonly array $meta = [],
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'conversation_id' => $this->conversationId,
            'status' => $this->status,
            'events' => $this->events,
            'output' => $this->output,
            'meta' => $this->meta,
            'error' => $this->errorCode === null && $this->errorMessage === null ? null : [
                'code' => $this->errorCode,
                'message' => $this->errorMessage,
            ],
        ];
    }
}
