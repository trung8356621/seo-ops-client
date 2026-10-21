<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\Api\Auth\ApiKeyGenerator;
use App\Api\Auth\ScopeNormalizer;
use App\Enums\ServiceApiCredentialKind;
use App\Enums\ServiceGatewayProvisionStatus;
use App\Models\Service;
use App\Models\ServiceApiCredential;
use App\Models\ServiceGatewayProvision;
use App\Services\ServiceIdentity;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Idempotent Service Gate provisioner.
 * Creates managed credentials only when entitlement + capability require them.
 * Never logs raw secrets. Does not open inbound HTTP endpoints.
 */
final class ServiceGatewayProvisioner
{
    public function __construct(
        private readonly ServiceCapabilityManifest $manifest,
        private readonly ApiKeyGenerator $keyGenerator,
        private readonly ScopeNormalizer $scopeNormalizer,
        private readonly EntitlementPresentationStore $entitlementStore,
    ) {}

    public function provisionPublicSlug(string $publicSlug, ?VerifiedEntitlementSnapshot $snapshot = null): ServiceGatewayProvisionResult
    {
        $snapshot ??= $this->entitlementStore->current();
        $service = ServiceIdentity::findService($publicSlug);
        if (! $service instanceof Service) {
            throw new RuntimeException('Service catalog row missing for '.$publicSlug);
        }

        return $this->provisionService($service, $publicSlug, $snapshot);
    }

    /**
     * @return list<ServiceGatewayProvisionResult>
     */
    public function provisionFromSnapshot(VerifiedEntitlementSnapshot $snapshot): array
    {
        $results = [];
        foreach (ServiceIdentity::knownPublicSlugs() as $publicSlug) {
            $service = ServiceIdentity::findService($publicSlug);
            if (! $service instanceof Service) {
                continue;
            }
            $results[] = $this->provisionService($service, $publicSlug, $snapshot);
        }

        return $results;
    }

    /**
     * Controlled managed-key rotation (never automatic on RENEW).
     */
    public function rotateManagedCredential(string $publicSlug): ServiceGatewayProvisionResult
    {
        $snapshot = $this->entitlementStore->current();
        if (! $snapshot->connected || ! $snapshot->isServiceEntitled($publicSlug)) {
            throw new RuntimeException('Cannot rotate managed credential without active entitlement.');
        }

        $service = ServiceIdentity::findService($publicSlug);
        if (! $service instanceof Service) {
            throw new RuntimeException('Service catalog row missing for '.$publicSlug);
        }

        $capability = $this->manifest->forPublicSlug($publicSlug);
        if (! $capability['requires_managed_api_credential']) {
            throw new RuntimeException('Service does not require a managed credential.');
        }

        return DB::transaction(function () use ($service, $publicSlug, $snapshot, $capability): ServiceGatewayProvisionResult {
            $provision = $this->lockProvisionRow($service, $publicSlug);

            $existing = $this->findActiveManagedCredential($service);
            if ($existing instanceof ServiceApiCredential) {
                $existing->forceFill(['revoked_at' => now()])->save();
            }

            $created = $this->createManagedCredential($service, $capability['managed_scopes']);
            $provision->fill([
                'status' => ServiceGatewayProvisionStatus::Ready,
                'entitled' => true,
                'execution_allowed' => true,
                'requires_managed_credential' => true,
                'managed_credential_id' => $created->id,
                'last_error' => null,
                'last_provisioned_at' => now(),
                'metadata' => [
                    'license_status' => $snapshot->licenseStatus,
                    'rotated' => true,
                ],
            ])->save();

            RuntimeLogger::info('service_gateway.managed_rotated', [
                'service_id' => (int) $service->id,
                'public_slug' => $publicSlug,
                'credential_id' => (int) $created->id,
            ]);

            return new ServiceGatewayProvisionResult($provision->fresh() ?? $provision, true);
        });
    }

    private function provisionService(
        Service $service,
        string $publicSlug,
        VerifiedEntitlementSnapshot $snapshot,
    ): ServiceGatewayProvisionResult {
        if (! Schema::hasTable('service_gateway_provisions')) {
            throw new RuntimeException('service_gateway_provisions table missing.');
        }

        try {
            return DB::transaction(function () use ($service, $publicSlug, $snapshot): ServiceGatewayProvisionResult {
                $provision = $this->lockProvisionRow($service, $publicSlug);
                $capability = $this->manifest->forPublicSlug($publicSlug);
                $requires = $capability['requires_managed_api_credential'];
                $entitled = $snapshot->connected && $snapshot->isServiceEntitled($publicSlug);

                if (! $entitled) {
                    $status = $snapshot->connected
                        ? ServiceGatewayProvisionStatus::NotEntitled
                        : ServiceGatewayProvisionStatus::Revoked;

                    // Keep managed credential row for restore; disable execution immediately.
                    $provision->fill([
                        'status' => $snapshot->licenseStatus === 'revoked'
                            ? ServiceGatewayProvisionStatus::Revoked
                            : $status,
                        'entitled' => false,
                        'execution_allowed' => false,
                        'requires_managed_credential' => $requires,
                        'last_error' => null,
                        'metadata' => [
                            'license_status' => $snapshot->licenseStatus,
                            'connected' => $snapshot->connected,
                        ],
                    ])->save();

                    return new ServiceGatewayProvisionResult($provision->fresh() ?? $provision, false);
                }

                if (! $requires) {
                    $provision->fill([
                        'status' => ServiceGatewayProvisionStatus::NotRequired,
                        'entitled' => true,
                        'execution_allowed' => true,
                        'requires_managed_credential' => false,
                        'last_error' => null,
                        'last_provisioned_at' => now(),
                        'metadata' => [
                            'license_status' => $snapshot->licenseStatus,
                            'opens_inbound_http' => $capability['opens_inbound_http'],
                        ],
                    ])->save();

                    return new ServiceGatewayProvisionResult($provision->fresh() ?? $provision, false);
                }

                $existing = $this->findActiveManagedCredential($service);
                $created = false;
                if (! $existing instanceof ServiceApiCredential) {
                    // Prefer previously linked (possibly still usable) credential on re-GRANT.
                    $linked = null;
                    if ($provision->managed_credential_id) {
                        $linked = ServiceApiCredential::query()
                            ->whereKey($provision->managed_credential_id)
                            ->where('service_id', $service->id)
                            ->where('kind', ServiceApiCredentialKind::Managed->value)
                            ->first();
                    }

                    if ($linked instanceof ServiceApiCredential && $linked->revoked_at === null) {
                        $existing = $linked;
                    } elseif ($linked instanceof ServiceApiCredential && $linked->revoked_at !== null) {
                        // Re-GRANT restores prior key without minting a new secret by default.
                        $linked->forceFill(['revoked_at' => null])->save();
                        $existing = $linked;
                    } else {
                        $existing = $this->createManagedCredential($service, $capability['managed_scopes']);
                        $created = true;
                    }
                }

                $provision->fill([
                    'status' => ServiceGatewayProvisionStatus::Ready,
                    'entitled' => true,
                    'execution_allowed' => true,
                    'requires_managed_credential' => true,
                    'managed_credential_id' => $existing->id,
                    'last_error' => null,
                    'last_provisioned_at' => now(),
                    'metadata' => [
                        'license_status' => $snapshot->licenseStatus,
                        'opens_inbound_http' => $capability['opens_inbound_http'],
                    ],
                ])->save();

                RuntimeLogger::info('service_gateway.provisioned', [
                    'service_id' => (int) $service->id,
                    'public_slug' => $publicSlug,
                    'credential_created' => $created,
                    'credential_id' => (int) $existing->id,
                    'status' => ServiceGatewayProvisionStatus::Ready->value,
                ]);

                return new ServiceGatewayProvisionResult($provision->fresh() ?? $provision, $created);
            });
        } catch (Throwable $e) {
            RuntimeLogger::error('service_gateway.provision_failed', [
                'public_slug' => $publicSlug,
                'service_id' => (int) $service->id,
                'error' => $e->getMessage(),
            ]);

            $provision = ServiceGatewayProvision::query()->firstOrNew(['service_id' => $service->id]);
            $provision->fill([
                'public_slug' => $publicSlug,
                'status' => ServiceGatewayProvisionStatus::Error,
                'entitled' => false,
                'execution_allowed' => false,
                'requires_managed_credential' => $this->manifest->requiresManagedCredential($publicSlug),
                'last_error' => $e->getMessage(),
            ])->save();

            return new ServiceGatewayProvisionResult($provision, false, $e->getMessage());
        }
    }

    private function lockProvisionRow(Service $service, string $publicSlug): ServiceGatewayProvision
    {
        $provision = ServiceGatewayProvision::query()
            ->where('service_id', $service->id)
            ->lockForUpdate()
            ->first();

        if (! $provision instanceof ServiceGatewayProvision) {
            $provision = new ServiceGatewayProvision([
                'service_id' => $service->id,
                'public_slug' => $publicSlug,
                'status' => ServiceGatewayProvisionStatus::Pending,
            ]);
            $provision->save();

            $provision = ServiceGatewayProvision::query()
                ->whereKey($provision->id)
                ->lockForUpdate()
                ->firstOrFail();
        }

        return $provision;
    }

    private function findActiveManagedCredential(Service $service): ?ServiceApiCredential
    {
        if (! Schema::hasColumn('service_api_credentials', 'kind')) {
            return null;
        }

        return ServiceApiCredential::query()
            ->where('service_id', $service->id)
            ->where('kind', ServiceApiCredentialKind::Managed->value)
            ->whereNull('revoked_at')
            ->lockForUpdate()
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  list<string>  $scopes
     */
    private function createManagedCredential(Service $service, array $scopes): ServiceApiCredential
    {
        $normalized = $this->scopeNormalizer->normalize($scopes === [] ? ['service:read'] : $scopes);
        $material = $this->keyGenerator->generate(ApiKeyGenerator::PREFIX_MANAGED);

        $credential = new ServiceApiCredential([
            'service_id' => $service->id,
            'kind' => ServiceApiCredentialKind::Managed,
            'name' => 'Service Gate managed',
            'key_prefix' => $material['key_prefix'],
            'key_hash' => $material['key_hash'],
            'scopes' => $normalized,
            'created_by' => null,
        ]);
        $credential->save();

        // Raw key exists only in $material — never persisted / logged.
        unset($material['raw_key']);

        return $credential->fresh() ?? $credential;
    }
}
