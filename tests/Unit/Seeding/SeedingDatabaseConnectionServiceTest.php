<?php

declare(strict_types=1);

namespace Tests\Unit\Seeding;

use App\Models\Service;
use App\Models\ServiceDatabaseConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Seeding\Services\SeedingDatabaseConnectionService;
use Omnichannel\Addons\Seeding\Support\SeedingServiceConfig;
use RuntimeException;
use Tests\TestCase;

final class SeedingDatabaseConnectionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('seeding_database_connections');
        Schema::dropIfExists('service_database_connections');
        Schema::dropIfExists('services');

        Schema::create('services', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('addon_namespace')->nullable();
            $table->string('db_connection')->default('mysql');
            $table->boolean('is_active')->default(true);
            $table->json('config')->nullable();
            $table->text('service_key')->nullable();
            $table->timestamps();
        });

        Schema::create('service_database_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('service_id')->unique();
            $table->string('type', 16)->default('manual');
            $table->string('driver', 32)->default('mysql');
            $table->string('host')->nullable();
            $table->string('port', 16)->nullable();
            $table->string('database')->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function test_canonical_service_connection_configures_omi_seeding_not_omi_seo_ai(): void
    {
        Config::set('database.connections.omi_seo_ai.database', 'omi_seo_ai');

        $seeding = Service::query()->create([
            'name' => 'Seeding',
            'slug' => 'seeding',
            'db_connection' => 'omi_seeding',
            'is_active' => true,
        ]);

        $mysql = config('database.connections.mysql');
        ServiceDatabaseConnection::query()->create([
            'service_id' => $seeding->id,
            'type' => 'manual',
            'driver' => 'mysql',
            'host' => $mysql['host'] ?? '127.0.0.1',
            'port' => (string) ($mysql['port'] ?? '3306'),
            'database' => 'omi_seeding',
            'username' => $mysql['username'] ?? 'root',
            'password' => (string) ($mysql['password'] ?? ''),
            'is_active' => true,
        ]);

        $service = app(SeedingDatabaseConnectionService::class);
        $service->bootstrap(forceReconnect: true);

        self::assertSame('omi_seeding', config('database.connections.omi_seeding.database'));
        self::assertSame('omi_seo_ai', config('database.connections.omi_seo_ai.database'));
        self::assertSame(SeedingServiceConfig::CONNECTION, $service->connectionName());
        self::assertNull($service->activeConnection());
        self::assertFalse(Schema::hasTable('seeding_database_connections'));
    }

    public function test_rejects_omi_seo_ai_database_name(): void
    {
        $this->expectException(RuntimeException::class);

        app(SeedingDatabaseConnectionService::class)->testConnectionFromAttributes([
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'omi_seo_ai',
            'username' => 'root',
            'password' => 'x',
        ], 'x');
    }

    public function test_health_reports_canonical_or_env_without_legacy_table(): void
    {
        $service = app(SeedingDatabaseConnectionService::class);
        $health = $service->healthCheck();

        self::assertSame('omi_seeding', $health['connection']);
        self::assertArrayHasKey('configured', $health);
        self::assertArrayHasKey('reachable', $health);
        self::assertFalse(Schema::hasTable('seeding_database_connections'));
        self::assertNotSame('legacy_seeding', $health['source'] ?? '');
    }
}
