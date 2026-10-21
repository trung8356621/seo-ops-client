<?php

declare(strict_types=1);

namespace App\Services\Gateway;

/**
 * Reads local Service Gate capability declarations from config/service_gateway.php.
 */
final class ServiceCapabilityManifest
{
    /**
     * @return array{
     *     requires_managed_api_credential: bool,
     *     managed_scopes: list<string>,
     *     opens_inbound_http: bool
     * }
     */
    public function forPublicSlug(string $publicSlug): array
    {
        $row = config('service_gateway.services.'.$publicSlug, []);
        if (! is_array($row)) {
            $row = [];
        }

        $scopes = $row['managed_scopes'] ?? [];
        if (! is_array($scopes)) {
            $scopes = [];
        }

        $normalizedScopes = [];
        foreach ($scopes as $scope) {
            if (is_string($scope) && trim($scope) !== '') {
                $normalizedScopes[] = strtolower(trim($scope));
            }
        }

        return [
            'requires_managed_api_credential' => (bool) ($row['requires_managed_api_credential'] ?? false),
            'managed_scopes' => array_values(array_unique($normalizedScopes)),
            'opens_inbound_http' => (bool) ($row['opens_inbound_http'] ?? false),
        ];
    }

    public function requiresManagedCredential(string $publicSlug): bool
    {
        return $this->forPublicSlug($publicSlug)['requires_managed_api_credential'];
    }
}
