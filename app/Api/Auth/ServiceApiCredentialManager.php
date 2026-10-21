<?php

declare(strict_types=1);

namespace App\Api\Auth;

use App\Enums\ServiceApiCredentialKind;
use App\Models\Service;
use App\Models\ServiceApiCredential;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * User API Access Gate credential lifecycle: create / revoke / rotate.
 * Does not touch services.service_key, Control Gate, or Service Gate managed keys.
 */
final class ServiceApiCredentialManager
{
    public function __construct(
        private readonly ApiKeyGenerator $generator,
        private readonly ScopeNormalizer $scopeNormalizer,
        private readonly ApiAccessAuditLogger $auditLogger,
    ) {}

    /**
     * @param  list<string>|string|null  $scopes
     */
    public function create(
        Service $service,
        string $name,
        mixed $scopes = null,
        ?Carbon $expiresAt = null,
        ?int $createdBy = null,
    ): ApiCredentialResult {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Credential name is required (max 120 characters).');
        }

        $normalizedScopes = $this->scopeNormalizer->normalize($scopes);
        $material = $this->generator->generate();

        $credential = new ServiceApiCredential([
            'service_id' => $service->id,
            'kind' => ServiceApiCredentialKind::User,
            'name' => $name,
            'key_prefix' => $material['key_prefix'],
            'key_hash' => $material['key_hash'],
            'scopes' => $normalizedScopes === [] ? null : $normalizedScopes,
            'expires_at' => $expiresAt,
            'created_by' => $createdBy,
        ]);
        $credential->save();
        $fresh = $credential->fresh() ?? $credential;
        $this->auditLogger->log('created', $fresh);

        return new ApiCredentialResult($fresh, $material['raw_key']);
    }

    public function revoke(ServiceApiCredential $credential): ServiceApiCredential
    {
        if ($credential->isManaged()) {
            throw new InvalidArgumentException('Managed Service Gate credentials cannot be revoked via API Access Gate.');
        }

        if ($credential->revoked_at === null) {
            $credential->forceFill(['revoked_at' => now()])->save();
        }

        $fresh = $credential->fresh() ?? $credential;
        $this->auditLogger->log('revoked', $fresh);

        return $fresh;
    }

    /**
     * Create a replacement credential and revoke the old one.
     *
     * @param  list<string>|string|null  $scopes  null = keep previous scopes
     */
    public function rotate(
        ServiceApiCredential $credential,
        ?string $name = null,
        mixed $scopes = null,
        ?Carbon $expiresAt = null,
        ?int $createdBy = null,
        bool $keepExpiresAt = true,
    ): ApiCredentialResult {
        if ($credential->isManaged()) {
            throw new InvalidArgumentException('Managed Service Gate credentials cannot be rotated via API Access Gate.');
        }

        $service = $credential->service;
        if (! $service instanceof Service) {
            throw new RuntimeException('Credential has no Service.');
        }

        return DB::transaction(function () use ($credential, $service, $name, $scopes, $expiresAt, $createdBy, $keepExpiresAt): ApiCredentialResult {
            $resolvedScopes = $scopes ?? $credential->scopes;
            $resolvedExpires = $expiresAt;
            if ($keepExpiresAt && $expiresAt === null) {
                $resolvedExpires = $credential->expires_at;
            }

            $result = $this->create(
                $service,
                $name ?? (string) $credential->name,
                $resolvedScopes,
                $resolvedExpires,
                $createdBy ?? ($credential->created_by !== null ? (int) $credential->created_by : null),
            );

            $this->revoke($credential);
            $this->auditLogger->log('rotated', $result->credential, [
                'predecessor_id' => (int) $credential->id,
            ]);

            return $result;
        });
    }
}
