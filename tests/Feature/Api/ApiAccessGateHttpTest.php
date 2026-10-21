<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Api\Auth\ApiKeyHasher;
use App\Api\Auth\ServiceApiCredentialManager;
use App\Api\Middleware\AuthenticateServiceApi;
use App\Filament\Pages\Gateway;
use App\Filament\Pages\ServiceConfigure;
use App\Models\Service;
use App\Models\ServiceApiCredential;
use App\Models\User;
use App\Services\ServiceIdentity;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Unit\Api\UsesServiceApiCredentialSchema;

/**
 * C-API-1 acceptance matrix for API Access Gate (local, synthetic credentials).
 */
final class ApiAccessGateHttpTest extends TestCase
{
    use UsesServiceApiCredentialSchema;

    private Service $seo;

    private User $owner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootServiceApiCredentialSchema();
        $this->bootUsersSchema();

        $this->seo = Service::query()->create([
            'name' => 'SEO',
            'slug' => 'seo',
            'addon_namespace' => 'App\\Addons\\Test',
            'db_connection' => 'mysql',
            'is_active' => true,
            'config' => [],
            'service_key' => 'provisioned-internal-key',
        ]);

        $this->owner = User::query()->create([
            'name' => 'Root Owner',
            'email' => 'root-api-gate-'.uniqid('', true).'@example.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);

        $this->admin = User::query()->create([
            'name' => 'Admin NonRoot',
            'email' => 'admin-api-gate-'.uniqid('', true).'@example.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_NORMAL,
        ]);
    }

    public function test_root_creates_hashed_key_shown_once_via_service_configure(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ServiceConfigure::class, ['service' => ServiceIdentity::PUBLIC_SEO])
            ->call('createApiCredential', [
                'name' => 'Pilot',
                'scopes' => ['service:read'],
                'expires_at' => null,
            ])
            ->assertSet('revealedApiKeyName', 'Pilot')
            ->assertNotSet('revealedApiKey', null);

        $revealed = (string) Livewire::test(ServiceConfigure::class, ['service' => ServiceIdentity::PUBLIC_SEO])
            ->call('createApiCredential', [
                'name' => 'Pilot2',
                'scopes' => ['service:read'],
                'expires_at' => null,
            ])
            ->get('revealedApiKey');

        self::assertStringStartsWith('svc_live_', $revealed);

        $row = DB::table('service_api_credentials')->orderByDesc('id')->first();
        self::assertNotNull($row);
        self::assertNotSame($revealed, $row->key_hash);
        self::assertTrue(app(ApiKeyHasher::class)->verify($revealed, (string) $row->key_hash));
        self::assertStringNotContainsString($revealed, (string) json_encode($row));

        Livewire::test(ServiceConfigure::class, ['service' => ServiceIdentity::PUBLIC_SEO])
            ->assertSet('revealedApiKey', null);
    }

    public function test_valid_key_and_scope_access_capabilities(): void
    {
        $key = app(ServiceApiCredentialManager::class)->create($this->seo, 'Ok', ['service:read']);

        $this->getJson('/api/v1/me/capabilities', [
            'Authorization' => 'Bearer '.$key->rawKey,
        ])
            ->assertOk()
            ->assertJsonPath('data.gate', 'api_access')
            ->assertJsonMissingPath('data.service_key')
            ->assertJsonMissingPath('error');
    }

    public function test_missing_and_invalid_key_rejected(): void
    {
        $this->getJson('/api/v1/me/capabilities')->assertStatus(401);
        $this->getJson('/api/v1/me/capabilities', [
            'Authorization' => 'Bearer not-a-valid-key',
        ])->assertStatus(401);
    }

    public function test_insufficient_scope_returns_403(): void
    {
        $key = app(ServiceApiCredentialManager::class)->create($this->seo, 'SeoOnly', ['seo:read']);

        $this->getJson('/api/v1/me/capabilities', [
            'Authorization' => 'Bearer '.$key->rawKey,
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'service_api_scope_denied');
    }

    public function test_revoked_key_stops_immediately(): void
    {
        $manager = app(ServiceApiCredentialManager::class);
        $created = $manager->create($this->seo, 'R', ['service:read']);
        $manager->revoke($created->credential);

        $this->getJson('/api/v1/me/capabilities', [
            'Authorization' => 'Bearer '.$created->rawKey,
        ])->assertStatus(401);
    }

    public function test_rotation_replaces_access_safely(): void
    {
        $manager = app(ServiceApiCredentialManager::class);
        $created = $manager->create($this->seo, 'Rot', ['service:read']);
        $old = $created->rawKey;
        $rotated = $manager->rotate($created->credential);

        $this->getJson('/api/v1/me/capabilities', [
            'Authorization' => 'Bearer '.$old,
        ])->assertStatus(401);

        $this->getJson('/api/v1/me/capabilities', [
            'Authorization' => 'Bearer '.$rotated->rawKey,
        ])->assertOk();
    }

    public function test_expiration_enforced(): void
    {
        $expired = app(ServiceApiCredentialManager::class)->create(
            $this->seo,
            'E',
            ['service:read'],
            Carbon::now()->subMinute(),
        );

        $this->getJson('/api/v1/me/capabilities', [
            'Authorization' => 'Bearer '.$expired->rawKey,
        ])->assertStatus(401);
    }

    public function test_rate_limit_registered_for_service_api(): void
    {
        $key = app(ServiceApiCredentialManager::class)->create($this->seo, 'RL', ['service:read']);
        $request = Request::create('/api/v1/me/capabilities', 'GET');
        $request->headers->set('Authorization', 'Bearer '.$key->rawKey);

        $credential = ServiceApiCredential::query()->findOrFail($key->credential->id);
        $request->attributes->set(
            AuthenticateServiceApi::REQUEST_CONTEXT_KEY,
            new \App\Api\Services\ServiceApiContext($this->seo, $credential),
        );

        $limiter = RateLimiter::limiter('service-api');
        self::assertNotNull($limiter);
        $result = $limiter($request);
        $limits = is_array($result) ? $result : [$result];
        self::assertNotEmpty($limits);
        self::assertInstanceOf(Limit::class, $limits[0]);
    }

    public function test_non_root_cannot_manage_keys_on_service_configure(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ServiceConfigure::class, ['service' => ServiceIdentity::PUBLIC_SEO])
            ->assertSet('revealedApiKey', null)
            ->call('createApiCredential', [
                'name' => 'ShouldFail',
                'scopes' => ['service:read'],
                'expires_at' => null,
            ])
            ->assertSet('revealedApiKey', null);

        self::assertSame(0, ServiceApiCredential::query()->count());
        self::assertFalse((new ServiceConfigure)->canManageApiKeys());
    }

    public function test_owner_can_manage_keys(): void
    {
        $this->actingAs($this->owner);
        self::assertTrue((new ServiceConfigure)->canManageApiKeys());
    }

    public function test_legacy_gateway_route_no_longer_exposes_management_ui_to_bearer(): void
    {
        self::assertFalse(Gateway::shouldRegisterNavigation());

        $key = app(ServiceApiCredentialManager::class)->create($this->seo, 'X', ['service:read', 'seo:read']);

        $admin = $this->getJson('/admin/gateway', [
            'Authorization' => 'Bearer '.$key->rawKey,
        ]);
        // Bearer API keys are not a Filament/session login — reject or redirect to login.
        self::assertTrue(
            in_array($admin->status(), [401, 302, 303, 307, 308], true),
            'Expected 401 or redirect away from legacy Gateway admin UI, got '.$admin->status()
        );

        $caps = $this->getJson('/api/v1/me/capabilities', [
            'Authorization' => 'Bearer '.$key->rawKey,
        ])->assertOk()->json('data');

        self::assertSame('api_access', $caps['gate'] ?? null);
        self::assertArrayNotHasKey('control_gate_secret', $caps);
        self::assertArrayNotHasKey('installation_id', $caps);
        self::assertArrayNotHasKey('service_key', $caps);
    }

    public function test_service_key_and_ops_secret_cannot_authenticate_api_access(): void
    {
        $this->getJson('/api/v1/me/capabilities', [
            'Authorization' => 'Bearer provisioned-internal-key',
        ])->assertStatus(401);
    }

    public function test_resolver_is_local_db_only_without_ops_server_imports(): void
    {
        $src = (string) file_get_contents(app_path('Api/Auth/ApiKeyResolver.php'));
        self::assertStringNotContainsString('ops-server', strtolower($src));
        self::assertStringNotContainsString('OpsServer', $src);
        self::assertStringNotContainsString('service_key', $src);

        $mw = (string) file_get_contents(app_path('Api/Middleware/AuthenticateServiceApi.php'));
        self::assertStringNotContainsString('OpsServer', $mw);
        self::assertStringContainsString('unauthorized()', $mw);
    }

    private function bootUsersSchema(): void
    {
        if (Schema::hasTable('users')) {
            return;
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default(User::ROLE_OWNER);
            $table->string('status')->default(User::STATUS_NORMAL);
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->timestamps();
        });
    }
}
