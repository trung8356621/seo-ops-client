<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Permissions\AddonAuthorization;
use App\Core\Permissions\AddonPermissionRegistry;
use App\Core\Permissions\SeoRoleAssignment;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Tests\TestCase;

final class AddonPermissionFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.core_connection', 'sqlite');
        Config::set('permission.testing', true);
        $this->createPermissionSchema();
    }

    public function test_registry_registers_seo_and_seeding_roles_idempotently(): void
    {
        /** @var AddonPermissionRegistry $registry */
        $registry = app(AddonPermissionRegistry::class);
        $registry->register('seo', [
            SeoRoleAssignment::ROLE_MANAGER,
            SeoRoleAssignment::ROLE_PLANNER,
            SeoRoleAssignment::ROLE_CONTENT_MANAGER,
        ]);
        $registry->register('seeding', [
            'seeding.manager',
            'seeding.topic_creator',
            'seeding.seeder',
        ]);

        $first = $registry->syncToDatabase();
        $second = $registry->syncToDatabase();

        self::assertGreaterThanOrEqual(6, $first['roles_created'] + $second['roles_created']);
        self::assertSame(0, $second['roles_created']);
        self::assertSame(3, count($registry->rolesFor('seo')));
        self::assertSame(3, count($registry->rolesFor('seeding')));
        self::assertTrue(
            \App\Models\Role::query()->where('name', 'seo.manager')->exists()
        );
        self::assertTrue(
            \App\Models\Role::query()->where('name', 'seeding.topic_creator')->exists()
        );
    }

    public function test_seo_role_assign_is_exclusive_and_idempotent(): void
    {
        $registry = app(AddonPermissionRegistry::class);
        $registry->register('seo', [
            SeoRoleAssignment::ROLE_MANAGER,
            SeoRoleAssignment::ROLE_PLANNER,
            SeoRoleAssignment::ROLE_CONTENT_MANAGER,
        ]);
        $registry->syncToDatabase();

        $user = User::query()->create([
            'name' => 'SEO Staff',
            'email' => 'seo-staff@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_STAFF,
            'status' => User::STATUS_NORMAL,
            'parent_id' => 1,
        ]);

        $seo = app(SeoRoleAssignment::class);
        $seo->assign($user, User::SEO_ROLE_MANAGER);
        self::assertTrue($user->fresh()->hasRole(SeoRoleAssignment::ROLE_MANAGER));

        $seo->assign($user->fresh(), User::SEO_ROLE_MANAGER);
        self::assertSame(1, $user->fresh()->roles()->where('name', 'like', 'seo.%')->count());

        $seo->assign($user->fresh(), User::SEO_ROLE_PLANNER);
        $fresh = $user->fresh();
        self::assertTrue($fresh->hasRole(SeoRoleAssignment::ROLE_PLANNER));
        self::assertFalse($fresh->hasRole(SeoRoleAssignment::ROLE_MANAGER));
        self::assertSame(1, $fresh->roles()->where('name', 'like', 'seo.%')->count());
    }

    public function test_owner_has_seo_panel_access_without_addon_role(): void
    {
        $owner = new User([
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);

        self::assertTrue($owner->canAccessSeoPanel());
        $this->actingAs($owner);
        self::assertSame(SeoAccessControl::ROLE_MANAGER, SeoAccessControl::actualRole());
    }

    public function test_staff_account_isolation_helper(): void
    {
        $registry = app(AddonPermissionRegistry::class);
        $registry->register('seo', [SeoRoleAssignment::ROLE_MANAGER]);
        $registry->syncToDatabase();

        $ownerA = User::query()->create([
            'name' => 'Owner A',
            'email' => 'owner-a@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);
        $ownerB = User::query()->create([
            'name' => 'Owner B',
            'email' => 'owner-b@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);
        $staffA = User::query()->create([
            'name' => 'Staff A',
            'email' => 'staff-a@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_STAFF,
            'status' => User::STATUS_NORMAL,
            'parent_id' => $ownerA->id,
        ]);

        app(SeoRoleAssignment::class)->assign($staffA, User::SEO_ROLE_MANAGER);

        /** @var AddonAuthorization $auth */
        $auth = app(AddonAuthorization::class);
        self::assertTrue($auth->sharesAccountScope($staffA->fresh(), $ownerA->fresh()));
        self::assertFalse($auth->sharesAccountScope($staffA->fresh(), $ownerB->fresh()));
        self::assertSame((int) $ownerA->id, (int) $staffA->fresh()->accountOwnerId());
        self::assertTrue($staffA->fresh()->hasRole(SeoRoleAssignment::ROLE_MANAGER));
    }

    public function test_permissions_sync_command_runs(): void
    {
        $registry = app(AddonPermissionRegistry::class);
        $registry->register('seo', [SeoRoleAssignment::ROLE_MANAGER]);
        $registry->register('seeding', ['seeding.manager']);

        $exit = Artisan::call('permissions:sync-addons', ['--backfill-seo' => true]);
        self::assertSame(0, $exit);
    }

    public function test_users_table_has_no_seo_role_column_after_drop_migration(): void
    {
        $connection = (string) config('database.core_connection', 'sqlite');

        Schema::connection($connection)->table('users', function (Blueprint $table): void {
            $table->string('seo_role')->nullable();
        });
        self::assertTrue(Schema::connection($connection)->hasColumn('users', 'seo_role'));

        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_09_14_120000_drop_legacy_seo_role_from_users_table.php',
            '--force' => true,
        ]);

        self::assertFalse(Schema::connection($connection)->hasColumn('users', 'seo_role'));
    }

    private function createPermissionSchema(): void
    {
        $connection = (string) config('database.core_connection', 'sqlite');

        foreach ([
            'role_has_permissions',
            'model_has_roles',
            'model_has_permissions',
            'roles',
            'permissions',
            'user_meta',
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

        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_09_14_021503_create_permission_tables.php',
            '--force' => true,
        ]);
    }
}
