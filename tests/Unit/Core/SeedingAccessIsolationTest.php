<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Permissions\AddonPermissionRegistry;
use App\Core\Permissions\LegacySeoRoleBridge;
use App\Core\Sites\SiteAccess;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Seeding\Support\SeedingAccess;
use Omnichannel\Addons\Seeding\Support\SeedingServiceResolver;
use Tests\TestCase;

/**
 * Addon role isolation: SEO roles must never grant Seeding Manager.
 */
final class SeedingAccessIsolationTest extends TestCase
{
    private SeedingAccess $access;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.core_connection', 'sqlite');
        Config::set('permission.testing', true);
        $this->createPermissionSchema();

        $registry = app(AddonPermissionRegistry::class);
        $registry->register('seo', [
            LegacySeoRoleBridge::ROLE_MANAGER,
            LegacySeoRoleBridge::ROLE_PLANNER,
            LegacySeoRoleBridge::ROLE_CONTENT_MANAGER,
        ]);
        $registry->register('seeding', [
            SeedingAccess::ROLE_MANAGER,
            SeedingAccess::ROLE_TOPIC_CREATOR,
            SeedingAccess::ROLE_SEEDER,
        ]);
        $registry->syncToDatabase();

        $this->access = new SeedingAccess(
            app(SiteAccess::class),
            app(SeedingServiceResolver::class),
        );
    }

    public function test_owner_is_seeding_manager(): void
    {
        $owner = $this->makeUser(User::ROLE_OWNER);

        self::assertTrue($this->access->canAccess($owner));
        self::assertTrue($this->access->isManager($owner));
    }

    public function test_staff_with_seeding_manager_role_is_manager(): void
    {
        $staff = $this->makeUser(User::ROLE_STAFF, parentId: 1);
        $staff->assignRole(SeedingAccess::ROLE_MANAGER);

        self::assertTrue($this->access->isManager($staff->fresh()));
    }

    public function test_staff_with_seo_manager_only_is_not_seeding_manager(): void
    {
        $staff = $this->makeUser(User::ROLE_STAFF, parentId: 1);
        $staff->assignRole(LegacySeoRoleBridge::ROLE_MANAGER);

        self::assertTrue($staff->fresh()->hasRole(LegacySeoRoleBridge::ROLE_MANAGER));
        self::assertFalse($this->access->isManager($staff->fresh()));
    }

    public function test_staff_with_legacy_seo_role_manager_only_is_not_seeding_manager(): void
    {
        $staff = $this->makeUser(
            User::ROLE_STAFF,
            parentId: 1,
            seoRole: User::SEO_ROLE_MANAGER,
        );

        self::assertSame(User::SEO_ROLE_MANAGER, $staff->seo_role);
        self::assertFalse($this->access->isManager($staff));
    }

    public function test_staff_with_seo_and_seeding_manager_is_seeding_manager(): void
    {
        $staff = $this->makeUser(User::ROLE_STAFF, parentId: 1);
        $staff->assignRole(LegacySeoRoleBridge::ROLE_MANAGER);
        $staff->assignRole(SeedingAccess::ROLE_MANAGER);

        self::assertTrue($this->access->isManager($staff->fresh()));
    }

    public function test_blocked_user_is_not_seeding_manager(): void
    {
        $owner = $this->makeUser(User::ROLE_OWNER, status: User::STATUS_BLOCK);
        $staff = $this->makeUser(User::ROLE_STAFF, parentId: 1, status: User::STATUS_BLOCK);
        $staff->assignRole(SeedingAccess::ROLE_MANAGER);

        self::assertFalse($this->access->canAccess($owner));
        self::assertFalse($this->access->isManager($owner));
        self::assertFalse($this->access->isManager($staff->fresh()));
    }

    public function test_service_activation_gate_unchanged_for_can_access(): void
    {
        $connection = (string) config('database.core_connection', 'sqlite');
        Schema::connection($connection)->dropIfExists('services');
        Schema::connection($connection)->create('services', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->unique();
            $table->string('addon_namespace')->nullable();
            $table->string('db_connection')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('config')->nullable();
            $table->text('service_key')->nullable();
            $table->timestamps();
        });

        \App\Models\Service::query()->create([
            'name' => 'Seeding',
            'slug' => SeedingServiceResolver::SLUG,
            'is_active' => false,
            'config' => [],
        ]);

        $access = new SeedingAccess(
            app(SiteAccess::class),
            app(SeedingServiceResolver::class),
        );
        $owner = $this->makeUser(User::ROLE_OWNER);

        self::assertFalse($access->canAccess($owner));
        self::assertFalse($access->canMutate($owner));
        self::assertFalse($access->isManager($owner));
    }

    private function makeUser(
        string $role,
        ?int $parentId = null,
        ?string $seoRole = null,
        string $status = User::STATUS_NORMAL,
    ): User {
        static $n = 0;
        $n++;

        return User::query()->create([
            'name' => "User {$n}",
            'email' => "user{$n}@example.com",
            'password' => Hash::make('password'),
            'role' => $role,
            'status' => $status,
            'parent_id' => $parentId,
            'seo_role' => $seoRole,
        ]);
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
