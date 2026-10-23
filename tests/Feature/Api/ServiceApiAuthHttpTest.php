<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Api\Auth\ServiceApiCredentialManager;
use App\Models\Service;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Unit\Api\UsesServiceApiCredentialSchema;

final class ServiceApiAuthHttpTest extends TestCase
{
    use UsesServiceApiCredentialSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootServiceApiCredentialSchema();
    }

    public function test_status_requires_bearer_and_scope(): void
    {
        $seo = $this->makeService('SEO', 'seo');
        $withScope = app(ServiceApiCredentialManager::class)->create($seo, 'Ok', ['service:read']);
        $noScope = app(ServiceApiCredentialManager::class)->create($seo, 'No', ['domains:read']);

        $this->getJson('/api/v1/services/seo/status')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'service_api_unauthorized');

        $this->getJson('/api/v1/services/seo/status', [
            'Authorization' => 'Bearer not-a-valid-key',
        ])->assertStatus(401);

        $this->getJson('/api/v1/services/seo/status', [
            'Authorization' => 'Bearer '.$noScope->rawKey,
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'service_api_scope_denied');

        $this->getJson('/api/v1/services/seo/status', [
            'Authorization' => 'Bearer '.$withScope->rawKey,
        ])
            ->assertOk()
            ->assertJsonPath('data.service', 'seo')
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.api_version', 'v1');
    }

    public function test_wildcard_scope_is_rejected_on_create(): void
    {
        $seo = $this->makeService('SEO', 'seo');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Wildcard scope');
        app(ServiceApiCredentialManager::class)->create($seo, 'Star', ['*']);
    }

    public function test_capabilities_pilot_requires_bearer_and_scope(): void
    {
        $seo = $this->makeService('SEO', 'seo');
        $ok = app(ServiceApiCredentialManager::class)->create($seo, 'Ok', ['service:read']);
        $no = app(ServiceApiCredentialManager::class)->create($seo, 'No', ['seo:read']);

        $this->getJson('/api/v1/me/capabilities')->assertStatus(401);

        $this->getJson('/api/v1/me/capabilities', [
            'Authorization' => 'Bearer '.$no->rawKey,
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'service_api_scope_denied');

        $this->getJson('/api/v1/me/capabilities', [
            'Authorization' => 'Bearer '.$ok->rawKey,
        ])
            ->assertOk()
            ->assertJsonPath('data.gate', 'api_access')
            ->assertJsonPath('data.api_version', 'v1')
            ->assertJsonPath('data.scopes.0', 'service:read');
    }

    public function test_revoked_expired_inactive_fail(): void
    {
        $seo = $this->makeService('SEO', 'seo');
        $manager = app(ServiceApiCredentialManager::class);

        $revoked = $manager->create($seo, 'R', ['service:read']);
        $manager->revoke($revoked->credential);

        $expired = $manager->create(
            $seo,
            'E',
            ['service:read'],
            Carbon::now()->subMinute(),
        );

        $this->getJson('/api/v1/services/seo/status', [
            'Authorization' => 'Bearer '.$revoked->rawKey,
        ])->assertStatus(401);

        $this->getJson('/api/v1/services/seo/status', [
            'Authorization' => 'Bearer '.$expired->rawKey,
        ])->assertStatus(401);

        // Legacy services.is_active=false must not block when H1 allows (pre_enrollment / open).
        $legacyInactive = $this->makeService('Seeding', 'seeding', false);
        $key = $manager->create($legacyInactive, 'I', ['service:read']);
        $this->getJson('/api/v1/services/seeding/status', [
            'Authorization' => 'Bearer '.$key->rawKey,
        ])
            ->assertOk()
            ->assertJsonPath('data.active', true);
    }

    public function test_cross_service_credential_isolation(): void
    {
        $seo = $this->makeService('SEO', 'seo');
        $seeding = $this->makeService('Seeding', 'seeding');
        $manager = app(ServiceApiCredentialManager::class);

        $seoKey = $manager->create($seo, 'SEO key', ['service:read']);
        $seedKey = $manager->create($seeding, 'Seed key', ['service:read']);

        $this->getJson('/api/v1/services/seo/status', [
            'Authorization' => 'Bearer '.$seoKey->rawKey,
        ])->assertOk();

        $this->getJson('/api/v1/services/seeding/status', [
            'Authorization' => 'Bearer '.$seoKey->rawKey,
        ])->assertStatus(403);

        $this->getJson('/api/v1/services/seo/status', [
            'Authorization' => 'Bearer '.$seedKey->rawKey,
        ])->assertStatus(403);

        $this->getJson('/api/v1/services/seeding/status', [
            'Authorization' => 'Bearer '.$seedKey->rawKey,
        ])->assertOk();
    }

    public function test_rotate_invalidates_old_key(): void
    {
        $seo = $this->makeService('SEO', 'seo');
        $manager = app(ServiceApiCredentialManager::class);
        $created = $manager->create($seo, 'Rot', ['service:read']);
        $old = $created->rawKey;
        $rotated = $manager->rotate($created->credential);

        $this->getJson('/api/v1/services/seo/status', [
            'Authorization' => 'Bearer '.$old,
        ])->assertStatus(401);

        $this->getJson('/api/v1/services/seo/status', [
            'Authorization' => 'Bearer '.$rotated->rawKey,
        ])->assertOk();
    }

    public function test_service_key_cannot_authenticate_service_api(): void
    {
        $seo = $this->makeService('SEO', 'seo');
        $seo->forceFill(['service_key' => 'provisioned-internal-key'])->save();

        $this->getJson('/api/v1/services/seo/status', [
            'Authorization' => 'Bearer provisioned-internal-key',
        ])->assertStatus(401);
    }

    private function makeService(string $name, string $slug, bool $active = true): Service
    {
        return Service::query()->create([
            'name' => $name,
            'slug' => $slug,
            'addon_namespace' => 'App\\\\Addons\\\\Test',
            'db_connection' => 'mysql',
            'is_active' => $active,
            'config' => [],
            'service_key' => 'provisioned-internal-key',
        ]);
    }
}
