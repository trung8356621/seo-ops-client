<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Permissions\AddonPermissionRegistry;
use App\Core\Permissions\SeoRoleAssignment;
use App\Models\User;
use Filament\Panel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class UserPanelAccessTest extends TestCase
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

    public function test_staff_cannot_access_admin_panel(): void
    {
        $user = $this->makeUser(User::ROLE_STAFF, parentId: 10);
        app(SeoRoleAssignment::class)->assign($user, User::SEO_ROLE_CONTENT_MANAGER);

        $this->assertFalse($user->fresh()->canAccessPanel($this->panelWithId('admin')));
    }

    public function test_legacy_admin_can_access_admin_panel(): void
    {
        $user = $this->makeUser(User::ROLE_ADMIN);

        $this->assertTrue($user->canAccessPanel($this->panelWithId('admin')));
    }

    public function test_owner_can_access_admin_panel(): void
    {
        $user = $this->makeUser(User::ROLE_OWNER);

        $this->assertTrue($user->canAccessPanel($this->panelWithId('admin')));
    }

    public function test_manager_cannot_access_admin_panel(): void
    {
        $user = $this->makeUser(User::ROLE_MANAGER, parentId: 10);

        $this->assertFalse($user->canAccessPanel($this->panelWithId('admin')));
    }

    public function test_staff_with_owner_link_and_seo_role_can_access_seo_panel(): void
    {
        $user = $this->makeUser(User::ROLE_STAFF, parentId: 10);
        app(SeoRoleAssignment::class)->assign($user, User::SEO_ROLE_CONTENT_MANAGER);

        $this->assertTrue($user->fresh()->canAccessPanel($this->panelWithId('seo')));
    }

    public function test_staff_without_seo_role_cannot_access_seo_panel(): void
    {
        $user = $this->makeUser(User::ROLE_STAFF, parentId: 10);

        $this->assertFalse($user->canAccessPanel($this->panelWithId('seo')));
    }

    public function test_authenticated_non_blocked_user_can_access_seeding_panel(): void
    {
        $owner = $this->makeUser(User::ROLE_OWNER);
        $staff = $this->makeUser(User::ROLE_STAFF, parentId: 10);
        $blocked = $this->makeUser(User::ROLE_OWNER, status: User::STATUS_BLOCK);

        $panel = $this->panelWithId('seeding');

        $this->assertTrue($owner->canAccessPanel($panel));
        $this->assertTrue($staff->canAccessPanel($panel));
        $this->assertFalse($blocked->canAccessPanel($panel));
    }

    private function makeUser(
        string $role,
        ?int $parentId = null,
        string $status = User::STATUS_NORMAL,
    ): User {
        static $n = 0;
        $n++;

        return User::query()->create([
            'name' => "Panel {$n}",
            'email' => "panel{$n}@example.com",
            'password' => Hash::make('password'),
            'role' => $role,
            'status' => $status,
            'parent_id' => $parentId,
        ]);
    }

    private function panelWithId(string $id): Panel
    {
        $panel = $this->createMock(Panel::class);
        $panel->method('getId')->willReturn($id);

        return $panel;
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
