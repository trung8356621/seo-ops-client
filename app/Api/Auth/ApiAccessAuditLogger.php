<?php

declare(strict_types=1);

namespace App\Api\Auth;

use App\Models\ServiceApiCredential;
use App\Support\RuntimeLogger;

/**
 * Minimal security audit for API Access Gate lifecycle (no secrets / raw keys).
 */
final class ApiAccessAuditLogger
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function log(string $action, ServiceApiCredential $credential, array $context = []): void
    {
        RuntimeLogger::info('api_access_gate.'.$action, array_merge([
            'credential_id' => (int) $credential->id,
            'service_id' => (int) $credential->service_id,
            'key_prefix' => (string) $credential->key_prefix,
            'scopes' => $credential->scopeList(),
            'created_by' => $credential->created_by !== null ? (int) $credential->created_by : null,
            'expires_at' => $credential->expires_at?->toIso8601String(),
            'revoked_at' => $credential->revoked_at?->toIso8601String(),
        ], $context));
    }
}
