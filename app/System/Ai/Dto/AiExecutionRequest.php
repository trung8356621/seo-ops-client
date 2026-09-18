<?php

declare(strict_types=1);

namespace App\System\Ai\Dto;

final class AiExecutionRequest
{
    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $requirements
     * @param  array<string, mixed>  $correlation
     */
    public function __construct(
        public readonly string $capability,
        public readonly array $input = [],
        public readonly array $context = [],
        public readonly array $requirements = [],
        public readonly array $correlation = [],
        public readonly ?string $idempotencyKey = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $capability = trim((string) ($payload['capability'] ?? ''));
        $input = is_array($payload['input'] ?? null) ? $payload['input'] : [];
        $context = is_array($payload['context'] ?? null) ? $payload['context'] : [];
        $requirements = is_array($payload['requirements'] ?? null) ? $payload['requirements'] : [];
        $correlation = is_array($payload['correlation'] ?? null) ? $payload['correlation'] : [];
        $idempotency = isset($payload['idempotency_key']) ? trim((string) $payload['idempotency_key']) : null;

        return new self(
            capability: $capability,
            input: $input,
            context: $context,
            requirements: $requirements,
            correlation: $correlation,
            idempotencyKey: $idempotency !== '' ? $idempotency : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'capability' => $this->capability,
            'input' => $this->input,
            'context' => $this->context,
            'requirements' => $this->requirements,
            'correlation' => $this->correlation,
            'idempotency_key' => $this->idempotencyKey,
        ];
    }
}
