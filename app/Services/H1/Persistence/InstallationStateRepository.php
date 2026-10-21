<?php

declare(strict_types=1);

namespace App\Services\H1\Persistence;

use App\Models\H1Credential;
use App\Models\H1Installation;
use App\Services\H1\Exceptions\H1EnrollmentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\InstallationMachineCredential;

final class InstallationStateRepository
{
    public function tablesReady(): bool
    {
        return Schema::hasTable('h1_installations') && Schema::hasTable('h1_credentials');
    }

    public function currentInstallation(): ?H1Installation
    {
        if (! $this->tablesReady()) {
            return null;
        }

        return H1Installation::query()->orderBy('id')->first();
    }

    public function isEnrolled(): bool
    {
        $installation = $this->currentInstallation();
        if (! $installation instanceof H1Installation) {
            return false;
        }

        return $this->activeCredential($installation->installation_id) instanceof H1Credential;
    }

    public function activeCredential(?string $installationId = null): ?H1Credential
    {
        if (! $this->tablesReady()) {
            return null;
        }

        $installationId ??= $this->currentInstallation()?->installation_id;
        if (! is_string($installationId) || $installationId === '') {
            return null;
        }

        $cred = H1Credential::query()
            ->where('installation_id', $installationId)
            ->where('status', H1Credential::STATUS_ACTIVE)
            ->orderByDesc('version')
            ->first();

        return $cred instanceof H1Credential ? $cred : null;
    }

    public function machineCredential(): ?InstallationMachineCredential
    {
        $installation = $this->currentInstallation();
        $cred = $installation ? $this->activeCredential($installation->installation_id) : null;
        if (! $installation instanceof H1Installation || ! $cred instanceof H1Credential) {
            return null;
        }

        $rotation = is_array($installation->rotation_state) ? $installation->rotation_state : [];
        if (($rotation['phase'] ?? null) === 'client_applied' && isset($rotation['new_credential_id'])) {
            $new = H1Credential::query()
                ->where('credential_id', (string) $rotation['new_credential_id'])
                ->where('status', H1Credential::STATUS_ACTIVE)
                ->first();
            if ($new instanceof H1Credential) {
                $cred = $new;
            }
        }

        try {
            $secret = $cred->secretBase64Url();
        } catch (\Throwable) {
            return null;
        }

        if ($secret === '') {
            return null;
        }

        return new InstallationMachineCredential(
            credentialId: $cred->credential_id,
            installationId: $installation->installation_id,
            secretBase64Url: $secret,
        );
    }

    /**
     * @param  array<string, mixed>  $entitlementSnapshot
     */
    public function persistFirstEnrollment(
        string $installationId,
        string $credentialId,
        int $credentialVersion,
        string $secretBase64Url,
        array $entitlementSnapshot,
        ?string $anchorIdentity,
        ?string $controlServerUrl,
        string $clientVersion,
        int $entitlementRevision,
    ): H1Installation {
        if (! $this->tablesReady()) {
            throw new H1EnrollmentException('schema_missing', 'H1 license tables are not migrated.');
        }

        $existing = $this->currentInstallation();
        if ($existing instanceof H1Installation) {
            if ($existing->installation_id !== $installationId) {
                throw new H1EnrollmentException(
                    'already_enrolled',
                    'This Client already has a different enrolled Installation. Refusing replacement.',
                );
            }
            // Idempotent retry for same installation — refresh snapshot/credential if needed.
        }

        return DB::transaction(function () use (
            $installationId,
            $credentialId,
            $credentialVersion,
            $secretBase64Url,
            $entitlementSnapshot,
            $anchorIdentity,
            $controlServerUrl,
            $clientVersion,
            $entitlementRevision,
            $existing,
        ): H1Installation {
            $installation = $existing ?? new H1Installation;
            $installation->fill([
                'installation_id' => $installationId,
                'product' => (string) config('h1-license.product', 'seo-ops'),
                'control_server_url' => $controlServerUrl,
                'status' => 'enrolled',
                'cutover_mode' => (string) ($installation->cutover_mode ?: config('h1-license.enforcement_mode', 'pre_enrollment')),
                'anchor_identity' => $anchorIdentity,
                'client_version' => $clientVersion,
                'entitlement_revision' => $entitlementRevision,
                'credential_version' => $credentialVersion,
                'last_command_id' => $entitlementRevision > 0 ? 'cmd_initial' : null,
                'verified_snapshot' => $entitlementSnapshot,
                'validated_at' => now(),
                'pending_acks' => [],
                'rotation_state' => null,
            ])->save();

            $cred = H1Credential::query()->where('credential_id', $credentialId)->first() ?? new H1Credential;
            $cred->installation_id = $installationId;
            $cred->credential_id = $credentialId;
            $cred->version = $credentialVersion;
            $cred->status = H1Credential::STATUS_ACTIVE;
            $cred->activated_at = now();
            $cred->setSecretBase64Url($secretBase64Url);
            $cred->save();

            return $installation->fresh() ?? $installation;
        });
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  list<array<string, mixed>>  $pendingAcks
     */
    public function updateObservedState(
        H1Installation $installation,
        int $revision,
        int $credentialVersion,
        ?string $lastCommandId,
        array $snapshot,
        array $pendingAcks = [],
    ): void {
        $installation->fill([
            'entitlement_revision' => $revision,
            'credential_version' => $credentialVersion,
            'last_command_id' => $lastCommandId,
            'verified_snapshot' => $snapshot,
            'validated_at' => now(),
            'pending_acks' => $pendingAcks,
        ])->save();
    }
}
