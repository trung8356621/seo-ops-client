<?php

declare(strict_types=1);

namespace App\Services\H1\Commands;

use App\Models\H1CommandReceipt;
use App\Models\H1Installation;
use App\Services\Gateway\EntitlementAppliedHook;
use App\Services\H1\Persistence\InstallationStateRepository;
use App\Services\H1\Protocol\LicenseCrypto;
use App\Services\H1\Protocol\LicenseProtocol;
use App\Services\H1\Sites\AdminSiteStatusPolicy;
use App\Services\H1\Snapshot\EntitlementSnapshotMapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class EntitlementCommandApplier
{
    public function __construct(
        private readonly InstallationStateRepository $installations,
        private readonly EntitlementSnapshotMapper $mapper,
        private readonly AdminSiteStatusPolicy $sites,
        private readonly EntitlementAppliedHook $appliedHook,
        private readonly RotationCommandApplier $rotation,
    ) {}

    /**
     * @param  array<string, mixed>  $command
     * @return array<string, mixed> ACK object
     */
    public function apply(array $command): array
    {
        $installation = $this->installations->currentInstallation();
        if (! $installation instanceof H1Installation) {
            return $this->ack($command, 'rejected', 'invalid_state', false, false);
        }

        $messageType = (string) ($command['message_type'] ?? '');
        if ($messageType === 'rotate_key') {
            return $this->rotation->apply($installation, $command);
        }
        if ($messageType === 'entitlement_snapshot_recovery') {
            return $this->applyRecovery($installation, $command);
        }
        if ($messageType !== 'entitlement_command') {
            return $this->ack($command, 'rejected', 'invalid_state', false, false);
        }

        return $this->applyEntitlement($installation, $command);
    }

    /**
     * @param  array<string, mixed>  $command
     * @return array<string, mixed>
     */
    private function applyEntitlement(H1Installation $installation, array $command): array
    {
        $commandId = (string) ($command['command_id'] ?? '');
        $raw = LicenseCrypto::canonicalJson($command);
        $hash = LicenseCrypto::payloadHash($raw);

        $existing = $this->findReceipt($installation->installation_id, $commandId);
        if ($existing instanceof H1CommandReceipt) {
            if ($existing->payload_hash !== $hash) {
                return $this->storeRejected($installation, $command, $hash, 'idempotency_conflict');
            }

            return $this->duplicateAck($command, $existing);
        }

        $revision = (int) ($command['entitlement_revision'] ?? 0);
        $localRevision = (int) $installation->entitlement_revision;
        $credentialVersion = (int) ($command['credential_version'] ?? 0);

        if ($credentialVersion !== (int) $installation->credential_version) {
            return $this->storeRejected($installation, $command, $hash, 'credential_version_mismatch');
        }
        if ($revision < $localRevision) {
            return $this->storeRejected($installation, $command, $hash, 'stale_revision');
        }
        if ($revision === $localRevision) {
            return $this->storeRejected($installation, $command, $hash, 'revision_conflict');
        }
        if ($revision > $localRevision + 1) {
            return $this->storeRejected($installation, $command, $hash, 'revision_gap');
        }

        $entitlement = is_array($command['entitlement'] ?? null) ? $command['entitlement'] : null;
        if ($entitlement === null) {
            return $this->storeRejected($installation, $command, $hash, 'operation_snapshot_mismatch');
        }

        $operation = (string) ($command['operation'] ?? '');
        $overflow = false;

        return DB::transaction(function () use ($installation, $command, $commandId, $hash, $revision, $credentialVersion, $entitlement, $operation, &$overflow): array {
            $locked = H1Installation::query()->whereKey($installation->getKey())->first();
            if (! $locked instanceof H1Installation) {
                return $this->ack($command, 'failed', 'internal_error', true, false);
            }

            // Re-check idempotency inside the transaction (lost-ACK / concurrent delivery).
            $existing = $this->findReceipt($locked->installation_id, $commandId);
            if ($existing instanceof H1CommandReceipt) {
                if ($existing->payload_hash !== $hash) {
                    return $this->ack($command, 'rejected', 'idempotency_conflict', false, false);
                }

                return $this->duplicateAck($command, $existing);
            }

            if ((int) $locked->entitlement_revision !== $revision - 1) {
                return $this->ack($command, 'rejected', 'revision_conflict', false, false);
            }

            $snapshot = $this->mapper->normalizeEntitlement(
                $entitlement,
                $locked->installation_id,
                $revision,
                $credentialVersion,
                $commandId,
            );

            if ($operation === 'change_plan') {
                $overflow = $this->maybeQuotaOverflowReset($snapshot);
            }

            $ack = $this->ack($command, 'applied', null, false, $overflow, $revision, $credentialVersion, $commandId);
            $this->writeReceipt($locked->installation_id, $commandId, 'entitlement_command', $hash, $ack, $overflow, null);
            $this->installations->updateObservedState(
                $locked,
                $revision,
                $credentialVersion,
                $commandId,
                $snapshot,
                $this->pendingAcksWith($locked, $ack),
            );

            $this->appliedHook->afterVerifiedEntitlementApplied($this->mapper->toPresentation($snapshot));
            $this->sites->bindAnchorFromInstallation($locked->fresh());

            return $ack;
        });
    }

    /**
     * @param  array<string, mixed>  $command
     * @return array<string, mixed>
     */
    private function applyRecovery(H1Installation $installation, array $command): array
    {
        $recoveryId = (string) ($command['recovery_id'] ?? '');
        $raw = LicenseCrypto::canonicalJson($command);
        $hash = LicenseCrypto::payloadHash($raw);
        $existing = $this->findReceipt($installation->installation_id, $recoveryId);
        if ($existing instanceof H1CommandReceipt) {
            return $this->duplicateAck($command, $existing);
        }

        $base = (int) ($command['base_observed_revision'] ?? -1);
        if ($base !== (int) $installation->entitlement_revision) {
            return $this->storeRejected($installation, $command, $hash, 'revision_conflict', $recoveryId);
        }
        $credentialVersion = (int) ($command['credential_version'] ?? 0);
        if ($credentialVersion !== (int) $installation->credential_version) {
            return $this->storeRejected($installation, $command, $hash, 'credential_version_mismatch', $recoveryId);
        }

        $entitlement = is_array($command['entitlement'] ?? null) ? $command['entitlement'] : null;
        if ($entitlement === null) {
            return $this->storeRejected($installation, $command, $hash, 'operation_snapshot_mismatch', $recoveryId);
        }

        $revision = (int) ($command['entitlement_revision'] ?? 0);

        return DB::transaction(function () use ($installation, $command, $recoveryId, $hash, $revision, $credentialVersion, $entitlement): array {
            $locked = H1Installation::query()->whereKey($installation->getKey())->first();
            if (! $locked instanceof H1Installation) {
                return $this->ack($command, 'failed', 'internal_error', true, false);
            }
            $oldSnapshot = is_array($locked->verified_snapshot) ? $locked->verified_snapshot : [];
            $snapshot = $this->mapper->normalizeEntitlement($entitlement, $locked->installation_id, $revision, $credentialVersion, $recoveryId);
            $overflow = $this->recoveryOverflow($oldSnapshot, $snapshot);
            if ($overflow) {
                $this->sites->resetAllInactive();
            }
            $this->installations->updateObservedState($locked, $revision, $credentialVersion, $locked->last_command_id, $snapshot, []);
            $ack = $this->ack($command, 'applied', null, false, $overflow, $revision, $credentialVersion, $recoveryId);
            $ack['recovery_id'] = $recoveryId;
            unset($ack['command_id']);
            $this->writeReceipt($locked->installation_id, $recoveryId, 'entitlement_snapshot_recovery', $hash, $ack, $overflow, null);
            $this->appliedHook->afterVerifiedEntitlementApplied($this->mapper->toPresentation($snapshot));

            return $ack;
        });
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function maybeQuotaOverflowReset(array $snapshot): bool
    {
        $seo = $snapshot['services']['seo'] ?? null;
        if (! is_array($seo)) {
            return false;
        }
        $quota = $seo['quota'] ?? null;
        if (! is_int($quota)) {
            return false;
        }
        if ($this->sites->quotaConsumedCount() > $quota) {
            $this->sites->resetAllInactive();

            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function recoveryOverflow(array $old, array $new): bool
    {
        $newQuota = $new['services']['seo']['quota'] ?? null;
        if (! is_int($newQuota)) {
            return false;
        }
        $oldQuota = $old['services']['seo']['quota'] ?? null;
        if (is_int($oldQuota) && $newQuota >= $oldQuota) {
            return false;
        }

        return $this->sites->quotaConsumedCount() > $newQuota;
    }

    /**
     * @param  array<string, mixed>  $command
     * @return array<string, mixed>
     */
    private function storeRejected(
        H1Installation $installation,
        array $command,
        string $hash,
        string $reason,
        ?string $idOverride = null,
    ): array {
        $ack = $this->ack($command, 'rejected', $reason, false, false);
        $commandId = $idOverride ?? (string) ($command['command_id'] ?? '');
        if ($commandId !== '' && Schema::hasTable('h1_command_receipts')) {
            $this->writeReceipt(
                $installation->installation_id,
                $commandId,
                (string) ($command['message_type'] ?? 'entitlement_command'),
                $hash,
                $ack,
                false,
                isset($command['rotation_id']) ? (string) $command['rotation_id'] : null,
            );
        }

        return $ack;
    }

    /**
     * @param  array<string, mixed>  $command
     * @return array<string, mixed>
     */
    private function duplicateAck(array $command, H1CommandReceipt $existing): array
    {
        $stored = is_array($existing->ack_json) ? $existing->ack_json : [];
        $ack = $this->ack(
            $command,
            'duplicate',
            null,
            false,
            (bool) $existing->quota_overflow_reset,
            isset($stored['observed_state']['entitlement_revision']) ? (int) $stored['observed_state']['entitlement_revision'] : null,
            isset($stored['observed_state']['credential_version']) ? (int) $stored['observed_state']['credential_version'] : null,
            isset($stored['observed_state']['last_command_id']) ? (string) $stored['observed_state']['last_command_id'] : null,
        );
        if (isset($stored['recovery_id'])) {
            $ack['recovery_id'] = $stored['recovery_id'];
            unset($ack['command_id']);
        }

        return $ack;
    }

    /**
     * @param  array<string, mixed>  $command
     * @return array<string, mixed>
     */
    private function ack(
        array $command,
        string $result,
        ?string $reason,
        bool $retryable,
        bool $quotaOverflowReset,
        ?int $revision = null,
        ?int $credentialVersion = null,
        ?string $lastCommandId = null,
    ): array {
        $installationId = (string) ($command['installation_id'] ?? '');
        $commandId = (string) ($command['command_id'] ?? $command['recovery_id'] ?? '');

        return [
            'protocol' => LicenseProtocol::ID,
            'ack_id' => 'ack_'.substr(hash('sha256', $commandId.$result.(string) microtime(true)), 0, 12),
            'command_id' => $commandId,
            'installation_id' => $installationId,
            'product' => (string) ($command['product'] ?? LicenseProtocol::PRODUCT),
            'result' => $result,
            'reason' => $reason,
            'retryable' => $retryable,
            'observed_state' => [
                'entitlement_revision' => $revision ?? (int) ($command['entitlement_revision'] ?? 0),
                'credential_version' => $credentialVersion ?? (int) ($command['credential_version'] ?? 0),
                'last_command_id' => $lastCommandId ?? $commandId,
                'quota_overflow_reset' => $quotaOverflowReset,
            ],
            'processed_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * @param  array<string, mixed>  $ack
     * @return list<array<string, mixed>>
     */
    private function pendingAcksWith(H1Installation $installation, array $ack): array
    {
        $pending = is_array($installation->pending_acks) ? $installation->pending_acks : [];
        $pending[] = $ack;

        return array_values(array_slice($pending, -50));
    }

    private function findReceipt(string $installationId, string $commandId): ?H1CommandReceipt
    {
        if ($commandId === '' || ! Schema::hasTable('h1_command_receipts')) {
            return null;
        }
        $row = H1CommandReceipt::query()
            ->where('installation_id', $installationId)
            ->where('command_id', $commandId)
            ->first();

        return $row instanceof H1CommandReceipt ? $row : null;
    }

    /**
     * @param  array<string, mixed>  $ack
     */
    private function writeReceipt(
        string $installationId,
        string $commandId,
        string $messageType,
        string $hash,
        array $ack,
        bool $overflow,
        ?string $rotationId,
    ): void {
        H1CommandReceipt::query()->updateOrCreate(
            [
                'installation_id' => $installationId,
                'command_id' => $commandId,
            ],
            [
                'message_type' => $messageType,
                'payload_hash' => $hash,
                'result' => (string) ($ack['result'] ?? 'applied'),
                'reason' => $ack['reason'] ?? null,
                'retryable' => (bool) ($ack['retryable'] ?? false),
                'quota_overflow_reset' => $overflow,
                'rotation_id' => $rotationId,
                'ack_json' => $ack,
                'processed_at' => now(),
            ],
        );
    }
}
