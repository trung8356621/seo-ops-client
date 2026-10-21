<?php

declare(strict_types=1);

namespace App\Services\H1\Rotation;

use App\Services\H1\Exceptions\H1CommandException;

/**
 * Server domain prepares rotation secrets, but no HTTP route is exposed yet.
 */
final class BlockedRotationSecretTransport implements RotationSecretTransport
{
    public function fetchReplacementSecret(
        string $installationId,
        string $rotationId,
        string $fromCredentialId,
    ): string {
        unset($installationId, $rotationId, $fromCredentialId);

        throw new H1CommandException(
            'server_endpoint_missing',
            'OPS-SERVER HTTP rotation secret endpoint is not implemented. '
            .'Expected path template: /api/v1/installations/{installation_id}/license/rotations/{rotation_id}/secret',
        );
    }
}
