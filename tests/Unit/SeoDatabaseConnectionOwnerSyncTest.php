<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Permissions\AddonPermissionRegistry;
use App\Core\Permissions\SeoRoleAssignment;
use App\Filament\Support\SeoDatabaseConnectionOwnerSync;
use App\Models\SeoDatabaseConnection;
use App\Models\Service;
use App\Models\SiteService;
use App\Models\User;
use App\Services\SiteServiceBindingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SeoDatabaseConnectionOwnerSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.core_connection', 'sqlite');
        Config::set('permission.testing', true);
        $this->createSchema();

        $registry = app(AddonPermissionRegistry::class);
        $registry->register('seo', [
            SeoRoleAssignment::ROLE_MANAGER,
            SeoRoleAssignment::ROLE_PLANNER,
            SeoRoleAssignment::ROLE_CONTENT_MANAGER,
        ]);
        $registry->syncToDatabase();
    }

    public function test_sync_owner_assigns_spatie_seo_manager(): void
    {
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@test.test',
            'password' => Hash::make('secret'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);
        app(SeoRoleAssignment::class)->assign($owner, User::SEO_ROLE_CONTENT_MANAGER);

        $connection = SeoDatabaseConnection::query()->create([
            'name' => 'Workspace',
            'type' => 'manual',
            'database' => 'omi_seo_ai',
            'is_active' => true,
        ]);

        SeoDatabaseConnectionOwnerSync::syncOwner($connection, $owner->id);

        $owner->refresh();

        $this->assertTrue($owner->hasRole(SeoRoleAssignment::ROLE_MANAGER));
        $this->assertFalse($owner->hasRole(SeoRoleAssignment::ROLE_CONTENT_MANAGER));
        $this->assertSame([$owner->id], $connection->users()->pluck('users.id')->all());
    }

    public function test_assert_owner_single_connection_blocks_duplicate(): void
    {
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner2@test.test',
            'password' => Hash::make('secret'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);

        $service = Service::query()->firstOrCreate(
            ['slug' => 'seo-content-ai'],
            [
                'name' => 'SEO Content AI',
                'addon_namespace' => 'App\\Addons\\SeoContentAi\\SeoContentAiServiceProvider',
                'is_active' => true,
            ],
        );

        SiteService::query()->create([
            'bound_type' => SiteServiceBindingService::BOUND_USER,
            'user_id' => $owner->id,
            'site_id' => null,
            'service_id' => $service->id,
            'status' => 'active',
            'settings' => ['db_config_type' => 'manual'],
        ]);

        $existing = SeoDatabaseConnection::query()->create([
            'name' => 'Existing',
            'type' => 'manual',
            'database' => 'db1',
            'is_active' => true,
        ]);
        $existing->users()->sync([$owner->id]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        SeoDatabaseConnectionOwnerSync::assertOwnerSingleConnection($owner->id);
    }

    private function createSchema(): void
    {
        $connection = (string) config('database.core_connection', 'sqlite');

        foreach ([
            'role_has_permissions',
            'model_has_roles',
            'model_has_permissions',
            'roles',
            'permissions',
            'seo_connection_users',
            'seo_database_connections',
            'site_services',
            'services',
            'users',
        ] as $table) {
            Schema::connection($connection)->dropIfExists($table);
        }

        Schema::connection($connection)->create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('staff');
            $table->string('status')->default('normal');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection($connection)->create('seo_database_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('hash_id')->nullable();
            $table->string('type')->nullable();
            $table->string('database')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::connection($connection)->create('seo_connection_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('connection_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });

        Schema::connection($connection)->create('services', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->unique();
            $table->string('addon_namespace')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::connection($connection)->create('site_services', function (Blueprint $table): void {
            $table->id();
            $table->string('bound_type')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('service_id');
            $table->string('status')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_09_14_021503_create_permission_tables.php',
            '--force' => true,
        ]);
    }
}
