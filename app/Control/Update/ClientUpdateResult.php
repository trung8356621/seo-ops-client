<?php

declare(strict_types=1);

namespace App\Control\Update;

final readonly class ClientUpdateResult
{
    public function __construct(
        public string $state,
        public ?string $release = null,
        public ?string $message = null,
    ) {}

    public static function notConfigured(?string $release = null): self
    {
        return new self(
            state: 'not_configured',
            release: $release,
            message: 'Client updater is not configured. Source replacement is deferred.',
        );
    }
}
