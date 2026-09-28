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
        $credentialId = (int) ($payload['credential_id'] ?? 0);
        if ($serviceId <= 0 || $credentialId <= 0) {
            return null;
        }

        $scope = (string) ($payload['scope'] ?? TemporaryServiceAccessContext::SCOPE_SITE);
        if (! in_array($scope, [TemporaryServiceAccessContext::SCOPE_SITE, TemporaryServiceAccessContext::SCOPE_GLOBAL], true)) {
            return null;
        }

        $rawSiteId = $payload['site_id'] ?? null;
        $siteId = is_numeric($rawSiteId) && (int) $rawSiteId > 0 ? (int) $rawSiteId : null;

        if ($scope === TemporaryServiceAccessContext::SCOPE_SITE && $siteId === null) {
            return null;
        }
        if ($scope === TemporaryServiceAccessContext::SCOPE_GLOBAL) {
            $siteId = null;
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
        foreach ($scopes as $s) {
            if (is_string($s) && trim($s) !== '') {
                $normalizedScopes[] = trim($s);
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
            scope: $scope,
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
