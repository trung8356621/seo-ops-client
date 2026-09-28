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
