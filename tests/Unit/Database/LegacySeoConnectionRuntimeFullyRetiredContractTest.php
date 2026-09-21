<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Filament\Pages\ServiceConfigure;
use App\Filament\Resources\SeedingDatabaseConnectionResource;
use App\Filament\Resources\SeoDatabaseConnectionResource;
use App\Models\Service;
use App\Models\ServiceDatabaseConnection;
use App\Models\User;
use App\Services\ServiceDatabaseConnectionResolver;
use App\Services\ServiceIdentity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\ContentProjects\Jobs\RunContentProjectArticleJob;
use Omnichannel\Addons\Publishing\Services\ScheduledArticlePublishRunner;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Seo\Services\SeoLoginServiceResolver;
use ReflectionClass;
use Tests\TestCase;

final class LegacySeoConnectionRuntimeFullyRetiredContractTest extends TestCase
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

    public function test_content_project_job_source_has_no_legacy_query(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(RunContentProjectArticleJob::class))->getFileName(),
        );
        self::assertStringNotContainsString('SeoDatabaseConnection::query(', $src);
        self::assertStringContainsString('bootstrapCanonicalSharedConnection', $src);
        self::assertFalse(Schema::hasTable('seo_database_connections'));
    }

    public function test_seo_login_resolver_works_without_legacy_tables(): void
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

        $user = new User(['role' => User::ROLE_ADMIN]);
        $user->id = 1;
        $resolver = app(SeoLoginServiceResolver::class);

        // Source contract: never queries retired table.
        self::assertStringNotContainsString(
            'SeoDatabaseConnection::query(',
            (string) file_get_contents((string) (new ReflectionClass(SeoLoginServiceResolver::class))->getFileName()),
        );

        // When Service DB exists, resolver prefers short /seo (even if PDO unreachable).
        $result = $resolver->resolveAfterLogin($user);
        self::assertArrayHasKey('use_short_url', $result);
        self::assertArrayHasKey('needs_selection', $result);
        self::assertArrayHasKey('hash', $result);
        self::assertFalse(Schema::hasTable('seo_database_connections'));
        // Accessible connections must not query legacy table.
        self::assertInstanceOf(\Illuminate\Support\Collection::class, $resolver->accessibleConnections($user));
    }

    public function test_scheduled_publishing_runner_is_canonical_only(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ScheduledArticlePublishRunner::class))->getFileName(),
        );
        self::assertStringNotContainsString('SeoDatabaseConnection::query(', $src);
        self::assertStringNotContainsString("hasTable('seo_database_connections')", $src);
        self::assertStringContainsString('resolveDefaultSharedConnectionRecord', $src);
    }

    public function test_legacy_resources_cannot_query_and_redirect_to_service_configure(): void
    {
        self::assertFalse(SeoDatabaseConnectionResource::canCreate());
        self::assertFalse(SeedingDatabaseConnectionResource::canCreate());
        self::assertFalse(SeoDatabaseConnectionResource::shouldRegisterNavigation());
        self::assertFalse(SeedingDatabaseConnectionResource::shouldRegisterNavigation());

        $listSeo = (string) file_get_contents(
            app_path('Filament/Resources/SeoDatabaseConnectionResource/Pages/ListSeoDatabaseConnections.php'),
        );
        $listSeed = (string) file_get_contents(
            app_path('Filament/Resources/SeedingDatabaseConnectionResource/Pages/ListSeedingDatabaseConnections.php'),
        );
        self::assertStringContainsString("ServiceConfigure::getUrl(['service' => 'seo']", $listSeo);
        self::assertStringContainsString("ServiceConfigure::getUrl(['service' => 'seeding']", $listSeed);
        self::assertTrue(class_exists(ServiceConfigure::class));

        $seoQuery = (string) file_get_contents(
            (string) (new ReflectionClass(SeoDatabaseConnectionResource::class))->getFileName(),
        );
        self::assertStringContainsString("whereRaw('0 = 1')", $seoQuery);
        self::assertStringNotContainsString('SeoDatabaseConnection::query(', $seoQuery);
    }

    public function test_client_app_production_has_no_legacy_credential_queries(): void
    {
        $roots = [app_path()];
        $forbidden = [
            'SeoDatabaseConnection::query(',
            'SeedingDatabaseConnection::query(',
            "DB::table('seo_database_connections'",
            "DB::table('seeding_database_connections'",
            "hasTable('seo_database_connections')",
            "hasTable('seeding_database_connections')",
        ];
        $hits = [];
        foreach ($roots as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $contents = (string) file_get_contents($file->getPathname());
                foreach ($forbidden as $needle) {
                    if (str_contains($contents, $needle)) {
                        $hits[] = $file->getPathname().' :: '.$needle;
                    }
                }
            }
        }
        self::assertSame([], $hits);
    }

    public function test_seeding_canonical_and_tombstone_still_ok(): void
    {
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

        $row = app(ServiceDatabaseConnectionResolver::class)->resolve(ServiceIdentity::PUBLIC_SEEDING);
        self::assertInstanceOf(ServiceDatabaseConnection::class, $row);
        self::assertSame('omi_seeding', $row->database);

        $migration = require database_path('migrations/2026_09_22_100000_drop_retired_legacy_service_db_credential_tables.php');
        $migration->up();
        $migration->up();
        self::assertFalse(Schema::hasTable('seo_database_connections'));
        self::assertTrue(Schema::hasTable('service_database_connections'));
    }

    public function test_seo_shared_bootstrap_without_legacy_table(): void
    {
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
        self::assertFalse(Schema::hasTable('seo_database_connections'));
    }
}
