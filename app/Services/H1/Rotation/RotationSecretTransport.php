<?php

declare(strict_types=1);

namespace App\Services\H1\Rotation;

interface RotationSecretTransport
{
    public function fetchReplacementSecret(
        string $installationId,
        string $rotationId,
        string $fromCredentialId,
    ): string;
}
