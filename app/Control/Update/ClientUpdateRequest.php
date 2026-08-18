<?php

declare(strict_types=1);

namespace App\Control\Update;

final readonly class ClientUpdateRequest
{
    public function __construct(
        public ?string $release = null,
        public ?string $version = null,
        public ?string $artifactUrl = null,
    ) {}

    /**
     * Exact release identifier from ops-server. Client never picks a version on its own.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        $release = isset($payload['release']) ? trim((string) $payload['release']) : '';
        $version = isset($payload['version']) ? trim((string) $payload['version']) : '';
        $artifactUrl = isset($payload['artifact_url']) ? trim((string) $payload['artifact_url']) : '';

        return new self(
            release: $release !== '' ? $release : null,
            version: $version !== '' ? $version : null,
            artifactUrl: $artifactUrl !== '' ? $artifactUrl : null,
        );
    }
}
