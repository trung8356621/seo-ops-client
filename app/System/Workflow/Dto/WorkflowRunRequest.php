<?php

declare(strict_types=1);

namespace App\System\Workflow\Dto;

final class WorkflowRunRequest
{
    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $correlation
     */
    public function __construct(
        public readonly ?int $definitionId = null,
        public readonly ?array $definition = null,
        public readonly array $input = [],
        public readonly array $context = [],
        public readonly array $correlation = [],
        public readonly ?string $idempotencyKey = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $definitionId = isset($payload['definition_id']) ? (int) $payload['definition_id'] : null;
        $definition = is_array($payload['definition'] ?? null) ? $payload['definition'] : null;

        return new self(
            definitionId: $definitionId > 0 ? $definitionId : null,
            definition: $definition,
            input: is_array($payload['input'] ?? null) ? $payload['input'] : [],
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
            'definition_id' => $this->definitionId,
            'definition' => $this->definition,
            'input' => $this->input,
            'context' => $this->context,
            'correlation' => $this->correlation,
            'idempotency_key' => $this->idempotencyKey,
        ];
    }
}
