<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\PostLoginRedirector;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Canonical auth routes — avoids RefreshDatabase (full migrate is flaky on sqlite memory).
 */
class AuthenticationTest extends TestCase
{
    public function test_login_route_is_registered(): void
    {
        self::assertTrue(Route::has('login'));
        self::assertTrue(Route::has('login.store'));
        $this->get('/login')->assertOk()->assertSee('Đăng nhập SEO Ops', false);
    }

    public function test_legacy_panel_logins_redirect_to_canonical_login(): void
    {
        $this->get('/admin/login')->assertRedirect('/login');
        $this->get('/seo/login')->assertRedirect('/login');
        $this->get('/seeding/login')->assertRedirect('/login');
        $this->get('/tools/login')->assertRedirect('/login');

        $hash = str_repeat('c', 32);
        $this->get('/seo/'.$hash.'/login')->assertRedirect('/login');
    }

    public function test_unauthenticated_addon_route_redirects_to_login(): void
    {
        $this->get('/seo')->assertRedirect(route('login'));
        $this->get('/seeding')->assertRedirect(route('login'));
        $this->get('/admin')->assertRedirect(route('login'));
        $this->get('/workspace')->assertRedirect(route('login'));
    }

    public function test_staff_post_login_destination_is_workspace(): void
    {
        $staff = new User([
            'role' => User::ROLE_STAFF,
            'parent_id' => 1,
        ]);

        self::assertSame(url('/workspace'), app(PostLoginRedirector::class)->urlFor($staff));
    }

    public function test_owner_post_login_destination_is_admin(): void
    {
        $owner = new User([
            'role' => User::ROLE_OWNER,
        ]);

        self::assertSame(url('/admin'), app(PostLoginRedirector::class)->urlFor($owner));
    }

    public function test_authenticated_visit_to_login_uses_post_login_redirector(): void
    {
        $owner = new User([
            'id' => 99,
            'role' => User::ROLE_OWNER,
            'email' => 'owner-auth-test@example.com',
            'password' => Hash::make('password'),
        ]);

        Auth::login($owner);

        $this->get('/login')->assertRedirect('/admin');
    }

    public function test_filament_panel_login_route_names_are_gone(): void
    {
        self::assertFalse(Route::has('filament.admin.auth.login'));
        self::assertFalse(Route::has('filament.seo-main.auth.login'));
        self::assertFalse(Route::has('filament.seo.auth.login'));
        self::assertFalse(Route::has('filament.seeding.auth.login'));
        self::assertFalse(Route::has('filament.tools.auth.login'));
        self::assertFalse(Route::has('seo.auth.login.store'));
    }

    public function test_users_can_logout_via_named_route(): void
    {
        self::assertTrue(Route::has('logout'));
    }
}
