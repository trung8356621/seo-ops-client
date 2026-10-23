<?php

declare(strict_types=1);

namespace Tests\Feature\Installation;

use App\Core\Installation\AppInitializer;
use App\Core\Installation\ServiceDatabaseConnectionHandoff;
use App\Filament\Pages\ServiceStatusOverview;
use App\Models\Service;
use App\Models\ServiceDatabaseConnection;
use App\Services\ServiceDatabaseConnectionResolver;
use App\Services\ServiceDatabasePasswordIntent;
use App\Services\ServiceIdentity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Integration: Installation Wizard 4-DB config → catalog → handoff → /admin/services read model.
 * Uses sqlite in-memory only — never touches live MySQL / .env.
 */
final class FreshSetupServiceDatabaseHandoffIntegrationTest extends TestCase
{
    /** @var array<string, array{host: string, port: string, database: string, username: string, password: string}> */
    private array $wizardServiceDbs = [
        'omi_seo_ai' => [
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'fresh_seo_db',
            'username' => 'fresh_seo_user',
            'password' => 'seo-pass',
        ],
        'omi_seeding' => [
            'host' => '10.1.0.2',
            'port' => '3307',
            'database' => 'fresh_seeding_db',
            'username' => 'fresh_seed_user',
            'password' => 'seed-pass',
        ],
        'omi_social_automation' => [
            'host' => '10.1.0.3',
            'port' => '3308',
            'database' => 'fresh_sa_db',
            'username' => 'fresh_sa_user',
            'password' => 'sa-pass',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTables();
    }

    public function test_fresh_setup_pipeline_handoffs_all_three_services_for_admin_services(): void
    {
        // 1) Wizard: đủ 4 DB — apply runtime config như DatabaseConfigurator (không ghi .env / không MySQL).
        $this->applyWizardRuntimeConfig();

        // 2) Post-migrate Service Catalog (AddonManager::discover equivalent for the 3 commercial services).
        $this->seedServiceCatalog();

        // 3) Same handoff step AppInitializer runs after catalog.
        $report = app(ServiceDatabaseConnectionHandoff::class)->ensureFromConfiguredConnections();
        self::assertSame(
            [
                ServiceIdentity::PUBLIC_SEO,
                ServiceIdentity::PUBLIC_SEEDING,
                ServiceIdentity::PUBLIC_SOCIAL_AUTOMATION,
            ],
            $report['created'],
        );

        // 4) Canonical rows: host/database/username (Social Automation included).
        $this->assertCanonicalRowsMatchWizard();

        // 5) /admin/services read model — configured from SDC, not defaults / unavailable.
        $this->assertAdminServicesReadsCanonicalConfigs();

        // 6) Retry Setup handoff: no duplicates, no overwrite.
        $this->applyWizardRuntimeConfig([
            'omi_seo_ai' => [
                'host' => '9.9.9.9',
                'port' => '9999',
                'database' => 'SHOULD_NOT_WRITE',
                'username' => 'SHOULD_NOT_WRITE',
                'password' => 'SHOULD_NOT_WRITE',
            ],
            'omi_seeding' => [
                'host' => '9.9.9.9',
                'port' => '9999',
                'database' => 'SHOULD_NOT_WRITE',
                'username' => 'SHOULD_NOT_WRITE',
                'password' => 'SHOULD_NOT_WRITE',
            ],
            'omi_social_automation' => [
                'host' => '9.9.9.9',
                'port' => '9999',
                'database' => 'SHOULD_NOT_WRITE',
                'username' => 'SHOULD_NOT_WRITE',
                'password' => 'SHOULD_NOT_WRITE',
            ],
        ]);

        $retry = app(ServiceDatabaseConnectionHandoff::class)->ensureFromConfiguredConnections();
        self::assertSame([], $retry['created']);
        self::assertCount(3, $retry['skipped']);
        self::assertSame(3, ServiceDatabaseConnection::query()->count());
        $this->assertCanonicalRowsMatchWizard();
    }

    public function test_legacy_gap_creates_only_missing_social_automation_row(): void
    {
        $this->applyWizardRuntimeConfig();
        $this->seedServiceCatalog();

        $resolver = app(ServiceDatabaseConnectionResolver::class);
        $seo = ServiceIdentity::findService(ServiceIdentity::PUBLIC_SEO);
        $seeding = ServiceIdentity::findService(ServiceIdentity::PUBLIC_SEEDING);
        self::assertInstanceOf(Service::class, $seo);
        self::assertInstanceOf(Service::class, $seeding);

        // Audit shape: SEO + Seeding present, Social Automation missing.
        $resolver->upsert($seo, [
            'host' => $this->wizardServiceDbs['omi_seo_ai']['host'],
            'port' => $this->wizardServiceDbs['omi_seo_ai']['port'],
            'database' => $this->wizardServiceDbs['omi_seo_ai']['database'],
            'username' => $this->wizardServiceDbs['omi_seo_ai']['username'],
        ], [
            'action' => ServiceDatabasePasswordIntent::ACTION_SET,
            'plain' => $this->wizardServiceDbs['omi_seo_ai']['password'],
        ]);
        $resolver->upsert($seeding, [
            'host' => $this->wizardServiceDbs['omi_seeding']['host'],
            'port' => $this->wizardServiceDbs['omi_seeding']['port'],
            'database' => $this->wizardServiceDbs['omi_seeding']['database'],
            'username' => $this->wizardServiceDbs['omi_seeding']['username'],
        ], [
            'action' => ServiceDatabasePasswordIntent::ACTION_SET,
            'plain' => $this->wizardServiceDbs['omi_seeding']['password'],
        ]);

        self::assertNull($resolver->resolve(ServiceIdentity::PUBLIC_SOCIAL_AUTOMATION));

        $report = app(ServiceDatabaseConnectionHandoff::class)->ensureFromConfiguredConnections();
        self::assertSame([ServiceIdentity::PUBLIC_SOCIAL_AUTOMATION], $report['created']);
        self::assertEqualsCanonicalizing(
            [ServiceIdentity::PUBLIC_SEO, ServiceIdentity::PUBLIC_SEEDING],
            $report['skipped'],
        );
        self::assertSame(3, ServiceDatabaseConnection::query()->count());

        $sa = $resolver->resolve(ServiceIdentity::PUBLIC_SOCIAL_AUTOMATION);
        self::assertInstanceOf(ServiceDatabaseConnection::class, $sa);
        self::assertSame('fresh_sa_db', (string) $sa->database);
        self::assertSame('fresh_sa_user', (string) $sa->username);
        self::assertSame('10.1.0.3', (string) $sa->host);

        // Existing SEO row untouched.
        $seoRow = $resolver->resolve(ServiceIdentity::PUBLIC_SEO);
        self::assertSame('fresh_seo_db', (string) $seoRow?->database);
        self::assertSame('seo-pass', $seoRow?->password);
    }

    public function test_app_initializer_wires_handoff_after_service_catalog(): void
    {
        $src = (string) file_get_contents(app_path('Core/Installation/AppInitializer.php'));
        self::assertStringContainsString('ServiceDatabaseConnectionHandoff', $src);
        self::assertStringContainsString('service_database_connection_handoff', $src);
        self::assertStringContainsString('addon_discovery_service_catalog', $src);

        $catalogPos = strpos($src, 'addon_discovery_service_catalog');
        $handoffPos = strpos($src, 'service_database_connection_handoff');
        self::assertNotFalse($catalogPos);
        self::assertNotFalse($handoffPos);
        self::assertGreaterThan($catalogPos, $handoffPos);

        self::assertSame(AppInitializer::class, AppInitializer::class);
    }

    /**
     * @param  array<string, array{host: string, port: string, database: string, username: string, password: string}>|null  $override
     */
    private function applyWizardRuntimeConfig(?array $override = null): void
    {
        $map = $override ?? $this->wizardServiceDbs;

        // Core (4th DB) — installer always configures mysql; handoff does not persist Core as a Service row.
        Config::set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'fresh_core_db',
            'username' => 'fresh_core_user',
            'password' => 'core-pass',
        ]);

        foreach ($map as $logical => $cfg) {
            Config::set('database.connections.'.$logical, array_merge(['driver' => 'mysql'], $cfg));
        }
    }

    private function seedServiceCatalog(): void
    {
        Service::query()->create([
            'name' => 'SEO Content AI',
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

    private function assertCanonicalRowsMatchWizard(): void
    {
        $resolver = app(ServiceDatabaseConnectionResolver::class);
        $expectations = [
            ServiceIdentity::PUBLIC_SEO => $this->wizardServiceDbs['omi_seo_ai'],
            ServiceIdentity::PUBLIC_SEEDING => $this->wizardServiceDbs['omi_seeding'],
            ServiceIdentity::PUBLIC_SOCIAL_AUTOMATION => $this->wizardServiceDbs['omi_social_automation'],
        ];

        self::assertSame(3, ServiceDatabaseConnection::query()->count());

        foreach ($expectations as $slug => $expected) {
            $row = $resolver->resolve($slug);
            self::assertInstanceOf(ServiceDatabaseConnection::class, $row, "Missing SDC for {$slug}");
            self::assertSame($expected['host'], (string) $row->host, $slug);
            self::assertSame($expected['port'], (string) $row->port, $slug);
            self::assertSame($expected['database'], (string) $row->database, $slug);
            self::assertSame($expected['username'], (string) $row->username, $slug);
            self::assertSame($expected['password'], (string) $row->password, $slug);
        }
    }

    private function assertAdminServicesReadsCanonicalConfigs(): void
    {
        $resolver = app(ServiceDatabaseConnectionResolver::class);
        $cards = (new ServiceStatusOverview)->serviceCards();
        $bySlug = [];
        foreach ($cards as $card) {
            $bySlug[$card['slug']] = $card;
        }

        foreach ([
            ServiceIdentity::PUBLIC_SEO => 'fresh_seo_db',
            ServiceIdentity::PUBLIC_SEEDING => 'fresh_seeding_db',
            ServiceIdentity::PUBLIC_SOCIAL_AUTOMATION => 'fresh_sa_db',
        ] as $slug => $database) {
            self::assertArrayHasKey($slug, $bySlug, "Admin services missing row for {$slug}");
            self::assertNotSame('Chưa cấu hình DB', $bySlug[$slug]['db_label'], $slug);

            $health = $resolver->healthReport($slug);
            self::assertTrue($health['database_configured'], $slug);
            self::assertSame('canonical', $health['connection_source'], $slug);
            self::assertSame($database, $health['database'], $slug);
            // Defaults must not win: host/username come from wizard handoff.
            $expected = match ($slug) {
                ServiceIdentity::PUBLIC_SEO => $this->wizardServiceDbs['omi_seo_ai'],
                ServiceIdentity::PUBLIC_SEEDING => $this->wizardServiceDbs['omi_seeding'],
                default => $this->wizardServiceDbs['omi_social_automation'],
            };
            self::assertSame($expected['host'], $health['host'], $slug);
            self::assertSame($expected['username'], $health['username'], $slug);
        }
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
