<?php

declare(strict_types=1);

namespace Tests\Unit\Control;

use App\Control\Commands\Handlers\ServicesApplyHandler;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ServiceKeyApplyContractTest extends TestCase
{
    use InteractsWithClientControl;
    use UsesClientControlSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootClientControlSchema();
        $this->seedEnrolledState();
    }

    public function test_service_key_encrypted_hidden_and_stored(): void
    {
        $svc = $this->makeRuntimeService('seeding', false);
        $result = app(ServicesApplyHandler::class)->handle([
            'revision' => 40,
            'mode' => 'replace',
            'active_services' => [
                [
                    'slug' => 'seeding',
                    'service_key' => 'secret-key-alpha',
                    'config' => ['enabled' => true],
                ],
            ],
        ]);

        self::assertNull($result->error);
        $row = Service::query()->where('slug', 'seeding')->first();
        self::assertTrue($row?->hasServiceKey());
        self::assertSame('secret-key-alpha', $row?->service_key);
        self::assertArrayNotHasKey('service_key', $row?->toArray() ?? []);

        $raw = DB::table('services')->where('slug', 'seeding')->value('service_key');
        self::assertNotSame('secret-key-alpha', $raw);
        self::assertNotNull($raw);
    }

    public function test_legacy_payload_without_key_keeps_existing(): void
    {
        $svc = $this->makeRuntimeService('seeding', true, ['enabled' => true]);
        $svc->forceFill(['service_key' => 'keep-me'])->save();

        $result = app(ServicesApplyHandler::class)->handle([
            'revision' => 41,
            'mode' => 'replace',
            'active_services' => [
                ['slug' => 'seeding', 'config' => ['enabled' => true]],
            ],
        ]);

        self::assertNull($result->error);
        self::assertSame('keep-me', Service::query()->where('slug', 'seeding')->first()?->service_key);
    }

    public function test_key_rotation(): void
    {
        $this->makeRuntimeService('seeding', true);
        app(ServicesApplyHandler::class)->handle([
            'revision' => 42,
            'mode' => 'replace',
            'active_services' => [
                ['slug' => 'seeding', 'service_key' => 'v1', 'config' => []],
            ],
        ]);
        app(ServicesApplyHandler::class)->handle([
            'revision' => 43,
            'mode' => 'replace',
            'active_services' => [
                ['slug' => 'seeding', 'service_key' => 'v2', 'config' => []],
            ],
        ]);

        self::assertSame('v2', Service::query()->where('slug', 'seeding')->first()?->service_key);
    }

    public function test_deactivation_clears_service_key(): void
    {
        $this->makeRuntimeService('seeding', true);
        $this->makeRuntimeService('seo-content-ai', true);

        app(ServicesApplyHandler::class)->handle([
            'revision' => 44,
            'mode' => 'replace',
            'active_services' => [
                ['slug' => 'seeding', 'service_key' => 'will-clear', 'config' => []],
                ['slug' => 'seo-content-ai', 'service_key' => 'seo-key', 'config' => []],
            ],
        ]);

        app(ServicesApplyHandler::class)->handle([
            'revision' => 45,
            'mode' => 'replace',
            'active_services' => [
                ['slug' => 'seo-content-ai', 'config' => []],
            ],
        ]);

        $seeding = Service::query()->where('slug', 'seeding')->first();
        self::assertFalse((bool) $seeding?->is_active);
        self::assertFalse($seeding?->hasServiceKey());
        self::assertTrue(Service::query()->where('slug', 'seo-content-ai')->first()?->hasServiceKey());
    }

    public function test_service_key_in_config_json_is_stripped(): void
    {
        $this->makeRuntimeService('seeding', false);
        app(ServicesApplyHandler::class)->handle([
            'revision' => 46,
            'mode' => 'replace',
            'active_services' => [
                [
                    'slug' => 'seeding',
                    'service_key' => 'real-key',
                    'config' => ['service_key' => 'should-not-land', 'enabled' => true],
                ],
            ],
        ]);

        $row = Service::query()->where('slug', 'seeding')->first();
        self::assertSame('real-key', $row?->service_key);
        self::assertArrayNotHasKey('service_key', $row?->config ?? []);
    }
}
