<?php

declare(strict_types=1);

namespace App\Api\Auth;

use App\Models\ServiceApiCredential;

/**
 * Create/rotate result: persists only prefix+hash; raw key returned once.
 */
final class ApiCredentialResult
{
    public function __construct(
        public readonly ServiceApiCredential $credential,
        public readonly string $rawKey,
    ) {}
}
