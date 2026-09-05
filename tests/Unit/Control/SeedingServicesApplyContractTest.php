<?php

declare(strict_types=1);

namespace Tests\Unit\Control;

use App\Control\Commands\Handlers\ServicesApplyHandler;
use App\Models\Service;
use Omnichannel\Addons\Seeding\Support\SeedingServiceConfig;
use Omnichannel\Addons\Seeding\Support\SeedingServiceResolver;
use Tests\TestCase;

final class SeedingServicesApplyContractTest extends TestCase
{
    use InteractsWithClientControl;
    use UsesClientControlSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootClientControlSchema();
        $this->seedEnrolledState();
    }

    public function test_services_apply_activates_seeding_with_config(): void
    {
        $this->makeRuntimeService('seeding', false, ['old' => true]);
        Service::query()->where('slug', 'seeding')->update([
            'db_connection' => SeedingServiceConfig::CONNECTION,
        ]);

        $result = app(ServicesApplyHandler::class)->handle([
            'revision' => 21,
            'mode' => 'replace',
            'active_services' => [
                [
                    'slug' => 'seeding',
                    'config' => [
                        'enabled' => true,
                        'database' => [
                            'connection' => 'omi_seeding',
                            'database' => 'omi_seeding',
                        ],
                    ],
                ],
            ],
        ]);

        self::assertNull($result->error);
        self::assertContains('seeding', $result->result['activated'] ?? []);
        $row = Service::query()->where('slug', 'seeding')->first();
        self::assertTrue((bool) $row?->is_active);
        self::assertSame('omi_seeding', $row?->config['database']['connection'] ?? null);
        self::assertTrue(app(SeedingServiceResolver::class)->isActive());
    }

    public function test_services_apply_deactivates_seeding_when_omitted(): void
    {
        $this->makeRuntimeService('seeding', true, ['enabled' => true]);
        $this->makeRuntimeService('seo-content-ai', true);

        $result = app(ServicesApplyHandler::class)->handle([
            'revision' => 22,
            'mode' => 'replace',
            'active_services' => [
                ['slug' => 'seo-content-ai', 'config' => []],
            ],
        ]);

        self::assertNull($result->error);
        self::assertContains('seeding', $result->result['deactivated'] ?? []);
        self::assertFalse((bool) Service::query()->where('slug', 'seeding')->value('is_active'));
        self::assertFalse(app(SeedingServiceResolver::class)->isActive());
    }

    public function test_seeding_resolver_never_uses_omi_seo_ai_database_name(): void
    {
        config([
            'database.connections.omi_seeding.database' => 'omi_seo_ai',
        ]);

        $resolved = app(SeedingServiceResolver::class)->resolve();
        self::assertSame('omi_seeding', $resolved->database['connection']);
        self::assertSame('omi_seeding', $resolved->database['database']);
        self::assertNotSame('omi_seo_ai', $resolved->database['database']);
    }
}
