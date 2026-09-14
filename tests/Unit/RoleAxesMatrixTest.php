<?php

declare(strict_types=1);

namespace Tests\Unit;

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

final class RoleAxesMatrixTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.core_connection', 'sqlite');
        Config::set('permission.testing', true);
        $this->createPermissionSchema();

        $registry = app(AddonPermissionRegistry::class);
        $registry->register('seo', [
            SeoRoleAssignment::ROLE_MANAGER,
            SeoRoleAssignment::ROLE_PLANNER,
            SeoRoleAssignment::ROLE_CONTENT_MANAGER,
        ]);
        $registry->syncToDatabase();
    }

    public function test_owner_without_seo_role_gets_full_manager_rank(): void
    {
        $user = $this->makeUser(User::ROLE_OWNER);

        $this->actingAs($user);

        $this->assertTrue($user->canAccessPanel(filament()->getPanel('admin')));
        $this->assertTrue(SeoAccessControl::canAccessSeoPanel($user));
        $this->assertSame(SeoAccessControl::ROLE_MANAGER, SeoAccessControl::actualRole());
        $this->assertTrue(SeoAccessControl::canAccessManagerFeatures());
    }

    public function test_owner_with_content_manager_spatie_role_keeps_axes_independent(): void
    {
        $user = $this->makeUser(User::ROLE_OWNER);
        app(SeoRoleAssignment::class)->assign($user, User::SEO_ROLE_CONTENT_MANAGER);

        $this->actingAs($user->fresh());

        $this->assertTrue($user->canAccessPanel(filament()->getPanel('admin')));
        $this->assertTrue(SeoAccessControl::canAccessSeoPanel($user->fresh()));
        $this->assertSame(SeoAccessControl::ROLE_CONTENT_MANAGER, SeoAccessControl::actualRole());
        $this->assertFalse(SeoAccessControl::canAccessManagerFeatures());
    }

    public function test_staff_with_manager_seo_role_denied_admin_allowed_seo_manager(): void
    {
        $user = $this->makeUser(User::ROLE_STAFF, parentId: 1);
        app(SeoRoleAssignment::class)->assign($user, User::SEO_ROLE_MANAGER);

        $this->actingAs($user->fresh());

        $this->assertFalse($user->canAccessPanel(filament()->getPanel('admin')));
        $this->assertTrue(SeoAccessControl::canAccessSeoPanel($user->fresh()));
        $this->assertSame(SeoAccessControl::ROLE_MANAGER, SeoAccessControl::actualRole());
        $this->assertTrue(SeoAccessControl::canAccessManagerFeatures());
    }

    public function test_legacy_admin_role_can_access_admin_but_not_seo_without_owner_link(): void
    {
        $user = $this->makeUser(User::ROLE_ADMIN);
        app(SeoRoleAssignment::class)->assign($user, User::SEO_ROLE_MANAGER);

        $this->actingAs($user->fresh());

        $this->assertTrue($user->canAccessPanel(filament()->getPanel('admin')));
        $this->assertFalse(SeoAccessControl::canAccessSeoPanel($user->fresh()));
    }

    private function makeUser(string $role, ?int $parentId = null): User
    {
        static $n = 0;
        $n++;

        return User::query()->create([
            'name' => "Axis {$n}",
            'email' => "axis{$n}@example.com",
            'password' => Hash::make('password'),
            'role' => $role,
            'status' => User::STATUS_NORMAL,
            'parent_id' => $parentId,
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
