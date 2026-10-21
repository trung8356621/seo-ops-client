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
    public function consume(string $enrollmentToken, string $clientVersion): array;
}
