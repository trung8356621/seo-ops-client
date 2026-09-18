<?php

declare(strict_types=1);

namespace App\System\Agent\Dto;

final class AgentMessageRequest
{
    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $correlation
     */
    public function __construct(
        public readonly ?string $conversationId,
        public readonly string $message,
        public readonly array $context = [],
        public readonly array $correlation = [],
        public readonly ?string $idempotencyKey = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            conversationId: isset($payload['conversation_id']) ? trim((string) $payload['conversation_id']) : null,
            message: trim((string) ($payload['message'] ?? '')),
            context: is_array($payload['context'] ?? null) ? $payload['context'] : [],
            correlation: is_array($payload['correlation'] ?? null) ? $payload['correlation'] : [],
            idempotencyKey: isset($payload['idempotency_key']) ? trim((string) $payload['idempotency_key']) : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'message' => $this->message,
            'context' => $this->context,
            'correlation' => $this->correlation,
            'idempotency_key' => $this->idempotencyKey,
        ];
    }
}
