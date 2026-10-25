<?php

declare(strict_types=1);

namespace App\Services\H1\Enrollment;

/**
 * Injectable enrollment transport. Real HTTP is blocked until OPS-SERVER exposes the route.
 */
interface EnrollmentTransport
{
    /**
     * @return array{
     *   success: bool,
     *   code?: string,
     *   is_retry?: bool,
     *   installation_id?: string,
     *   product?: string,
     *   credential?: array{id: string, version: int, secret: string},
     *   entitlement?: array<string, mixed>,
     *   entitlement_revision?: int,
     *   anchor_identity?: string|null,
     *   detail?: string
     * }
     */
    /**
     * @param  string|null  $anchorIdentity  Local Domain Anchor hostname (optional; legacy clients omit)
     * @param  string|null  $previousInstallationId  Prior Installation Key when reconnecting (detach ACK)
     */
    public function consume(
        string $enrollmentToken,
        string $clientVersion,
        ?string $anchorIdentity = null,
        ?string $previousInstallationId = null,
    ): array;
}
