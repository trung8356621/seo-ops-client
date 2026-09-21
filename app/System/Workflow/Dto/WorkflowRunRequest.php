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
        public readonly string $executionMode = WorkflowExecutionMode::FullRun->value,
        public readonly ?string $startNodeId = null,
        public readonly ?string $targetNodeId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $definitionId = isset($payload['definition_id']) ? (int) $payload['definition_id'] : null;
        $definition = is_array($payload['definition'] ?? null) ? $payload['definition'] : null;
        $modeRaw = isset($payload['execution_mode']) ? trim((string) $payload['execution_mode']) : '';
        $startNodeId = isset($payload['start_node_id']) ? trim((string) $payload['start_node_id']) : '';
        $targetNodeId = isset($payload['target_node_id']) ? trim((string) $payload['target_node_id']) : '';

        return new self(
            definitionId: $definitionId > 0 ? $definitionId : null,
            definition: $definition,
            input: is_array($payload['input'] ?? null) ? $payload['input'] : [],
            context: is_array($payload['context'] ?? null) ? $payload['context'] : [],
            correlation: is_array($payload['correlation'] ?? null) ? $payload['correlation'] : [],
            idempotencyKey: isset($payload['idempotency_key']) ? trim((string) $payload['idempotency_key']) : null,
            executionMode: $modeRaw !== '' ? $modeRaw : WorkflowExecutionMode::FullRun->value,
            startNodeId: $startNodeId !== '' ? $startNodeId : null,
            targetNodeId: $targetNodeId !== '' ? $targetNodeId : null,
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
            'execution_mode' => $this->executionMode,
            'start_node_id' => $this->startNodeId,
            'target_node_id' => $this->targetNodeId,
        ];
    }
}
