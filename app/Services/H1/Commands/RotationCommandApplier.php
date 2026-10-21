<?php

declare(strict_types=1);

namespace App\Services\H1\Commands;

use App\Models\H1CommandReceipt;
use App\Models\H1Credential;
use App\Models\H1Installation;
use App\Services\H1\Exceptions\H1CommandException;
use App\Services\H1\Protocol\LicenseCrypto;
use App\Services\H1\Protocol\LicenseProtocol;
use App\Services\H1\Rotation\RotationSecretTransport;
use App\Services\H1\Sites\AdminSiteStatusPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two-phase machine credential rotation state machine.
 * Remote secret fetch uses injectable transport (blocked until Server HTTP exists).
 */
final class RotationCommandApplier
{
    public function __construct(
        private readonly RotationSecretTransport $secretTransport,
        private readonly AdminSiteStatusPolicy $sites,
    ) {}

    /**
     * @param  array<string, mixed>  $command
     * @return array<string, mixed>
     */
    public function apply(H1Installation $installation, array $command): array
    {
        $commandId = (string) ($command['command_id'] ?? '');
        $rotationId = (string) ($command['rotation_id'] ?? '');
        $raw = LicenseCrypto::canonicalJson($command);
        $hash = LicenseCrypto::payloadHash($raw);

        $existing = H1CommandReceipt::query()
            ->where('installation_id', $installation->installation_id)
            ->where('command_id', $commandId)
            ->first();
        if ($existing instanceof H1CommandReceipt) {
            if ($existing->payload_hash !== $hash) {
                return $this->ack($command, 'rejected', 'idempotency_conflict', false);
            }

            $stored = is_array($existing->ack_json) ? $existing->ack_json : [];
            $version = isset($stored['observed_state']['credential_version'])
                ? (int) $stored['observed_state']['credential_version']
                : null;

            return $this->ack($command, 'duplicate', null, false, $version);
        }

        $from = is_array($command['from_credential'] ?? null) ? $command['from_credential'] : [];
        $to = is_array($command['to_credential'] ?? null) ? $command['to_credential'] : [];
        $fromId = (string) ($from['id'] ?? '');
        $fromVersion = (int) ($from['version'] ?? 0);
        $toId = (string) ($to['id'] ?? '');
        $toVersion = (int) ($to['version'] ?? 0);

        if ($fromVersion !== (int) $installation->credential_version || $toVersion !== $fromVersion + 1) {
            return $this->persist($installation, $command, $hash, $this->ack($command, 'rejected', 'credential_version_mismatch', false));
        }

        $auth = is_array($command['authorization'] ?? null) ? $command['authorization'] : [];
        if (($auth['method'] ?? '') === '' || ($auth['authorization_id'] ?? '') === '') {
            return $this->persist($installation, $command, $hash, $this->ack($command, 'rejected', 'invalid_state', false));
        }

        try {
            $secret = $this->secretTransport->fetchReplacementSecret(
                $installation->installation_id,
                $rotationId,
                $fromId,
            );
        } catch (H1CommandException $e) {
            return $this->ack($command, 'failed', $e->errorCode, true);
        }

        return DB::transaction(function () use ($installation, $command, $commandId, $rotationId, $hash, $fromId, $toId, $toVersion, $secret): array {
            $locked = H1Installation::query()->whereKey($installation->getKey())->first();
            if (! $locked instanceof H1Installation) {
                return $this->ack($command, 'failed', 'internal_error', true);
            }

            $old = H1Credential::query()
                ->where('installation_id', $locked->installation_id)
                ->where('credential_id', $fromId)
                ->first();
            if (! $old instanceof H1Credential) {
                return $this->ack($command, 'rejected', 'invalid_state', false);
            }

            $old->status = H1Credential::STATUS_RETIRING;
            $old->save();

            $new = H1Credential::query()->where('credential_id', $toId)->first() ?? new H1Credential;
            $new->installation_id = $locked->installation_id;
            $new->credential_id = $toId;
            $new->version = $toVersion;
            $new->status = H1Credential::STATUS_ACTIVE;
            $new->activated_at = now();
            $new->setSecretBase64Url($secret);
            $new->save();

            $this->sites->resetAllInactive();

            $locked->credential_version = $toVersion;
            $locked->last_command_id = $commandId;
            $locked->rotation_state = [
                'phase' => 'client_applied',
                'rotation_id' => $rotationId,
                'old_credential_id' => $fromId,
                'new_credential_id' => $toId,
                'sites_reset' => true,
            ];
            $locked->save();

            $ack = $this->ack($command, 'applied', null, false, $toVersion);
            $this->persist($locked, $command, $hash, $ack, $rotationId);

            return $ack;
        });
    }

    /**
     * @param  array<string, mixed>  $confirmation
     */
    public function confirmRotation(H1Installation $installation, array $confirmation): void
    {
        $rotationId = (string) ($confirmation['rotation_id'] ?? '');
        $state = is_array($installation->rotation_state) ? $installation->rotation_state : [];
        if ($rotationId === '' || ($state['rotation_id'] ?? null) !== $rotationId) {
            return;
        }
        $oldId = (string) ($state['old_credential_id'] ?? '');
        if ($oldId !== '') {
            H1Credential::query()
                ->where('credential_id', $oldId)
                ->update([
                    'status' => H1Credential::STATUS_RETIRED,
                    'retired_at' => now(),
                    'secret_encrypted' => '', // wipe retired secret material
                ]);
        }
        $installation->rotation_state = null;
        $installation->save();
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, mixed>  $ack
     * @return array<string, mixed>
     */
    private function persist(
        H1Installation $installation,
        array $command,
        string $hash,
        array $ack,
        ?string $rotationId = null,
    ): array {
        if (! Schema::hasTable('h1_command_receipts')) {
            return $ack;
        }
        H1CommandReceipt::query()->updateOrCreate(
            [
                'installation_id' => $installation->installation_id,
                'command_id' => (string) ($command['command_id'] ?? ''),
            ],
            [
                'message_type' => 'rotate_key',
                'payload_hash' => $hash,
                'result' => (string) ($ack['result'] ?? 'rejected'),
                'reason' => $ack['reason'] ?? null,
                'retryable' => (bool) ($ack['retryable'] ?? false),
                'quota_overflow_reset' => false,
                'rotation_id' => $rotationId ?? (string) ($command['rotation_id'] ?? ''),
                'ack_json' => $ack,
                'processed_at' => now(),
            ],
        );

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
        ?int $credentialVersion = null,
    ): array {
        return [
            'protocol' => LicenseProtocol::ID,
            'ack_id' => 'ack_'.substr(hash('sha256', (string) ($command['command_id'] ?? '').$result), 0, 12),
            'command_id' => (string) ($command['command_id'] ?? ''),
            'installation_id' => (string) ($command['installation_id'] ?? ''),
            'product' => (string) ($command['product'] ?? LicenseProtocol::PRODUCT),
            'result' => $result,
            'reason' => $reason,
            'retryable' => $retryable,
            'observed_state' => [
                'entitlement_revision' => null,
                'credential_version' => $credentialVersion ?? (int) (($command['to_credential']['version'] ?? 0)),
                'last_command_id' => (string) ($command['command_id'] ?? ''),
                'quota_overflow_reset' => false,
            ],
            'processed_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }
}
