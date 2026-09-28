<?php

declare(strict_types=1);

namespace App\Api\Access;

use App\Models\Service;
use App\Services\ServiceIdentity;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Request-scoped temporary Service capability (site-bound).
 * Set by ResolveTemporaryServiceAccess — not ServiceApiContext.
 */
final class TemporaryServiceAccessContext
{
    public const SCOPE_SITE = 'site';
    public const SCOPE_GLOBAL = 'global';

    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public readonly Service $service,
        public readonly ?int $siteId,
        public readonly int $credentialId,
        public readonly array $scopes,
        public readonly string $issuedAt,
        public readonly string $expiresAt,
        public readonly string $lookupId,
        public readonly string $scope = self::SCOPE_SITE,
    ) {}

    public function isSite(): bool
    {
        return $this->scope === self::SCOPE_SITE;
    }

    public function isGlobal(): bool
    {
        return $this->scope === self::SCOPE_GLOBAL;
    }

    public function serviceId(): int
    {
        return (int) $this->service->id;
    }

    public function siteRef(): ?string
    {
        return $this->siteId !== null && $this->siteId > 0 ? 'site:'.$this->siteId : null;
    }

    public function publicServiceSlug(): string
    {
        return ServiceIdentity::publicSlugForCatalog((string) $this->service->slug);
    }

    public function hasScope(string $scope): bool
    {
        $scope = trim($scope);
        if ($scope === '') {
            return false;
        }
        foreach ($this->scopes as $item) {
            if ($item === '*' || $item === $scope) {
                return true;
            }
        }

        return false;
    }

    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable('now');
        $expires = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $this->expiresAt)
            ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $this->expiresAt);

        if (! $expires instanceof DateTimeImmutable) {
            return true;
        }

        return $expires <= $now;
    }
}
