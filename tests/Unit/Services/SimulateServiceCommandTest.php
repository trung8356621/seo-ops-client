<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Service;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Seeding\Support\SeedingServiceConfig;
use Omnichannel\Addons\Seeding\Support\SeedingServiceResolver;
use Tests\TestCase;

final class SimulateServiceCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'local';

        Schema::dropIfExists('services');
        Schema::create('services', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('addon_namespace');
            $table->string('db_connection')->default('mysql');
            $table->boolean('is_active')->default(true);
            $table->json('config')->nullable();
            $table->text('service_key')->nullable();
            $table->timestamps();
        });
    }

    public function test_simulate_seeding_activates_without_deactivating_existing(): void
    {
        $seo = $this->makeRuntimeService('seo-content-ai', true, ['enabled' => true]);
        $media = $this->makeRuntimeService('media', true);

        Service::query()->create([
            'name' => 'Seeding',
            'slug' => SeedingServiceResolver::SLUG,
            'addon_namespace' => \Omnichannel\Addons\Seeding\SeedingServiceProvider::class,
            'db_connection' => SeedingServiceConfig::CONNECTION,
            'is_active' => false,
            'config' => ['enabled' => false],
        ]);

        $this->artisan('service:simulate', ['slug' => 'seeding'])
            ->assertSuccessful();

        self::assertTrue((bool) Service::query()->where('slug', 'seo-content-ai')->value('is_active'));
        self::assertTrue((bool) Service::query()->where('slug', 'media')->value('is_active'));
        self::assertTrue((bool) Service::query()->where('slug', 'seeding')->value('is_active'));
        self::assertSame(
            SeedingServiceConfig::CONNECTION,
            Service::query()->where('slug', 'seeding')->value('db_connection'),
        );

        // Ensure previously active rows were not flipped off.
        self::assertSame($seo->id, Service::query()->where('slug', 'seo-content-ai')->value('id'));
        self::assertSame($media->id, Service::query()->where('slug', 'media')->value('id'));
    }

    public function test_simulate_seeding_is_idempotent(): void
    {
        Service::query()->create([
            'name' => 'Seeding',
            'slug' => SeedingServiceResolver::SLUG,
            'addon_namespace' => \Omnichannel\Addons\Seeding\SeedingServiceProvider::class,
            'db_connection' => SeedingServiceConfig::CONNECTION,
            'is_active' => false,
            'config' => [],
        ]);
        $this->makeRuntimeService('seo-content-ai', true);

        $this->artisan('service:simulate', ['slug' => 'seeding'])->assertSuccessful();
        $this->artisan('service:simulate', ['slug' => 'seeding'])->assertSuccessful();

        self::assertSame(1, Service::query()->where('slug', 'seeding')->count());
        self::assertTrue((bool) Service::query()->where('slug', 'seeding')->value('is_active'));
        self::assertTrue((bool) Service::query()->where('slug', 'seo-content-ai')->value('is_active'));
    }

    public function test_simulate_refused_in_production_without_force(): void
    {
        $this->app['env'] = 'production';

        Service::query()->create([
            'name' => 'Seeding',
            'slug' => SeedingServiceResolver::SLUG,
            'addon_namespace' => \Omnichannel\Addons\Seeding\SeedingServiceProvider::class,
            'db_connection' => SeedingServiceConfig::CONNECTION,
            'is_active' => false,
            'config' => [],
        ]);

        $this->artisan('service:simulate', ['slug' => 'seeding'])
            ->assertFailed();

        self::assertFalse((bool) Service::query()->where('slug', 'seeding')->value('is_active'));
    }

    public function test_simulate_all_discovers_and_activates_addons(): void
    {
        $this->artisan('service:simulate', ['--all' => true])
            ->assertSuccessful();

        $activeServices = Service::query()->where('is_active', true)->get();
        self::assertGreaterThanOrEqual(1, $activeServices->count());

        foreach ($activeServices as $service) {
            self::assertTrue((bool) $service->is_active);
            self::assertNotEmpty($service->service_key);
        }

        $skipSlugs = config('addons.skip_slugs', []);
        foreach ($skipSlugs as $skipSlug) {
            self::assertNull(
                Service::query()->where('slug', $skipSlug)->where('is_active', true)->first(),
                "Skipped slug [{$skipSlug}] should not be active."
            );
        }
    }

    public function test_simulate_preserves_existing_service_key_and_generates_missing(): void
    {
        $existingKey = 'custom-preexisting-key-12345';
        Service::query()->create([
            'name' => 'Existing Service',
            'slug' => 'seo-content-ai',
            'addon_namespace' => 'App\\Addons\\Fake\\seo-content-ai',
            'db_connection' => 'mysql',
            'is_active' => false,
            'service_key' => $existingKey,
        ]);

        $this->artisan('service:simulate', ['slug' => 'seo-content-ai'])
            ->assertSuccessful();

        $service = Service::query()->where('slug', 'seo-content-ai')->firstOrFail();
        self::assertTrue((bool) $service->is_active);
        self::assertSame($existingKey, $service->service_key); // Key was NOT rotated

        // Now test another slug with null key gets generated
        Service::query()->where('slug', 'media')->update(['is_active' => false, 'service_key' => null]);
        if (! Service::query()->where('slug', 'media')->exists()) {
            Service::query()->create([
                'name' => 'Fresh Service',
                'slug' => 'media',
                'addon_namespace' => 'App\\Addons\\Fake\\media',
                'db_connection' => 'mysql',
                'is_active' => false,
                'service_key' => null,
            ]);
        }

        $this->artisan('service:simulate', ['slug' => 'media'])
            ->assertSuccessful();

        $fresh = Service::query()->where('slug', 'media')->firstOrFail();
        self::assertTrue((bool) $fresh->is_active);
        self::assertNotNull($fresh->service_key);
        self::assertStringStartsWith('local-fixture-', (string) $fresh->service_key);
    }

    public function test_simulate_unknown_slug_fails(): void
    {
        $this->artisan('service:simulate', ['slug' => 'completely-unknown-addon-slug'])
            ->assertFailed();
    }

    public function test_simulate_fails_clearly_when_services_table_missing(): void
    {
        Schema::dropIfExists('services');

        $this->artisan('service:simulate', ['--all' => true])
            ->assertFailed();

        $this->artisan('service:simulate', ['slug' => 'seeding'])
            ->assertFailed();
    }

    public function test_simulate_force_with_env_succeeds_outside_local(): void
    {
        $this->app['env'] = 'production';
        putenv('SERVICE_SIMULATE_FORCE=1');

        try {
            $this->artisan('service:simulate', ['slug' => 'seeding', '--force' => true])
                ->assertSuccessful();

            self::assertTrue((bool) Service::query()->where('slug', 'seeding')->value('is_active'));
        } finally {
            putenv('SERVICE_SIMULATE_FORCE');
        }
    }

    private function makeRuntimeService(string $slug, bool $active = true, array $config = []): Service
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
