<?php

declare(strict_types=1);

namespace App\Services\H1\Enforcement;

use App\Core\Installation\InstallationStateManager;
use App\Models\H1Installation;
use App\Services\H1\Persistence\InstallationStateRepository;
use App\Services\H1\Snapshot\EntitlementSnapshotMapper;

/**
 * Per-service commercial gate. Agent never bypasses SEO/Seeding checks.
 */
final class ServiceEntitlementGate
{
    public function __construct(
        private readonly InstallationStateRepository $installations,
        private readonly EntitlementSnapshotMapper $mapper,
        private readonly ?InstallationStateManager $installationState = null,
    ) {}

    public function enforcementActive(): bool
    {
        $mode = (string) config('h1-license.enforcement_mode', 'pre_enrollment');
        if ($mode === 'enforcing') {
            return true;
        }

        // Enrolled installations are always under H1 snapshot authority.
        // pre_enrollment fail-open applies only to legacy installs that never enrolled.
        if ($this->installations->isEnrolled()) {
            return true;
        }

        $installation = $this->installations->currentInstallation();
        if ($installation instanceof H1Installation && $installation->cutover_mode === 'enforcing') {
            return true;
        }

        // Fresh V1 installation boundary:
        // A fresh completed installation must enforce entitlement (denying commercial operations)
        // rather than failing open via legacy pre_enrollment compatibility.
        $stateManager = $this->installationState ?? app(InstallationStateManager::class);
        if ($stateManager->isFreshUnlicensed()) {
            return true;
        }

        return false;
    }

    public function allows(string $serviceKey): bool
    {
        return $this->state($serviceKey) === 'allowed';
    }

    /**
     * Commercial entitlement state for a service key (H1 is sole authority).
     *
     * @return 'allowed'|'not_entitled'|'suspended'
     */
    public function state(string $serviceKey): string
    {
        if ($serviceKey === 'agent') {
            return 'allowed';
        }

        if (! $this->enforcementActive()) {
            return 'allowed';
        }

        $installation = $this->installations->currentInstallation();
        if (! $installation instanceof H1Installation || ! is_array($installation->verified_snapshot)) {
            return 'not_entitled';
        }

        $snap = $installation->verified_snapshot;
        $services = $snap['services'] ?? [];
        if (! is_array($services) || ! isset($services[$serviceKey]) || ! is_array($services[$serviceKey])) {
            return 'not_entitled';
        }

        $service = $services[$serviceKey];
        if ($this->serviceSnapshotAllows($service, $snap)) {
            return 'allowed';
        }

        // Present in verified snapshot but not currently usable → suspended/revoked/expired.
        return 'suspended';
    }

    /**
     * @param  array<string, mixed>  $service
     * @param  array<string, mixed>  $snap
     */
    private function serviceSnapshotAllows(array $service, array $snap): bool
    {
        if (! $this->mapper->serviceCurrentlyLicensed($service)) {
            return false;
        }

        if (! empty($snap['revoked_at'])) {
            return false;
        }

        $deadline = $snap['grace_until'] ?? $snap['valid_until'] ?? null;
        if (is_string($deadline) && $deadline !== '' && ! $this->mapper->timestampAllows($deadline)) {
            if (empty($service['valid_until']) && empty($service['grace_until'])) {
                return false;
            }
        }

        return true;
    }

    public function seoQuota(): ?int
    {
        $installation = $this->installations->currentInstallation();
        if (! $installation instanceof H1Installation || ! is_array($installation->verified_snapshot)) {
            return null;
        }
        $quota = $installation->verified_snapshot['services']['seo']['quota'] ?? null;

        return is_numeric($quota) ? (int) $quota : null;
    }
}
