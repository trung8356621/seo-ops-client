<?php

declare(strict_types=1);

namespace App\System\Ai\Dto;

final class AiExecutionResult
{
    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $trace
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly string $capability,
        public readonly array $output = [],
        public readonly array $trace = [],
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
            'id' => $this->id,
            'status' => $this->status,
            'capability' => $this->capability,
            'output' => $this->output,
            'trace' => $this->trace,
            'meta' => $this->meta,
            'error' => $this->errorCode === null && $this->errorMessage === null ? null : [
                'code' => $this->errorCode,
                'message' => $this->errorMessage,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];

        return new self(
            id: (string) ($payload['id'] ?? ''),
            status: (string) ($payload['status'] ?? 'unknown'),
            capability: (string) ($payload['capability'] ?? ''),
            output: is_array($payload['output'] ?? null) ? $payload['output'] : [],
            trace: is_array($payload['trace'] ?? null) ? $payload['trace'] : [],
            meta: is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
            errorCode: isset($error['code']) ? (string) $error['code'] : null,
            errorMessage: isset($error['message']) ? (string) $error['message'] : null,
        );
    }
}
