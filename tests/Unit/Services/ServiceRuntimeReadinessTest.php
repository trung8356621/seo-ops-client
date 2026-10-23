<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\H1Credential;
use App\Models\H1Installation;
use App\Models\Service;
use App\Models\ServiceDatabaseConnection;
use App\Services\H1\Enforcement\ServiceEntitlementGate;
use App\Services\H1\Protocol\LicenseCrypto;
use App\Services\ServiceDatabaseConnectionResolver;
use App\Services\ServiceDatabasePasswordIntent;
use App\Services\ServiceHealth;
use App\Services\ServiceIdentity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\Unit\H1\UsesH1LicenseSchema;

final class ServiceRuntimeReadinessTest extends TestCase
{
    use UsesH1LicenseSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootH1LicenseSchema();
        $this->bootServiceTables();
    }

    public function test_h1_entitled_without_db_needs_config_for_all_services(): void
    {
        $this->seedEntitledInstallation();
        foreach ([
            ServiceIdentity::PUBLIC_SEO,
            ServiceIdentity::PUBLIC_SEEDING,
            ServiceIdentity::PUBLIC_SOCIAL_AUTOMATION,
        ] as $slug) {
            $this->makeService($slug, ServiceIdentity::defaultLogicalConnection($slug));
            $health = ServiceHealth::make(
                publicSlug: $slug,
                service: ServiceIdentity::findService($slug),
                databaseConfigured: false,
                databaseReachable: false,
                schemaReady: false,
                entitlementState: app(ServiceEntitlementGate::class)->state($slug),
            );
            self::assertSame(ServiceHealth::STATUS_NEEDS_CONFIG, $health['status']);
            self::assertFalse($health['runtime_ready']);
            self::assertTrue($health['entitled']);
        }
    }

    public function test_h1_entitled_db_and_schema_ready_is_ready(): void
    {
        $this->seedEntitledInstallation();
        foreach ([
            ServiceIdentity::PUBLIC_SEO,
            ServiceIdentity::PUBLIC_SEEDING,
            ServiceIdentity::PUBLIC_SOCIAL_AUTOMATION,
        ] as $slug) {
            $svc = $this->makeService($slug, ServiceIdentity::defaultLogicalConnection($slug), isActive: false);
            $health = ServiceHealth::make(
                publicSlug: $slug,
                service: $svc,
                databaseConfigured: true,
                databaseReachable: true,
                schemaReady: true,
                entitlementState: 'allowed',
                lastTestOk: true,
            );
            self::assertSame(ServiceHealth::STATUS_READY, $health['status']);
            self::assertTrue($health['runtime_ready']);
            self::assertTrue($health['active'], 'active mirrors H1 entitlement, not services.is_active');
        }
    }

    public function test_db_test_failure_does_not_mark_ready(): void
    {
        $health = ServiceHealth::make(
            publicSlug: ServiceIdentity::PUBLIC_SEO,
            service: $this->makeService('seo', 'omi_seo_ai'),
            databaseConfigured: true,
            databaseReachable: false,
            schemaReady: false,
            entitlementState: 'allowed',
            lastTestOk: false,
            error: 'unreachable',
        );
        self::assertSame(ServiceHealth::STATUS_CONNECTION_ERROR, $health['status']);
        self::assertFalse($health['runtime_ready']);
    }

    public function test_db_ready_but_h1_not_entitled_cannot_use(): void
    {
        config(['h1-license.enforcement_mode' => 'enforcing']);
        $health = ServiceHealth::make(
            publicSlug: ServiceIdentity::PUBLIC_SEO,
            service: $this->makeService('seo', 'omi_seo_ai'),
            databaseConfigured: true,
            databaseReachable: true,
            schemaReady: true,
            entitlementState: 'not_entitled',
        );
        self::assertSame(ServiceHealth::STATUS_NOT_ENTITLED, $health['status']);
        self::assertFalse($health['runtime_ready']);
        self::assertFalse(app(ServiceEntitlementGate::class)->allows('seo'));
    }

    public function test_h1_suspend_locks_business_and_keeps_db_row(): void
    {
        $this->seedEntitledInstallation();
        $seo = $this->makeService('seo', 'omi_seo_ai');
        $resolver = app(ServiceDatabaseConnectionResolver::class);
        $resolver->upsert($seo, [
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'omi_seo_ai',
            'username' => 'root',
            'type' => 'manual',
        ], ServiceDatabasePasswordIntent::fromFormState(['password' => 'secret', 'clear_password' => false], false));

        $installation = H1Installation::query()->firstOrFail();
        $snap = $installation->verified_snapshot;
        $snap['services']['seo']['enabled'] = false;
        $snap['services']['seo']['revoked_at'] = '2026-10-23T00:00:00Z';
        $installation->forceFill(['verified_snapshot' => $snap])->save();

        $gate = app(ServiceEntitlementGate::class);
        self::assertFalse($gate->allows('seo'));
        self::assertSame('suspended', $gate->state('seo'));
        self::assertSame(1, ServiceDatabaseConnection::query()->where('service_id', $seo->id)->count());
        self::assertSame('secret', ServiceDatabaseConnection::query()->where('service_id', $seo->id)->value('password'));

        $health = ServiceHealth::make(
            publicSlug: 'seo',
            service: $seo->fresh(),
            databaseConfigured: true,
            databaseReachable: true,
            schemaReady: true,
            entitlementState: $gate->state('seo'),
        );
        self::assertSame(ServiceHealth::STATUS_SUSPENDED, $health['status']);
        self::assertFalse($health['runtime_ready']);
    }

    public function test_h1_resume_reuses_ready_db(): void
    {
        $this->seedEntitledInstallation();
        $gate = app(ServiceEntitlementGate::class);
        self::assertTrue($gate->allows('seo'));

        $installation = H1Installation::query()->firstOrFail();
        $snap = $installation->verified_snapshot;
        $snap['services']['seo']['enabled'] = false;
        $installation->forceFill(['verified_snapshot' => $snap])->save();
        self::assertSame('suspended', $gate->state('seo'));

        $snap['services']['seo']['enabled'] = true;
        unset($snap['services']['seo']['revoked_at']);
        $installation->forceFill(['verified_snapshot' => $snap])->save();
        self::assertSame('allowed', $gate->state('seo'));

        $health = ServiceHealth::make(
            publicSlug: 'seo',
            service: $this->makeService('seo', 'omi_seo_ai'),
            databaseConfigured: true,
            databaseReachable: true,
            schemaReady: true,
            entitlementState: $gate->state('seo'),
        );
        self::assertSame(ServiceHealth::STATUS_READY, $health['status']);
    }

    public function test_legacy_service_is_active_false_does_not_block_connection_resolve(): void
    {
        $seo = $this->makeService('seo', 'omi_seo_ai', isActive: false);
        $row = new ServiceDatabaseConnection([
            'service_id' => $seo->id,
            'type' => 'manual',
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'omi_seo_ai',
            'username' => 'root',
            'is_active' => false,
        ]);
        $row->password = 'kept';
        $row->save();

        $resolved = app(ServiceDatabaseConnectionResolver::class)->connectionForService($seo);
        self::assertInstanceOf(ServiceDatabaseConnection::class, $resolved);
        self::assertSame('kept', $resolved->password);
        self::assertFalse((bool) $resolved->is_active);
    }

    public function test_blank_password_on_existing_keeps_stored_secret(): void
    {
        $seo = $this->makeService('seo', 'omi_seo_ai');
        $resolver = app(ServiceDatabaseConnectionResolver::class);
        $resolver->upsert($seo, [
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'omi_seo_ai',
            'username' => 'root',
        ], ['action' => ServiceDatabasePasswordIntent::ACTION_SET, 'plain' => 'original']);

        $resolver->upsert(
            $seo,
            [
                'host' => '127.0.0.1',
                'port' => '3306',
                'database' => 'omi_seo_ai',
                'username' => 'root',
            ],
            ServiceDatabasePasswordIntent::fromFormState(['password' => '', 'clear_password' => false], true),
        );

        self::assertSame('original', ServiceDatabaseConnection::query()->where('service_id', $seo->id)->value('password'));
    }

    public function test_client_cannot_grant_commercial_entitlement_via_db_config(): void
    {
        config(['h1-license.enforcement_mode' => 'enforcing']);
        $seo = $this->makeService('seo', 'omi_seo_ai', isActive: true);
        app(ServiceDatabaseConnectionResolver::class)->upsert($seo, [
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'omi_seo_ai',
            'username' => 'root',
        ], ['action' => ServiceDatabasePasswordIntent::ACTION_SET, 'plain' => null]);

        self::assertFalse(app(ServiceEntitlementGate::class)->allows('seo'));
        $health = ServiceHealth::make(
            publicSlug: 'seo',
            service: $seo,
            databaseConfigured: true,
            databaseReachable: true,
            schemaReady: true,
            entitlementState: 'not_entitled',
        );
        self::assertFalse($health['runtime_ready']);
        self::assertSame(ServiceHealth::STATUS_NOT_ENTITLED, $health['status']);
    }

    public function test_upsert_forces_connection_is_active_true_without_granting_h1(): void
    {
        config(['h1-license.enforcement_mode' => 'enforcing']);
        $seo = $this->makeService('seo', 'omi_seo_ai', isActive: false);
        $row = app(ServiceDatabaseConnectionResolver::class)->upsert($seo, [
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'omi_seo_ai',
            'username' => 'root',
            'is_active' => false,
        ], ['action' => ServiceDatabasePasswordIntent::ACTION_SET, 'plain' => null]);

        self::assertTrue((bool) $row->is_active);
        self::assertFalse(app(ServiceEntitlementGate::class)->allows('seo'));
    }

    private function bootServiceTables(): void
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

    private function makeService(string $slug, string $logical, bool $isActive = true): Service
    {
        $existing = Service::query()->where('slug', $slug)->first();
        if ($existing instanceof Service) {
            $existing->forceFill([
                'db_connection' => $logical,
                'is_active' => $isActive,
            ])->save();

            return $existing->fresh() ?? $existing;
        }

        return Service::query()->create([
            'name' => ServiceIdentity::displayName(ServiceIdentity::publicSlugForCatalog($slug)),
            'slug' => $slug,
            'db_connection' => $logical,
            'is_active' => $isActive,
        ]);
    }

    private function seedEntitledInstallation(): void
    {
        $secret = LicenseCrypto::base64UrlEncode(str_repeat('b', 32));
        H1Installation::query()->create([
            'installation_id' => 'ins_ready',
            'product' => 'seo-ops',
            'status' => 'enrolled',
            'cutover_mode' => 'enforcing',
            'entitlement_revision' => 1,
            'credential_version' => 1,
            'verified_snapshot' => [
                'protocol' => 'seo-ops-license/1',
                'installation_id' => 'ins_ready',
                'product' => 'seo-ops',
                'entitlement_revision' => 1,
                'plan_key' => 'seo-pro-30',
                'valid_until' => '2030-10-21T00:00:00Z',
                'services' => [
                    'agent' => ['key' => 'agent', 'enabled' => true, 'scope' => 'global'],
                    'seo' => ['key' => 'seo', 'enabled' => true, 'license_model' => 'domain_quota', 'quota' => 30, 'valid_until' => '2030-10-21T00:00:00Z'],
                    'seeding' => ['key' => 'seeding', 'enabled' => true, 'license_model' => 'flat', 'valid_until' => '2030-10-21T00:00:00Z'],
                    'social-automation' => ['key' => 'social-automation', 'enabled' => true, 'license_model' => 'flat', 'valid_until' => '2030-10-21T00:00:00Z'],
                ],
            ],
            'validated_at' => now(),
            'pending_acks' => [],
        ]);
        $cred = new H1Credential([
            'installation_id' => 'ins_ready',
            'credential_id' => 'cred_ready',
            'version' => 1,
            'status' => 'active',
            'activated_at' => now(),
        ]);
        $cred->setSecretBase64Url($secret);
        $cred->save();
    }
}
