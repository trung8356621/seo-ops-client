<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Permissions\AddonAuthorization;
use App\Core\Permissions\AddonPermissionRegistry;
use App\Core\Permissions\LegacySeoRoleBridge;
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
            LegacySeoRoleBridge::ROLE_MANAGER,
            LegacySeoRoleBridge::ROLE_PLANNER,
            LegacySeoRoleBridge::ROLE_CONTENT_MANAGER,
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

    public function test_seo_legacy_backfill_and_repeat_is_idempotent(): void
    {
        $registry = app(AddonPermissionRegistry::class);
        $registry->register('seo', [
            LegacySeoRoleBridge::ROLE_MANAGER,
            LegacySeoRoleBridge::ROLE_PLANNER,
            LegacySeoRoleBridge::ROLE_CONTENT_MANAGER,
        ]);
        $registry->syncToDatabase();

        $user = User::query()->create([
            'name' => 'SEO Staff',
            'email' => 'seo-staff@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_STAFF,
            'status' => User::STATUS_NORMAL,
            'parent_id' => 1,
            'seo_role' => User::SEO_ROLE_MANAGER,
        ]);

        $bridge = app(LegacySeoRoleBridge::class);
        self::assertTrue($bridge->syncFromLegacyColumn($user->fresh()));
        self::assertTrue($user->fresh()->hasRole(LegacySeoRoleBridge::ROLE_MANAGER));
        self::assertFalse($bridge->syncFromLegacyColumn($user->fresh()));
        self::assertSame(1, $user->fresh()->roles()->where('name', 'like', 'seo.%')->count());
    }

    public function test_owner_has_seo_panel_access_without_addon_role(): void
    {
        $owner = new User([
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
            'seo_role' => null,
        ]);

        self::assertTrue($owner->canAccessSeoPanel());
        $this->actingAs($owner);
        self::assertSame(SeoAccessControl::ROLE_MANAGER, SeoAccessControl::actualRole());
    }

    public function test_staff_account_isolation_helper(): void
    {
        $registry = app(AddonPermissionRegistry::class);
        $registry->register('seo', [LegacySeoRoleBridge::ROLE_MANAGER]);
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
            'seo_role' => User::SEO_ROLE_MANAGER,
        ]);

        app(LegacySeoRoleBridge::class)->syncFromLegacyColumn($staffA->fresh());

        /** @var AddonAuthorization $auth */
        $auth = app(AddonAuthorization::class);
        self::assertTrue($auth->sharesAccountScope($staffA->fresh(), $ownerA->fresh()));
        self::assertFalse($auth->sharesAccountScope($staffA->fresh(), $ownerB->fresh()));
        self::assertSame((int) $ownerA->id, (int) $staffA->fresh()->accountOwnerId());
        self::assertTrue($staffA->fresh()->hasRole(LegacySeoRoleBridge::ROLE_MANAGER));
    }

    public function test_permissions_sync_command_runs(): void
    {
        $registry = app(AddonPermissionRegistry::class);
        $registry->register('seo', [LegacySeoRoleBridge::ROLE_MANAGER]);
        $registry->register('seeding', ['seeding.manager']);

        $exit = Artisan::call('permissions:sync-addons', ['--backfill-seo' => true]);
        self::assertSame(0, $exit);
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
            $table->string('seo_role')->nullable();
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
