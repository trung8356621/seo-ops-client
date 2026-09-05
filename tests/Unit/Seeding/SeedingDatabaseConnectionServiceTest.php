<?php

declare(strict_types=1);

namespace Tests\Unit\Seeding;

use App\Models\SeedingDatabaseConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
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
        Schema::create('seeding_database_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type', 16)->default('manual');
            $table->string('host')->nullable();
            $table->string('port', 16)->nullable();
            $table->string('database')->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function test_password_is_encrypted_at_rest(): void
    {
        $row = SeedingDatabaseConnection::query()->create([
            'name' => 'Local',
            'type' => 'manual',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'omi_seeding',
            'username' => 'root',
            'password' => 'secret-pass',
            'is_active' => true,
        ]);

        $raw = DB::table('seeding_database_connections')->where('id', $row->id)->value('password');
        self::assertNotSame('secret-pass', $raw);
        self::assertSame('secret-pass', $row->fresh()->password);
    }

    public function test_resolver_configures_omi_seeding_not_omi_seo_ai(): void
    {
        Config::set('database.connections.omi_seo_ai.database', 'omi_seo_ai');

        SeedingDatabaseConnection::query()->create([
            'name' => 'Local',
            'type' => 'manual',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'omi_seeding',
            'username' => 'root',
            'password' => 'x',
            'is_active' => true,
        ]);

        $service = app(SeedingDatabaseConnectionService::class);
        $service->bootstrap(forceReconnect: true);

        self::assertSame('omi_seeding', config('database.connections.omi_seeding.database'));
        self::assertSame('omi_seo_ai', config('database.connections.omi_seo_ai.database'));
        self::assertSame(SeedingServiceConfig::CONNECTION, $service->connectionName());
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

    public function test_invalid_connection_is_unhealthy(): void
    {
        SeedingDatabaseConnection::query()->create([
            'name' => 'Bad',
            'type' => 'manual',
            'host' => '127.0.0.1',
            'port' => '1',
            'database' => 'omi_seeding_missing_xyz',
            'username' => 'no_such_user',
            'password' => 'bad',
            'is_active' => true,
        ]);

        $health = app(SeedingDatabaseConnectionService::class)->healthCheck();

        self::assertTrue($health['configured']);
        self::assertFalse($health['reachable']);
        self::assertSame('omi_seeding', $health['connection']);
    }

    public function test_reachable_env_fallback_reports_health_without_tables(): void
    {
        // Prefer existing default mysql credentials if DB is up; otherwise assert structure only.
        $service = app(SeedingDatabaseConnectionService::class);
        $health = $service->healthCheck();

        self::assertSame('omi_seeding', $health['connection']);
        self::assertArrayHasKey('configured', $health);
        self::assertArrayHasKey('reachable', $health);
        self::assertArrayHasKey('database', $health);
        // No business tables required — health does not check Schema::hasTable for topics.
        self::assertArrayNotHasKey('tables', $health);
    }
}
