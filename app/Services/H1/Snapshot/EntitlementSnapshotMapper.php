<?php

declare(strict_types=1);

namespace App\Services\H1\Snapshot;

use App\Services\Gateway\VerifiedEntitlementSnapshot;
use App\Services\H1\Protocol\LicenseProtocol;
use Illuminate\Support\Carbon;

final class EntitlementSnapshotMapper
{
    /**
     * @param  array<string, mixed>  $entitlement
     * @return array<string, mixed>
     */
    public function normalizeEntitlement(
        array $entitlement,
        string $installationId,
        int $revision,
        int $credentialVersion,
        ?string $lastCommandId = null,
    ): array {
        $servicesIn = $entitlement['services'] ?? [];
        $services = [];
        if (is_array($servicesIn)) {
            foreach ($servicesIn as $key => $row) {
                if (is_array($row) && isset($row['key'])) {
                    $services[(string) $row['key']] = $this->normalizeService($row);
                } elseif (is_string($key) && is_array($row)) {
                    $services[$key] = $this->normalizeService(array_merge(['key' => $key], $row));
                }
            }
        }

        return [
            'protocol' => LicenseProtocol::ID,
            'installation_id' => $installationId,
            'product' => (string) ($entitlement['product'] ?? config('h1-license.product', LicenseProtocol::PRODUCT)),
            'credential' => [
                'version' => $credentialVersion,
            ],
            'entitlement_revision' => $revision,
            'last_command_id' => $lastCommandId,
            'validated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'plan_key' => isset($entitlement['plan_key']) ? (string) $entitlement['plan_key'] : null,
            'valid_until' => $entitlement['valid_until'] ?? null,
            'grace_until' => $entitlement['grace_until'] ?? null,
            'revoked_at' => $entitlement['revoked_at'] ?? null,
            'services' => $services,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeService(array $row): array
    {
        return [
            'key' => (string) ($row['key'] ?? ''),
            'scope' => $row['scope'] ?? null,
            'license_model' => $row['license_model'] ?? null,
            'enabled' => (bool) ($row['enabled'] ?? false),
            'quota' => array_key_exists('quota', $row) ? (is_numeric($row['quota']) ? (int) $row['quota'] : null) : null,
            'valid_until' => $row['valid_until'] ?? null,
            'grace_until' => $row['grace_until'] ?? null,
            'revoked_at' => $row['revoked_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function toPresentation(array $snapshot): VerifiedEntitlementSnapshot
    {
        $services = [];
        foreach (($snapshot['services'] ?? []) as $key => $row) {
            if (! is_array($row)) {
                continue;
            }
            $slug = is_string($key) ? $key : (string) ($row['key'] ?? '');
            if ($slug === '' || $slug === 'agent') {
                continue;
            }
            $services[$slug] = $this->serviceCurrentlyLicensed($row);
        }

        $status = 'active';
        if (! empty($snapshot['revoked_at'])) {
            $status = 'revoked';
        } elseif (! $this->timestampAllows((string) ($snapshot['grace_until'] ?? $snapshot['valid_until'] ?? ''))) {
            $status = 'expired';
        }

        return new VerifiedEntitlementSnapshot(
            connected: true,
            licenseStatus: $status,
            productName: (string) ($snapshot['product'] ?? 'seo-ops'),
            planName: isset($snapshot['plan_key']) ? (string) $snapshot['plan_key'] : null,
            startsAt: null,
            endsAt: $this->parseTime($snapshot['valid_until'] ?? null),
            entitledServices: $services,
        );
    }

    /**
     * @param  array<string, mixed>  $service
     */
    public function serviceCurrentlyLicensed(array $service, ?\DateTimeInterface $now = null): bool
    {
        if (! (bool) ($service['enabled'] ?? false)) {
            return false;
        }
        if (! empty($service['revoked_at'])) {
            return false;
        }

        $grace = $service['grace_until'] ?? null;
        $valid = $service['valid_until'] ?? null;
        $deadline = is_string($grace) && $grace !== '' ? $grace : (is_string($valid) ? $valid : null);
        if ($deadline === null || $deadline === '') {
            return true;
        }

        return $this->timestampAllows($deadline, $now);
    }

    public function timestampAllows(string $rfc3339, ?\DateTimeInterface $now = null): bool
    {
        if ($rfc3339 === '') {
            return true;
        }
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $rfc3339, new \DateTimeZone('UTC'));
        if (! $parsed instanceof \DateTimeImmutable) {
            try {
                $parsed = new \DateTimeImmutable($rfc3339);
            } catch (\Throwable) {
                return false;
            }
        }
        $nowTs = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->getTimestamp();

        return $nowTs <= $parsed->getTimestamp();
    }

    private function parseTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
