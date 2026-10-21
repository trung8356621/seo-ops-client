<?php

declare(strict_types=1);

namespace App\Services\H1\Enforcement;

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
    ) {}

    public function enforcementActive(): bool
    {
        $mode = (string) config('h1-license.enforcement_mode', 'pre_enrollment');
        if ($mode === 'enforcing') {
            return true;
        }
        // Cutover: once enrolled, prefer verified snapshot even in pre_enrollment
        // only when installation cutover_mode is enforcing.
        $installation = $this->installations->currentInstallation();
        if ($installation instanceof H1Installation && $installation->cutover_mode === 'enforcing') {
            return true;
        }

        return false;
    }

    public function allows(string $serviceKey): bool
    {
        if ($serviceKey === 'agent') {
            return true;
        }

        if (! $this->enforcementActive()) {
            return true;
        }

        $installation = $this->installations->currentInstallation();
        if (! $installation instanceof H1Installation || ! is_array($installation->verified_snapshot)) {
            return false;
        }

        $services = $installation->verified_snapshot['services'] ?? [];
        if (! is_array($services) || ! isset($services[$serviceKey]) || ! is_array($services[$serviceKey])) {
            return false;
        }

        $service = $services[$serviceKey];
        if (! $this->mapper->serviceCurrentlyLicensed($service)) {
            return false;
        }

        // Product-level revoke / expiry also blocks when no per-service dates.
        $snap = $installation->verified_snapshot;
        if (! empty($snap['revoked_at'])) {
            return false;
        }
        $deadline = $snap['grace_until'] ?? $snap['valid_until'] ?? null;
        if (is_string($deadline) && $deadline !== '' && ! $this->mapper->timestampAllows($deadline)) {
            // Per-service dates already checked; product deadline is a backstop when service omits dates.
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
