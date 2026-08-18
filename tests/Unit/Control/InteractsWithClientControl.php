<?php

declare(strict_types=1);

namespace Tests\Unit\Control;

use App\Control\Signing\ControlRequestSigner;
use App\Enums\ClientControlStatus;
use App\Models\ClientControlState;
use App\Models\Service;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

trait InteractsWithClientControl
{
    protected string $controlInstallationId = '11111111-1111-4111-8111-111111111111';

    protected string $controlInstallationSecret = 'test-installation-secret';

    protected function seedEnrolledState(
        ClientControlStatus $status = ClientControlStatus::Active,
        ?string $secret = null,
        ?string $installationId = null,
    ): ClientControlState {
        ClientControlState::query()->delete();

        return ClientControlState::query()->create([
            'installation_id' => $installationId ?? $this->controlInstallationId,
            'control_server_url' => 'https://ops.example.test',
            'installation_secret' => $secret ?? $this->controlInstallationSecret,
            'status' => $status,
            'connected_at' => now(),
            'client_version' => '0.0.9',
            'locked_at' => $status === ClientControlStatus::Locked ? now() : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function postControlCommand(
        string $command,
        array $payload = [],
        ?string $secret = null,
        ?string $commandId = null,
        ?string $installationId = null,
        ?string $issuedAt = null,
        ?string $signature = null,
    ): TestResponse {
        $commandId ??= (string) Str::uuid();
        $installationId ??= $this->controlInstallationId;
        $issuedAt ??= now()->utc()->toIso8601String();
        $secret ??= $this->controlInstallationSecret;
        $signer = new ControlRequestSigner;
        $computed = $signer->sign($secret, $installationId, $commandId, $issuedAt, $command, $payload);

        return $this->postJson('/api/control/v1/commands', [
            'installation_id' => $installationId,
            'command_id' => $commandId,
            'issued_at' => $issuedAt,
            'command' => $command,
            'payload' => $payload,
        ], [
            (string) config('client_control.signature_header') => $signature ?? $computed,
        ]);
    }

    protected function makeRuntimeService(string $slug, bool $active = true, array $config = []): Service
    {
        return Service::query()->create([
            'name' => $slug,
            'slug' => $slug,
            'addon_namespace' => 'App\\Addons\\Fake\\'.$slug,
            'db_connection' => 'mysql',
            'is_active' => $active,
            'config' => $config,
        ]);
    }
}
