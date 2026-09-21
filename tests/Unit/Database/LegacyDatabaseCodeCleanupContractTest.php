<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Agent\Extension\ExtensionStateStore;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Seeding\Services\SeedingDatabaseConnectionService;
use App\Models\Service;
use App\Models\ServiceDatabaseConnection;
use App\Services\ServiceDatabaseConnectionResolver;
use App\Services\ServiceIdentity;
use Tests\TestCase;

/**
 * Runtime contracts for retired legacy credential / extension-state DB paths.
 * Uses disposable schema only — never migrate:fresh on real DB.
 */
final class LegacyDatabaseCodeCleanupContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('seo_database_connections');
        Schema::dropIfExists('seo_connection_users');
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

    public function test_canonical_seo_resolver_does_not_require_seo_database_connections(): void
    {
        self::assertFalse(Schema::hasTable('seo_database_connections'));

        $seo = Service::query()->create([
            'name' => 'SEO',
            'slug' => 'seo-content-ai',
            'db_connection' => 'omi_seo_ai',
            'is_active' => true,
        ]);

        $mysql = config('database.connections.mysql');
        ServiceDatabaseConnection::query()->create([
            'service_id' => $seo->id,
            'type' => 'manual',
            'driver' => 'mysql',
            'host' => $mysql['host'] ?? '127.0.0.1',
            'port' => (string) ($mysql['port'] ?? '3306'),
            'database' => 'omi_seo_ai',
            'username' => $mysql['username'] ?? 'root',
            'password' => (string) ($mysql['password'] ?? ''),
            'is_active' => true,
        ]);

        $resolver = app(ServiceDatabaseConnectionResolver::class);
        $row = $resolver->resolve(ServiceIdentity::PUBLIC_SEO);

        self::assertInstanceOf(ServiceDatabaseConnection::class, $row);
        self::assertSame('omi_seo_ai', $row->database);
        self::assertFalse(Schema::hasTable('seo_database_connections'));
    }

    public function test_seo_shared_bootstrap_uses_canonical_without_legacy_table(): void
    {
        self::assertFalse(Schema::hasTable('seo_database_connections'));

        $seo = Service::query()->create([
            'name' => 'SEO',
            'slug' => 'seo-content-ai',
            'db_connection' => 'omi_seo_ai',
            'is_active' => true,
        ]);

        $mysql = config('database.connections.mysql');
        ServiceDatabaseConnection::query()->create([
            'service_id' => $seo->id,
            'type' => 'manual',
            'driver' => 'mysql',
            'host' => $mysql['host'] ?? '127.0.0.1',
            'port' => (string) ($mysql['port'] ?? '3306'),
            'database' => 'omi_seo_ai',
            'username' => $mysql['username'] ?? 'root',
            'password' => (string) ($mysql['password'] ?? ''),
            'is_active' => true,
        ]);

        $adapter = app(SeoDatabaseConnectionService::class)->bootstrapCanonicalSharedConnection();

        self::assertNotNull($adapter);
        self::assertSame(0, (int) $adapter->getKey());
        self::assertSame('omi_seo_ai', (string) $adapter->database);
        self::assertFalse(Schema::hasTable('seo_database_connections'));
    }

    public function test_seeding_bootstrap_does_not_query_seeding_database_connections(): void
    {
        self::assertFalse(Schema::hasTable('seeding_database_connections'));

        $service = app(SeedingDatabaseConnectionService::class);
        self::assertNull($service->activeConnection());

        $src = (string) file_get_contents(base_path('addons/seeding/src/Services/SeedingDatabaseConnectionService.php'));
        self::assertStringNotContainsString('SeedingDatabaseConnection::query()', $src);
        self::assertStringNotContainsString("hasTable('seeding_database_connections')", $src);

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

        $service->bootstrap(forceReconnect: true);
        self::assertSame('omi_seeding', config('database.connections.omi_seeding.database'));
        self::assertFalse(Schema::hasTable('seeding_database_connections'));
    }

    public function test_extension_state_store_works_with_cache_only(): void
    {
        Cache::flush();
        $store = new ExtensionStateStore;

        self::assertTrue($store->isEnabled('demo.ext'));
        $store->setEnabled('demo.ext', false);
        self::assertFalse($store->isEnabled('demo.ext'));

        $store->setHealth('demo.ext', ['ok' => false, 'status' => 'error', 'message' => 'x']);
        self::assertSame('error', $store->getStatus('demo.ext'));
        self::assertSame('x', $store->getHealthPayload('demo.ext')['message'] ?? null);

        $src = (string) file_get_contents(base_path('addons/agent/src/Extension/ExtensionStateStore.php'));
        self::assertDoesNotMatchRegularExpression("/->table\\(\\s*['\"]seo_extension_states['\"]/", $src);
        self::assertDoesNotMatchRegularExpression("/hasTable\\(\\s*['\"]seo_extension_states['\"]/", $src);
    }

    public function test_tombstone_migration_drops_legacy_tables_idempotently_and_keeps_service_connections(): void
    {
        Schema::create('seo_database_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        Schema::create('seo_connection_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('connection_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });
        Schema::create('seeding_database_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        $seo = Service::query()->create([
            'name' => 'SEO',
            'slug' => 'seo-content-ai',
            'db_connection' => 'omi_seo_ai',
            'is_active' => true,
        ]);
        ServiceDatabaseConnection::query()->create([
            'service_id' => $seo->id,
            'type' => 'manual',
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'omi_seo_ai',
            'username' => 'root',
            'password' => '',
            'is_active' => true,
        ]);

        $migration = require database_path('migrations/2026_09_22_100000_drop_retired_legacy_service_db_credential_tables.php');
        $migration->up();
        $migration->up(); // idempotent

        self::assertFalse(Schema::hasTable('seo_database_connections'));
        self::assertFalse(Schema::hasTable('seo_connection_users'));
        self::assertFalse(Schema::hasTable('seeding_database_connections'));
        self::assertTrue(Schema::hasTable('service_database_connections'));
        self::assertSame(1, ServiceDatabaseConnection::query()->count());
    }

    public function test_service_database_connections_remains_canonical_in_ownership_config(): void
    {
        $ownership = config('database_table_ownership.connections.mysql.tables')
            ?? config('database_table_ownership');

        // Flatten search — ownership structure may nest.
        $encoded = json_encode(config('database_table_ownership'));
        self::assertIsString($encoded);
        self::assertStringContainsString('service_database_connections', $encoded);
        self::assertStringNotContainsString('seo_database_connections', $encoded);
        self::assertStringNotContainsString('seeding_database_connections', $encoded);
    }
}
