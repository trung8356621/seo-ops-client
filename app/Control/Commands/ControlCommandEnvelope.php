<?php

declare(strict_types=1);

namespace App\Control\Commands;

final readonly class ControlCommandEnvelope
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $installationId,
        public string $commandId,
        public string $issuedAt,
        public string $command,
        public array $payload,
        public string $signature,
    ) {}
}
