<?php

declare(strict_types=1);

namespace App\Control\Commands;

use App\Enums\ClientControlCommandStatus;

final readonly class ControlCommandResult
{
    /**
     * @param  array<string, mixed>|null  $result
     */
    public function __construct(
        public ClientControlCommandStatus $status,
        public ?array $result = null,
        public ?string $error = null,
    ) {}

    /**
     * @param  array<string, mixed>  $result
     */
    public static function completed(array $result = []): self
    {
        return new self(ClientControlCommandStatus::Completed, $result);
    }

    public static function failed(string $error, array $result = []): self
    {
        return new self(ClientControlCommandStatus::Failed, $result === [] ? null : $result, $error);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public static function ignored(array $result = [], ?string $error = null): self
    {
        return new self(ClientControlCommandStatus::Ignored, $result === [] ? null : $result, $error);
    }

    public function httpStatus(): int
    {
        return match ($this->status) {
            ClientControlCommandStatus::Failed => 422,
            ClientControlCommandStatus::Ignored => 200,
            default => 200,
        };
    }
}
