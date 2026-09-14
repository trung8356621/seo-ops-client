<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Addon\AddonRegistry;
use App\Core\Members\MembersSectionRegistry;
use App\Core\Permissions\AddonPermissionRegistry;
use App\Core\Permissions\LegacySeoRoleBridge;
use App\Filament\Resources\UserResource;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Seeding\Members\SeedingMembersSectionContributor;
use Omnichannel\Addons\Seeding\Support\SeedingAccess;
use Omnichannel\Addons\Seeding\Support\SeedingRoleAssignment;
use ReflectionClass;
use Tests\TestCase;

final class SeedingMembersSectionContributorTest extends TestCase
{
    private SeedingMembersSectionContributor $contributor;

    private SeedingRoleAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.core_connection', 'sqlite');
        Config::set('permission.testing', true);
        $this->createPermissionSchema();

        $registry = app(AddonPermissionRegistry::class);
        $registry->register('seo', [
            LegacySeoRoleBridge::ROLE_MANAGER,
            LegacySeoRoleBridge::ROLE_CONTENT_MANAGER,
        ]);
        $registry->register('seeding', [
            SeedingAccess::ROLE_MANAGER,
            SeedingAccess::ROLE_TOPIC_CREATOR,
            SeedingAccess::ROLE_SEEDER,
        ]);
        $registry->syncToDatabase();

        app(AddonRegistry::class)->markEnabled('seeding');

        $this->contributor = app(SeedingMembersSectionContributor::class);
        $this->assignment = app(SeedingRoleAssignment::class);
    }

    public function test_contributor_registers_seeding_tab_when_available(): void
    {
        $members = new MembersSectionRegistry();
        $members->register($this->contributor);

        self::assertTrue($this->contributor->isAvailable());
        self::assertTrue($members->has('seeding-members'));
        self::assertSame('Seeding', $this->contributor->tabLabel());

        $tabs = $members->formTabs();
        self::assertCount(1, $tabs);
        self::assertSame('Seeding', $tabs[0]->getLabel());
    }

    public function test_core_user_resource_does_not_hardcode_seeding(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(UserResource::class))->getFileName());
        self::assertStringNotContainsString('seeding.manager', $source);
        self::assertStringNotContainsString('SeedingMembersSectionContributor', $source);
        self::assertStringNotContainsString("Tab::make('Seeding')", $source);
        self::assertStringContainsString('formTabs()', $source);
        self::assertStringContainsString('formOnlyStateKeys()', (string) file_get_contents(
            (new ReflectionClass(\App\Filament\Resources\UserResource\Pages\EditUser::class))->getFileName()
        ));
    }

    public function test_user_without_seeding_role_hydrates_as_seeder(): void
    {
        $user = $this->makeUser();
        $fill = $this->contributor->fillCustomizeModal($user);

        self::assertSame(SeedingAccess::ROLE_SEEDER, $fill[SeedingMembersSectionContributor::FORM_KEY]);
        self::assertFalse($user->hasRole(SeedingAccess::ROLE_SEEDER));
    }

    public function test_create_default_save_assigns_seeder(): void
    {
        $user = $this->makeUser();
        $this->contributor->afterUserSaved($user, [
            SeedingMembersSectionContributor::FORM_KEY => SeedingAccess::ROLE_SEEDER,
        ]);

        $fresh = $user->fresh();
        self::assertTrue($fresh->hasRole(SeedingAccess::ROLE_SEEDER));
        self::assertSame(1, $fresh->roles()->whereIn('name', SeedingRoleAssignment::PRECEDENCE)->count());
    }

    public function test_seeder_to_topic_creator_replaces_exclusively(): void
    {
        $user = $this->makeUser();
        $this->assignment->assignExclusive($user, SeedingAccess::ROLE_SEEDER);

        $this->contributor->afterUserSaved($user->fresh(), [
            SeedingMembersSectionContributor::FORM_KEY => SeedingAccess::ROLE_TOPIC_CREATOR,
        ]);

        $fresh = $user->fresh();
        self::assertFalse($fresh->hasRole(SeedingAccess::ROLE_SEEDER));
        self::assertTrue($fresh->hasRole(SeedingAccess::ROLE_TOPIC_CREATOR));
        self::assertSame(1, $fresh->roles()->whereIn('name', SeedingRoleAssignment::PRECEDENCE)->count());
    }

    public function test_topic_creator_to_manager(): void
    {
        $user = $this->makeUser();
        $this->assignment->assignExclusive($user, SeedingAccess::ROLE_TOPIC_CREATOR);

        $this->contributor->afterUserSaved($user->fresh(), [
            SeedingMembersSectionContributor::FORM_KEY => SeedingAccess::ROLE_MANAGER,
        ]);

        $fresh = $user->fresh();
        self::assertTrue($fresh->hasRole(SeedingAccess::ROLE_MANAGER));
        self::assertFalse($fresh->hasRole(SeedingAccess::ROLE_TOPIC_CREATOR));
        self::assertSame(1, $fresh->roles()->whereIn('name', SeedingRoleAssignment::PRECEDENCE)->count());
    }

    public function test_manager_to_seeder(): void
    {
        $user = $this->makeUser();
        $this->assignment->assignExclusive($user, SeedingAccess::ROLE_MANAGER);

        $this->contributor->afterUserSaved($user->fresh(), [
            SeedingMembersSectionContributor::FORM_KEY => SeedingAccess::ROLE_SEEDER,
        ]);

        $fresh = $user->fresh();
        self::assertTrue($fresh->hasRole(SeedingAccess::ROLE_SEEDER));
        self::assertFalse($fresh->hasRole(SeedingAccess::ROLE_MANAGER));
    }

    public function test_changing_seeding_role_preserves_seo_and_core_role(): void
    {
        $user = $this->makeUser(coreRole: User::ROLE_STAFF);
        $user->assignRole(LegacySeoRoleBridge::ROLE_CONTENT_MANAGER);
        $this->assignment->assignExclusive($user, SeedingAccess::ROLE_SEEDER);

        $this->contributor->afterUserSaved($user->fresh(), [
            SeedingMembersSectionContributor::FORM_KEY => SeedingAccess::ROLE_MANAGER,
        ]);

        $fresh = $user->fresh();
        self::assertSame(User::ROLE_STAFF, $fresh->role);
        self::assertTrue($fresh->hasRole(LegacySeoRoleBridge::ROLE_CONTENT_MANAGER));
        self::assertTrue($fresh->hasRole(SeedingAccess::ROLE_MANAGER));
        self::assertFalse($fresh->hasRole(SeedingAccess::ROLE_SEEDER));
    }

    public function test_bad_multi_role_hydrates_by_precedence_and_save_normalizes(): void
    {
        $user = $this->makeUser();
        $user->assignRole(SeedingAccess::ROLE_SEEDER);
        $user->assignRole(SeedingAccess::ROLE_TOPIC_CREATOR);
        $user->assignRole(SeedingAccess::ROLE_MANAGER);

        self::assertSame(
            SeedingAccess::ROLE_MANAGER,
            $this->assignment->resolveForUser($user->fresh()),
        );

        $this->contributor->afterUserSaved($user->fresh(), [
            SeedingMembersSectionContributor::FORM_KEY => SeedingAccess::ROLE_MANAGER,
        ]);

        $fresh = $user->fresh();
        self::assertSame(1, $fresh->roles()->whereIn('name', SeedingRoleAssignment::PRECEDENCE)->count());
        self::assertTrue($fresh->hasRole(SeedingAccess::ROLE_MANAGER));
    }

    public function test_unavailable_contributor_yields_no_seeding_tab(): void
    {
        Config::set('addons.skip_slugs', ['seeding']);
        $contributor = new SeedingMembersSectionContributor();
        self::assertFalse($contributor->isAvailable());

        $members = new MembersSectionRegistry();
        $members->register($contributor);
        self::assertSame([], $members->formTabs());
    }

    public function test_no_users_seeding_role_column_in_schema_or_fillable(): void
    {
        self::assertNotContains('seeding_role', (new User)->getFillable());
        $migrations = glob(base_path('database/migrations/*.php')) ?: [];
        foreach ($migrations as $file) {
            $src = (string) file_get_contents($file);
            self::assertStringNotContainsString('seeding_role', $src, basename($file));
        }
    }

    private function makeUser(string $coreRole = User::ROLE_STAFF): User
    {
        static $n = 0;
        $n++;

        return User::query()->create([
            'name' => "Seeding Member {$n}",
            'email' => "seeding-member{$n}@example.com",
            'password' => Hash::make('password'),
            'role' => $coreRole,
            'status' => User::STATUS_NORMAL,
            'parent_id' => $coreRole === User::ROLE_STAFF ? 1 : null,
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
