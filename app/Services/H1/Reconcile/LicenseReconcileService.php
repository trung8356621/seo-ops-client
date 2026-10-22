<?php

declare(strict_types=1);

namespace App\Services\H1\Reconcile;

use App\Models\H1Installation;
use App\Services\H1\Commands\EntitlementCommandApplier;
use App\Services\H1\Commands\RotationCommandApplier;
use App\Services\H1\Exceptions\H1TransportException;
use App\Services\H1\Persistence\InstallationStateRepository;
use App\Services\H1\Protocol\LicenseCrypto;
use App\Services\H1\Protocol\LicenseProtocol;
use App\Services\H1\Protocol\SignedHttpTransport;
use App\Services\H1\Sites\AdminSiteStatusPolicy;

final class LicenseReconcileService
{
    public function __construct(
        private readonly InstallationStateRepository $installations,
        private readonly SignedHttpTransport $http,
        private readonly EntitlementCommandApplier $applier,
        private readonly RotationCommandApplier $rotation,
        private readonly AdminSiteStatusPolicy $sites,
    ) {}

    /**
     * @return array{commands_applied: int, acks: list<array<string, mixed>>, offline: bool}
     */
    public function reconcile(?array $mockedResponse = null): array
    {
        $installation = $this->installations->currentInstallation();
        $credential = $this->installations->activeCredential();
        if (! $installation instanceof H1Installation || $credential === null) {
            throw new H1TransportException('not_enrolled', 'Installation is not enrolled.');
        }

        $acks = is_array($installation->pending_acks) ? $installation->pending_acks : [];
        $requestId = 'req_'.bin2hex(random_bytes(6));
        $payload = [
            'protocol' => LicenseProtocol::ID,
            'request_id' => $requestId,
            'installation_id' => $installation->installation_id,
            'product' => (string) $installation->product,
            'credential' => [
                'id' => $credential->credential_id,
                'version' => (int) $credential->version,
            ],
            'observed_state' => [
                'entitlement_revision' => (int) $installation->entitlement_revision,
                'credential_version' => (int) $installation->credential_version,
                'last_command_id' => $installation->last_command_id,
            ],
            'client' => [
                'version' => (string) ($installation->client_version ?: config('app.version', '0.0.0')),
            ],
            'usage_summary' => [
                'active_site_count' => $this->sites->activeCount(),
                'anchor_status' => $this->anchorStatus($installation),
            ],
            'acknowledgments' => $acks,
        ];

        $rawBody = LicenseCrypto::canonicalJson($payload);
        $path = str_replace(
            '{installation_id}',
            rawurlencode($installation->installation_id),
            (string) config('h1-license.reconcile.path_template'),
        );

        if ($mockedResponse !== null) {
            $decoded = $mockedResponse;
        } else {
            $base = filled($installation->control_server_url)
                ? rtrim((string) $installation->control_server_url, '/')
                : app(\App\Services\H1\Persistence\InstallationStateRepository::class)->getEffectiveControlServerUrl();

            try {
                $secret = $credential->secretBase64Url();
                $result = $this->http->postSigned(
                    $base.$path,
                    $path,
                    $rawBody,
                    $credential->credential_id,
                    $secret,
                );
                $decoded = $result['decoded'];
            } catch (H1TransportException $e) {
                if ($e->errorCode === 'server_offline') {
                    return ['commands_applied' => 0, 'acks' => $acks, 'offline' => true];
                }
                throw $e;
            }
        }

        $commands = is_array($decoded['commands'] ?? null) ? $decoded['commands'] : [];
        $newAcks = [];
        $applied = 0;
        foreach ($commands as $command) {
            if (! is_array($command)) {
                continue;
            }
            $ack = $this->applier->apply($command);
            $newAcks[] = $ack;
            if (($ack['result'] ?? '') === 'applied') {
                $applied++;
            }
        }

        if (isset($decoded['rotation_confirmation']) && is_array($decoded['rotation_confirmation'])) {
            $fresh = $this->installations->currentInstallation();
            if ($fresh instanceof H1Installation) {
                $this->rotation->confirmRotation($fresh, $decoded['rotation_confirmation']);
            }
        }

        $fresh = $this->installations->currentInstallation();
        if ($fresh instanceof H1Installation) {
            $pending = array_values(array_filter(
                $newAcks,
                static fn (array $ack): bool => in_array(($ack['result'] ?? ''), ['applied', 'duplicate'], true),
            ));
            $fresh->pending_acks = $pending;
            $fresh->save();
        }

        return [
            'commands_applied' => $applied,
            'acks' => $newAcks,
            'offline' => false,
        ];
    }

    private function anchorStatus(H1Installation $installation): string
    {
        if ($installation->anchor_site_id === null) {
            return 'unbound';
        }
        $site = \App\Models\Site::query()->find($installation->anchor_site_id);
        if (! $site) {
            return 'missing';
        }

        return (string) $site->status;
    }
}
