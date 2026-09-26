<?php

declare(strict_types=1);

namespace App\Api\Services;

use App\Models\Service;
use App\Models\ServiceApiCredential;
use App\Services\ServiceIdentity;

/**
 * Request-scoped Service API auth context (set by AuthenticateServiceApi).
 */
final class ServiceApiContext
{
    public function __construct(
        public readonly Service $service,
        public readonly ServiceApiCredential $credential,
    ) {}

    public function serviceId(): int
    {
        return (int) $this->service->id;
    }

    public function serviceSlug(): string
    {
        return (string) $this->service->slug;
    }

    public function publicServiceSlug(): string
    {
        return ServiceIdentity::publicSlugForCatalog($this->serviceSlug());
    }

    /**
     * @return list<string>
     */
    public function scopes(): array
    {
        return $this->credential->scopeList();
    }

    public function hasScope(string $scope): bool
    {
        return $this->credential->hasScope($scope);
    }

    public function credentialId(): int
    {
        return (int) $this->credential->id;
    }
}
