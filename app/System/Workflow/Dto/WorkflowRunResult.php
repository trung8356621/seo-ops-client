<?php

declare(strict_types=1);

namespace App\System\Workflow\Dto;

final class WorkflowRunResult
{
    /**
     * @param  array<string, mixed>  $steps
     * @param  array<string, mixed>  $artifacts
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly array $steps = [],
        public readonly array $artifacts = [],
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
            'steps' => $this->steps,
            'artifacts' => $this->artifacts,
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
            steps: is_array($payload['steps'] ?? null) ? $payload['steps'] : [],
            artifacts: is_array($payload['artifacts'] ?? null) ? $payload['artifacts'] : [],
            meta: is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
            errorCode: isset($error['code']) ? (string) $error['code'] : null,
            errorMessage: isset($error['message']) ? (string) $error['message'] : null,
        );
    }
}
