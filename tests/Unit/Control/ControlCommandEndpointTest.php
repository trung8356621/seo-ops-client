<?php

declare(strict_types=1);

namespace Tests\Unit\Control;

use App\Enums\ClientControlCommandStatus;
use App\Enums\ClientControlStatus;
use App\Models\ClientControlCommand;
use App\Models\Service;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ControlCommandEndpointTest extends TestCase
{
    use InteractsWithClientControl;
    use UsesClientControlSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootClientControlSchema();
    }

    public function test_duplicate_command_id_executes_once(): void
    {
        $this->seedEnrolledState();
        $this->makeRuntimeService('seo-content-ai', false);
        $this->makeRuntimeService('media', true);

        $commandId = (string) Str::uuid();
        $payload = [
            'revision' => 12,
            'mode' => 'replace',
            'active_services' => [
                ['slug' => 'seo-content-ai', 'config' => []],
            ],
        ];

        $first = $this->postControlCommand('services.apply', $payload, commandId: $commandId);
        $first->assertOk();
        $this->assertTrue((bool) Service::query()->where('slug', 'seo-content-ai')->value('is_active'));
        $this->assertFalse((bool) Service::query()->where('slug', 'media')->value('is_active'));

        Service::query()->where('slug', 'media')->update(['is_active' => true]);

        $second = $this->postControlCommand('services.apply', $payload, commandId: $commandId);
        $second->assertOk();
        $second->assertJsonPath('status', ClientControlCommandStatus::Completed->value);
        $this->assertTrue((bool) Service::query()->where('slug', 'media')->value('is_active'));
        $this->assertSame(1, ClientControlCommand::query()->where('command_id', $commandId)->count());
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $this->seedEnrolledState();
        $this->makeRuntimeService('seo-content-ai', true);

        $response = $this->postControlCommand(
            'client.lock',
            [],
            signature: str_repeat('a', 64),
        );

        $response->assertUnauthorized();
        $response->assertJsonPath('error', 'invalid_signature');
        $this->assertSame(ClientControlStatus::Active, $this->currentStatus());
        $this->assertTrue((bool) Service::query()->where('slug', 'seo-content-ai')->value('is_active'));
    }

    public function test_stale_issued_at_is_rejected(): void
    {
        $this->seedEnrolledState();

        $response = $this->postControlCommand(
            'client.lock',
            [],
            issuedAt: now()->utc()->subMinutes(20)->toIso8601String(),
        );

        $response->assertUnauthorized();
        $response->assertJsonPath('error', 'stale');
        $this->assertSame(ClientControlStatus::Active, $this->currentStatus());
    }

    public function test_wrong_installation_id_is_rejected(): void
    {
        $this->seedEnrolledState();

        $response = $this->postControlCommand(
            'client.lock',
            [],
            installationId: '22222222-2222-4222-8222-222222222222',
        );

        $response->assertForbidden();
        $response->assertJsonPath('error', 'wrong_installation');
    }

    public function test_unknown_command_is_rejected_safely(): void
    {
        $this->seedEnrolledState();
        $this->makeRuntimeService('seo-content-ai', true);

        Http::fake();

        $response = $this->postControlCommand('telemetry.configure', ['enabled' => true]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'unknown_command');
        $this->assertTrue((bool) Service::query()->where('slug', 'seo-content-ai')->value('is_active'));
        $this->assertSame(ClientControlStatus::Active, $this->currentStatus());
        Http::assertNothingSent();
    }

    public function test_update_command_never_checks_github_or_latest(): void
    {
        $this->seedEnrolledState();
        Http::fake();

        $response = $this->postControlCommand('client.update', [
            'latest' => true,
            'release' => 'v9.9.9',
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', ClientControlCommandStatus::Ignored->value);
        $response->assertJsonPath('result.state', 'not_configured');
        Http::assertNothingSent();
    }

    public function test_revoked_installation_is_rejected(): void
    {
        $this->seedEnrolledState(ClientControlStatus::Revoked);

        $response = $this->postControlCommand('client.unlock');

        $response->assertForbidden();
        $response->assertJsonPath('error', 'revoked');
    }

    private function currentStatus(): ClientControlStatus
    {
        return \App\Models\ClientControlState::query()->orderBy('id')->firstOrFail()->status;
    }
}
