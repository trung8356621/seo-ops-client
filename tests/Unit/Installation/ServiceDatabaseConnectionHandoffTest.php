<?php

declare(strict_types=1);

namespace Tests\Unit\Installation;

use App\Core\Installation\ServiceDatabaseConnectionHandoff;
use App\Models\Service;
use App\Models\ServiceDatabaseConnection;
use App\Services\ServiceDatabaseConnectionResolver;
use App\Services\ServiceIdentity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ServiceDatabaseConnectionHandoffTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTables();
    }

    public function test_fresh_setup_handoff_persists_all_three_service_dbs_for_admin_health(): void
    {
        $this->seedCatalogServices();
        $this->configureLogicalConnections([
            'omi_seo_ai' => ['host' => '127.0.0.1', 'port' => '3306', 'database' => 'omi_seo_ai_setup', 'username' => 'seo_user', 'password' => 'seo-secret'],
            'omi_seeding' => ['host' => '10.0.0.2', 'port' => '3307', 'database' => 'omi_seeding_setup', 'username' => 'seed_user', 'password' => 'seed-secret'],
            'omi_social_automation' => ['host' => '10.0.0.3', 'port' => '3308', 'database' => 'omi_sa_setup', 'username' => 'sa_user', 'password' => ''],
        ]);

        $report = app(ServiceDatabaseConnectionHandoff::class)->ensureFromConfiguredConnections();

        self::assertSame(
            [ServiceIdentity::PUBLIC_SEO, ServiceIdentity::PUBLIC_SEEDING, ServiceIdentity::PUBLIC_SOCIAL_AUTOMATION],
            $report['created'],
        );
        self::assertSame([], $report['skipped']);

        $resolver = app(ServiceDatabaseConnectionResolver::class);
        foreach ([
            ServiceIdentity::PUBLIC_SEO => ['host' => '127.0.0.1', 'port' => '3306', 'database' => 'omi_seo_ai_setup', 'username' => 'seo_user', 'password' => 'seo-secret'],
            ServiceIdentity::PUBLIC_SEEDING => ['host' => '10.0.0.2', 'port' => '3307', 'database' => 'omi_seeding_setup', 'username' => 'seed_user', 'password' => 'seed-secret'],
            ServiceIdentity::PUBLIC_SOCIAL_AUTOMATION => ['host' => '10.0.0.3', 'port' => '3308', 'database' => 'omi_sa_setup', 'username' => 'sa_user', 'password' => null],
        ] as $slug => $expected) {
            $row = $resolver->resolve($slug);
            self::assertInstanceOf(ServiceDatabaseConnection::class, $row);
            self::assertSame($expected['host'], (string) $row->host);
            self::assertSame($expected['port'], (string) $row->port);
            self::assertSame($expected['database'], (string) $row->database);
            self::assertSame($expected['username'], (string) $row->username);
            self::assertSame($expected['password'], $row->password);

            $health = $resolver->healthReport($slug);
            self::assertTrue($health['database_configured']);
            self::assertSame($expected['database'], $health['database']);
            self::assertSame($expected['host'], $health['host']);
            self::assertSame($expected['username'], $health['username']);
            self::assertSame('canonical', $health['connection_source']);
        }
    }

    public function test_handoff_is_idempotent_and_does_not_overwrite_existing(): void
    {
        $this->seedCatalogServices();
        $this->configureLogicalConnections([
            'omi_seo_ai' => ['host' => '127.0.0.1', 'port' => '3306', 'database' => 'omi_seo_ai_setup', 'username' => 'seo_user', 'password' => 'first'],
            'omi_seeding' => ['host' => '127.0.0.1', 'port' => '3306', 'database' => 'omi_seeding_setup', 'username' => 'seed_user', 'password' => 'first'],
            'omi_social_automation' => ['host' => '127.0.0.1', 'port' => '3306', 'database' => 'omi_sa_setup', 'username' => 'sa_user', 'password' => 'first'],
        ]);

        app(ServiceDatabaseConnectionHandoff::class)->ensureFromConfiguredConnections();

        $this->configureLogicalConnections([
            'omi_seo_ai' => ['host' => '9.9.9.9', 'port' => '9999', 'database' => 'changed', 'username' => 'changed', 'password' => 'second'],
            'omi_seeding' => ['host' => '9.9.9.9', 'port' => '9999', 'database' => 'changed', 'username' => 'changed', 'password' => 'second'],
            'omi_social_automation' => ['host' => '9.9.9.9', 'port' => '9999', 'database' => 'changed', 'username' => 'changed', 'password' => 'second'],
        ]);

        $report = app(ServiceDatabaseConnectionHandoff::class)->ensureFromConfiguredConnections();
        self::assertSame([], $report['created']);
        self::assertCount(3, $report['skipped']);

        $seo = app(ServiceDatabaseConnectionResolver::class)->resolve(ServiceIdentity::PUBLIC_SEO);
        self::assertSame('omi_seo_ai_setup', $seo?->database);
        self::assertSame('seo_user', $seo?->username);
        self::assertSame('first', $seo?->password);
        self::assertSame('127.0.0.1', $seo?->host);
    }

    public function test_handoff_sets_logical_db_connection_on_service_row(): void
    {
        Service::query()->create([
            'name' => 'SEO',
            'slug' => 'seo-content-ai',
            'db_connection' => 'mysql',
            'is_active' => false,
        ]);
        $this->configureLogicalConnections([
            'omi_seo_ai' => ['host' => '127.0.0.1', 'port' => '3306', 'database' => 'omi_seo_ai_setup', 'username' => 'seo_user', 'password' => 'x'],
        ]);

        app(ServiceDatabaseConnectionHandoff::class)->ensureFromConfiguredConnections();

        self::assertSame('omi_seo_ai', Service::query()->where('slug', 'seo-content-ai')->value('db_connection'));
    }

    /**
     * @param  array<string, array{host: string, port: string, database: string, username: string, password: string}>  $map
     */
    private function configureLogicalConnections(array $map): void
    {
        foreach ($map as $logical => $cfg) {
            Config::set('database.connections.'.$logical, array_merge([
                'driver' => 'mysql',
            ], $cfg));
        }
    }

    private function seedCatalogServices(): void
    {
        Service::query()->create([
            'name' => 'SEO',
            'slug' => 'seo-content-ai',
            'db_connection' => 'omi_seo_ai',
            'is_active' => false,
        ]);
        Service::query()->create([
            'name' => 'Seeding',
            'slug' => 'seeding',
            'db_connection' => 'omi_seeding',
            'is_active' => false,
        ]);
        Service::query()->create([
            'name' => 'Social Automation',
            'slug' => 'social-automation',
            'db_connection' => 'omi_social_automation',
            'is_active' => false,
        ]);
    }

    private function bootTables(): void
    {
        Schema::dropIfExists('service_database_connections');
        Schema::dropIfExists('services');

        Schema::create('services', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('addon_namespace')->nullable();
            $table->string('db_connection')->default('mysql');
            $table->boolean('is_active')->default(false);
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
}
