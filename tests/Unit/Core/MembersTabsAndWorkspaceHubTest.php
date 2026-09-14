<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Members\MembersSectionContributor;
use App\Core\Members\MembersSectionRegistry;
use App\Core\Workspace\WorkspaceDestination;
use App\Core\Workspace\WorkspaceDestinationRegistry;
use App\Filament\Resources\UserResource;
use App\Http\Middleware\Filament\RedirectStaffFromAdminPanel;
use App\Models\User;
use Filament\Forms\Components\Tabs\Tab;
use Filament\Panel;
use Illuminate\Http\Request;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterCapacitySettingsService;
use Omnichannel\Addons\SearchFoundation\Members\SeoMembersSectionContributor;
use ReflectionClass;
use Tests\TestCase;

final class MembersTabsAndWorkspaceHubTest extends TestCase
{
    public function test_user_resource_form_uses_tabs_with_core_account_tab(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(UserResource::class))->getFileName());
        self::assertStringContainsString("Tabs::make('member_tabs')", $source);
        self::assertStringContainsString("Tab::make('Tài khoản')", $source);
        self::assertStringContainsString('formTabs()', $source);
        self::assertStringContainsString("__('Account')", $source);
        self::assertStringContainsString("__('Organization')", $source);
    }

    public function test_registry_form_tabs_include_registered_addon_and_skip_empty(): void
    {
        $registry = new MembersSectionRegistry();
        $registry->register(new class implements MembersSectionContributor
        {
            public function addonSlug(): string
            {
                return 'demo-addon';
            }

            public function tabLabel(): string
            {
                return 'Demo';
            }

            public function tabIcon(): ?string
            {
                return 'heroicon-o-sparkles';
            }

            public function sort(): int
            {
                return 5;
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function formOnlyStateKeys(): array
            {
                return [];
            }

            public function formSections(): array
            {
                return [
                    \Filament\Forms\Components\TextInput::make('demo_field'),
                ];
            }

            public function customizeModalSchema(): array
            {
                return [];
            }

            public function fillCustomizeModal(User $user): array
            {
                return [];
            }

            public function afterUserSaved(User $user, array $formState): void {}
        });

        $registry->register(new class implements MembersSectionContributor
        {
            public function addonSlug(): string
            {
                return 'empty-addon';
            }

            public function tabLabel(): string
            {
                return 'Empty';
            }

            public function tabIcon(): ?string
            {
                return null;
            }

            public function sort(): int
            {
                return 99;
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function formOnlyStateKeys(): array
            {
                return [];
            }

            public function formSections(): array
            {
                return [];
            }

            public function customizeModalSchema(): array
            {
                return [];
            }

            public function fillCustomizeModal(User $user): array
            {
                return [];
            }

            public function afterUserSaved(User $user, array $formState): void {}
        });

        $tabs = $registry->formTabs();
        self::assertCount(1, $tabs);
        self::assertInstanceOf(Tab::class, $tabs[0]);
        self::assertSame('Demo', $tabs[0]->getLabel());
    }

    public function test_seo_fill_shows_system_default_when_override_null(): void
    {
        $this->prepareCapacityTables();
        $settings = ContentProjectWriterCapacitySettingsService::withDefaults();
        $this->app->instance(ContentProjectWriterCapacitySettingsService::class, $settings);

        $user = User::query()->create([
            'name' => 'Fill',
            'email' => 'fill@example.com',
            'password' => 'x',
            'role' => User::ROLE_STAFF,
            'status' => User::STATUS_NORMAL,
            'parent_id' => 1,
        ]);

        $fill = (new SeoMembersSectionContributor())->fillCustomizeModal($user);

        self::assertTrue($fill['seo_capacity_use_default']);
        self::assertSame($settings->defaultMonthlyCapacity(), $fill['seo_monthly_capacity_override']);
        self::assertNull($settings->overrideForUserId((int) $user->id));
    }

    public function test_seo_default_mode_persists_null_not_displayed_default(): void
    {
        $this->prepareCapacityTables();
        $settings = ContentProjectWriterCapacitySettingsService::withDefaults();
        $this->app->instance(ContentProjectWriterCapacitySettingsService::class, $settings);

        $user = User::query()->create([
            'name' => 'U',
            'email' => 'u@example.com',
            'password' => 'x',
            'role' => User::ROLE_STAFF,
            'status' => User::STATUS_NORMAL,
            'parent_id' => 1,
        ]);
        $settings->setUserOverride($user, 55);
        self::assertSame(55, $settings->overrideForUserId((int) $user->id));

        (new SeoMembersSectionContributor())->afterUserSaved($user, [
            'seo_capacity_use_default' => true,
            'seo_monthly_capacity_override' => $settings->defaultMonthlyCapacity(),
        ]);

        self::assertNull($settings->overrideForUserId((int) $user->id));
        self::assertSame($settings->defaultMonthlyCapacity(), $settings->capacityForUser($user));
    }

    public function test_seo_custom_mode_persists_integer(): void
    {
        $this->prepareCapacityTables();
        $settings = ContentProjectWriterCapacitySettingsService::withDefaults();
        $this->app->instance(ContentProjectWriterCapacitySettingsService::class, $settings);

        $user = User::query()->create([
            'name' => 'U2',
            'email' => 'u2@example.com',
            'password' => 'x',
            'role' => User::ROLE_STAFF,
            'status' => User::STATUS_NORMAL,
            'parent_id' => 1,
        ]);

        (new SeoMembersSectionContributor())->afterUserSaved($user, [
            'seo_capacity_use_default' => false,
            'seo_monthly_capacity_override' => 42,
        ]);

        self::assertSame(42, $settings->overrideForUserId((int) $user->id));
    }

    public function test_seo_toggle_custom_to_default_clears_override(): void
    {
        $this->prepareCapacityTables();
        $settings = ContentProjectWriterCapacitySettingsService::withDefaults();
        $this->app->instance(ContentProjectWriterCapacitySettingsService::class, $settings);

        $user = User::query()->create([
            'name' => 'U3',
            'email' => 'u3@example.com',
            'password' => 'x',
            'role' => User::ROLE_STAFF,
            'status' => User::STATUS_NORMAL,
            'parent_id' => 1,
        ]);
        $settings->setUserOverride($user, 42);

        (new SeoMembersSectionContributor())->afterUserSaved($user, [
            'seo_capacity_use_default' => true,
            'seo_monthly_capacity_override' => 42,
        ]);

        self::assertNull($settings->overrideForUserId((int) $user->id));
    }

    public function test_seo_contributor_source_has_effective_default_layout_and_toggle_init(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(SeoMembersSectionContributor::class))->getFileName()
        );
        self::assertStringContainsString('Dùng hạn mức mặc định', $source);
        self::assertStringContainsString('defaultMonthlyCapacity()', $source);
        self::assertStringContainsString("dehydrated(fn (Get \$get): bool => ! (bool) \$get('seo_capacity_use_default'))", $source);
        self::assertStringContainsString('afterStateUpdated', $source);
        self::assertStringContainsString('tabLabel', $source);
        self::assertStringContainsString('Giới hạn bài SEO / tháng', $source);
    }

    public function test_staff_admin_home_redirects_to_workspace_hub(): void
    {
        $staff = new User([
            'role' => User::ROLE_STAFF,
            'status' => User::STATUS_NORMAL,
            'parent_id' => 1,
        ]);
        $this->actingAs($staff);

        $middleware = new RedirectStaffFromAdminPanel();
        $request = Request::create('/admin', 'GET');
        $request->setUserResolver(fn () => $staff);

        $response = $middleware->handle($request, fn () => response('ok'));
        self::assertTrue($response->isRedirect());
        self::assertSame(url('/workspace'), $response->headers->get('Location'));
    }

    public function test_staff_admin_users_path_does_not_redirect_via_middleware(): void
    {
        $staff = new User([
            'role' => User::ROLE_STAFF,
            'status' => User::STATUS_NORMAL,
            'parent_id' => 1,
        ]);
        $this->actingAs($staff);

        $middleware = new RedirectStaffFromAdminPanel();
        $request = Request::create('/admin/users', 'GET');
        $request->setUserResolver(fn () => $staff);

        $response = $middleware->handle($request, fn () => response('passthrough', 200));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('passthrough', $response->getContent());
        self::assertFalse($staff->canAccessPanel($this->mockPanel('admin')));
    }

    public function test_owner_admin_home_passes_middleware(): void
    {
        $owner = new User([
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);
        $this->actingAs($owner);

        $middleware = new RedirectStaffFromAdminPanel();
        $request = Request::create('/admin', 'GET');
        $request->setUserResolver(fn () => $owner);

        $response = $middleware->handle($request, fn () => response('admin-ok', 200));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('admin-ok', $response->getContent());
        self::assertTrue($owner->canAccessPanel($this->mockPanel('admin')));
    }

    public function test_workspace_hub_only_lists_authorized_destinations(): void
    {
        $registry = new WorkspaceDestinationRegistry();
        $registry->register(new WorkspaceDestination(
            key: 'allowed',
            label: 'Allowed',
            url: '/allowed',
            sort: 1,
            canAccess: static fn (User $u): bool => $u->isStaff(),
        ));
        $registry->register(new WorkspaceDestination(
            key: 'denied',
            label: 'Denied',
            url: '/denied',
            sort: 2,
            canAccess: static fn (User $u): bool => false,
        ));

        $staff = new User([
            'role' => User::ROLE_STAFF,
            'status' => User::STATUS_NORMAL,
            'parent_id' => 1,
        ]);
        $visible = $registry->visibleFor($staff);
        self::assertCount(1, $visible);
        self::assertSame('allowed', $visible[0]->key);

        $blocked = new User([
            'role' => User::ROLE_STAFF,
            'status' => User::STATUS_BLOCK,
            'parent_id' => 1,
        ]);
        self::assertSame([], $registry->visibleFor($blocked));
    }

    public function test_workspace_route_requires_auth(): void
    {
        $this->get('/workspace')->assertRedirect();
    }

    private function mockPanel(string $id): Panel
    {
        $panel = $this->createMock(Panel::class);
        $panel->method('getId')->willReturn($id);

        return $panel;
    }

    private function prepareCapacityTables(): void
    {
        \Illuminate\Support\Facades\Config::set('database.core_connection', 'sqlite');
        $connection = 'sqlite';

        \Illuminate\Support\Facades\Schema::connection($connection)->dropIfExists('user_meta');
        \Illuminate\Support\Facades\Schema::connection($connection)->dropIfExists('users');

        \Illuminate\Support\Facades\Schema::connection($connection)->create('users', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('staff');
            $table->string('status')->default('normal');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        \Illuminate\Support\Facades\Schema::connection($connection)->create('user_meta', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->timestamps();
        });
    }
}
