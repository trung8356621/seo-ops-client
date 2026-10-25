<?php

declare(strict_types=1);

namespace App\Services\H1\Enrollment;

use App\Services\H1\Exceptions\H1EnrollmentException;

/**
 * Default production transport until OPS-SERVER exposes POST /api/v1/enrollment/consume.
 */
final class BlockedEnrollmentTransport implements EnrollmentTransport
{
    public function consume(
        string $enrollmentToken,
        string $clientVersion,
        ?string $anchorIdentity = null,
        ?string $previousInstallationId = null,
    ): array {
        unset($enrollmentToken, $clientVersion, $anchorIdentity, $previousInstallationId);

        throw new H1EnrollmentException(
            'server_endpoint_missing',
            'OPS-SERVER HTTP route POST /api/v1/enrollment/consume is not implemented. '
            .'Enrollment domain exists only via Server admin actions. Live enrollment transport is blocked.',
            false,
        );
    }
}
