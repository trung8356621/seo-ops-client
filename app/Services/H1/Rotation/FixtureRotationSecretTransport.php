<?php

declare(strict_types=1);

namespace App\Services\H1\Rotation;

use App\Services\H1\Exceptions\H1CommandException;

final class FixtureRotationSecretTransport implements RotationSecretTransport
{
    /** @var array<string, string> */
    private array $secrets = [];

    public function register(string $rotationId, string $secretBase64Url): void
    {
        $this->secrets[$rotationId] = $secretBase64Url;
    }

    public function fetchReplacementSecret(
        string $installationId,
        string $rotationId,
        string $fromCredentialId,
    ): string {
        unset($installationId, $fromCredentialId);
        if (! isset($this->secrets[$rotationId])) {
            throw new H1CommandException('rotation_secret_missing', 'Fixture rotation secret not registered.');
        }

        return $this->secrets[$rotationId];
    }
}
