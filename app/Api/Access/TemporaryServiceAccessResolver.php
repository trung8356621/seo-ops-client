<?php

declare(strict_types=1);

namespace App\Api\Access;

use App\Models\Service;
use App\Services\ServiceIdentity;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Resolve opaque temporary Service access tokens into TemporaryServiceAccessContext.
 * Does not use AuthenticateServiceApi / permanent Bearer keys.
 */
final class TemporaryServiceAccessResolver
{
    public function __construct(
        private readonly TemporaryServiceAccessManager $manager,
    ) {}

    public function resolve(string $rawToken): ?TemporaryServiceAccessContext
    {
        $rawToken = trim($rawToken);
        if ($rawToken === '') {
            return null;
        }

        $lookupId = $this->manager->extractLookupId($rawToken);
        if ($lookupId === null) {
            return null;
        }

        $payload = $this->manager->findPayloadByLookup($lookupId);
        if ($payload === null) {
            return null;
        }

        if (! $this->manager->verifyRawToken($rawToken, $payload)) {
            return null;
        }

        $expiresAt = (string) ($payload['expires_at'] ?? '');
        if ($expiresAt === '' || $this->isExpired($expiresAt)) {
            return null;
        }

        $serviceId = (int) ($payload['service_id'] ?? 0);
        $siteId = (int) ($payload['site_id'] ?? 0);
        $credentialId = (int) ($payload['credential_id'] ?? 0);
        if ($serviceId <= 0 || $siteId <= 0 || $credentialId <= 0) {
            return null;
        }

        $service = Service::query()->find($serviceId);
        if (! $service instanceof Service) {
            return null;
        }
        if (! $service->is_active) {
            return null;
        }
        if (ServiceIdentity::publicSlugForCatalog((string) $service->slug) !== ServiceIdentity::PUBLIC_SEO) {
            return null;
        }

        $scopes = $payload['scopes'] ?? [TemporaryServiceAccessManager::READ_SCOPE];
        if (! is_array($scopes)) {
            $scopes = [TemporaryServiceAccessManager::READ_SCOPE];
        }
        $normalizedScopes = [];
        foreach ($scopes as $scope) {
            if (is_string($scope) && trim($scope) !== '') {
                $normalizedScopes[] = trim($scope);
            }
        }
        if ($normalizedScopes === []) {
            $normalizedScopes = [TemporaryServiceAccessManager::READ_SCOPE];
        }

        return new TemporaryServiceAccessContext(
            service: $service,
            siteId: $siteId,
            credentialId: $credentialId,
            scopes: array_values(array_unique($normalizedScopes)),
            issuedAt: (string) ($payload['issued_at'] ?? ''),
            expiresAt: $expiresAt,
            lookupId: $lookupId,
        );
    }

    private function isExpired(string $expiresAt): bool
    {
        $expires = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $expiresAt)
            ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $expiresAt);

        if (! $expires instanceof DateTimeImmutable) {
            return true;
        }

        return $expires <= new DateTimeImmutable('now');
    }
}
