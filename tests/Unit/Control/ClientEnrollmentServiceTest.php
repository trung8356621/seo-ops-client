<?php

declare(strict_types=1);

namespace Tests\Unit\Control;

use App\Control\ClientEnrollmentService;
use App\Enums\ClientControlStatus;
use App\Models\ClientControlState;
use App\Models\Service;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ClientEnrollmentServiceTest extends TestCase
{
    use InteractsWithClientControl;
    use UsesClientControlSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootClientControlSchema();
    }

    public function test_network_errors_do_not_change_local_entitlement_or_lock_state(): void
    {
        $this->makeRuntimeService('seo-content-ai', true);
        $this->makeRuntimeService('media', false);

        Http::fake(function () {
            throw new ConnectionException('dns failed');
        });

        $result = app(ClientEnrollmentService::class)->enroll(
            'https://ops.example.test',
            'raw-api-key-must-not-be-stored',
        );

        $this->assertFalse($result->ok);
        $this->assertNull($result->installationId);

        $state = ClientControlState::query()->orderBy('id')->first();
        $this->assertTrue(
            $state === null || $state->status === ClientControlStatus::Unregistered,
        );
        $this->assertNull($state?->installation_id);
        $this->assertNull($state?->installation_secret);
        $this->assertDatabaseMissing('client_control_state', [
            'installation_secret' => 'raw-api-key-must-not-be-stored',
        ]);
        $this->assertTrue((bool) Service::query()->where('slug', 'seo-content-ai')->value('is_active'));
        $this->assertFalse((bool) Service::query()->where('slug', 'media')->value('is_active'));
    }

    public function test_http_500_does_not_fake_installation(): void
    {
        Http::fake([
            'https://ops.example.test/*' => Http::response(['error' => 'no'], 500),
        ]);

        $result = app(ClientEnrollmentService::class)->enroll(
            'https://ops.example.test',
            'raw-api-key',
        );

        $this->assertFalse($result->ok);
        $this->assertNull(ClientControlState::query()->orderBy('id')->first()?->installation_id);
    }

    public function test_successful_enrollment_stores_installation_and_drops_api_key(): void
    {
        $this->makeRuntimeService('seo-content-ai', false);
        $this->makeRuntimeService('media', true);

        Http::fake([
            'https://ops.example.test/api/control/v1/enrollments' => Http::response([
                'installation_id' => $this->controlInstallationId,
                'installation_secret' => $this->controlInstallationSecret,
                'status' => 'active',
                'services_revision' => 4,
                'active_services' => [
                    ['slug' => 'seo-content-ai', 'config' => ['ok' => true]],
                ],
            ], 201),
        ]);

        $result = app(ClientEnrollmentService::class)->enroll(
            'https://ops.example.test',
            'raw-api-key-must-not-be-stored',
        );

        $this->assertTrue($result->ok);
        $state = ClientControlState::query()->orderBy('id')->firstOrFail();
        $this->assertSame($this->controlInstallationId, $state->installation_id);
        $this->assertSame($this->controlInstallationSecret, $state->installation_secret);
        $this->assertSame(ClientControlStatus::Active, $state->status);
        $this->assertSame(4, $state->services_revision);
        $this->assertTrue((bool) Service::query()->where('slug', 'seo-content-ai')->value('is_active'));
        $this->assertFalse((bool) Service::query()->where('slug', 'media')->value('is_active'));
        $this->assertDatabaseMissing('client_control_state', [
            'installation_secret' => 'raw-api-key-must-not-be-stored',
        ]);
    }
}
