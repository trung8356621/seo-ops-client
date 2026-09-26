<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use App\Api\Auth\ApiKeyGenerator;
use App\Api\Auth\ApiKeyHasher;
use App\Api\Auth\ServiceApiCredentialManager;
use App\Models\Service;
use App\Models\ServiceApiCredential;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ServiceApiCredentialFoundationTest extends TestCase
{
    use UsesServiceApiCredentialSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootServiceApiCredentialSchema();
    }

    public function test_service_has_many_credentials_and_secrets_hidden(): void
    {
        $service = $this->makeService('seo', 'seo-content-ai');
        $result = app(ServiceApiCredentialManager::class)->create($service, 'Agent', ['service:read']);

        self::assertTrue($service->apiCredentials()->exists());
        self::assertSame($service->id, $result->credential->service->id);
        self::assertArrayNotHasKey('key_hash', $result->credential->toArray());
        self::assertIsArray($result->credential->scopes);
        self::assertNull($result->credential->expires_at);
        self::assertNull($result->credential->revoked_at);
    }

    public function test_raw_key_generated_not_stored_prefix_and_hash_verify(): void
    {
        $service = $this->makeService('seo', 'seo');
        $result = app(ServiceApiCredentialManager::class)->create($service, 'Default API Key', ['service:read']);

        self::assertStringStartsWith('svc_live_', $result->rawKey);
        self::assertSame(
            app(ApiKeyGenerator::class)->extractPrefix($result->rawKey),
            $result->credential->key_prefix,
        );

        $row = DB::table('service_api_credentials')->where('id', $result->credential->id)->first();
        self::assertNotNull($row);
        self::assertNotSame($result->rawKey, $row->key_hash);
        self::assertStringNotContainsString($result->rawKey, (string) json_encode($row));

        $hasher = app(ApiKeyHasher::class);
        self::assertTrue($hasher->verify($result->rawKey, (string) $row->key_hash));
        self::assertFalse($hasher->verify($result->rawKey.'x', (string) $row->key_hash));
    }

    public function test_lifecycle_create_revoke_rotate(): void
    {
        $service = $this->makeService('seeding', 'seeding');
        $manager = app(ServiceApiCredentialManager::class);

        $created = $manager->create($service, 'Make', ['service:read', 'mcp:read']);
        $oldRaw = $created->rawKey;
        $oldId = (int) $created->credential->id;

        $manager->revoke($created->credential->fresh());
        self::assertNotNull(ServiceApiCredential::query()->find($oldId)?->revoked_at);

        $active = $manager->create($service, 'Partner', ['service:read']);
        $rotated = $manager->rotate($active->credential->fresh());

        self::assertNotNull($active->credential->fresh()?->revoked_at);
        self::assertNull($rotated->credential->revoked_at);
        self::assertNotSame($active->rawKey, $rotated->rawKey);
        self::assertTrue(app(ApiKeyHasher::class)->verify(
            $rotated->rawKey,
            (string) $rotated->credential->key_hash,
        ));
        self::assertFalse(app(ApiKeyHasher::class)->verify(
            $oldRaw,
            (string) $rotated->credential->key_hash,
        ));
    }

    public function test_has_scope_and_wildcard(): void
    {
        $service = $this->makeService('seo', 'seo');
        $withRead = app(ServiceApiCredentialManager::class)->create($service, 'A', ['service:read']);
        $withStar = app(ServiceApiCredentialManager::class)->create($service, 'B', ['*']);

        self::assertTrue($withRead->credential->hasScope('service:read'));
        self::assertFalse($withRead->credential->hasScope('domains:read'));
        self::assertTrue($withStar->credential->hasScope('domains:read'));
        self::assertTrue($withStar->credential->hasScope('service:read'));
    }

    private function makeService(string $name, string $slug, bool $active = true): Service
    {
        return Service::query()->create([
            'name' => $name,
            'slug' => $slug,
            'addon_namespace' => 'App\\\\Addons\\\\'.ucfirst($name),
            'db_connection' => 'mysql',
            'is_active' => $active,
            'config' => [],
            'service_key' => 'provisioned-internal-key',
        ]);
    }
}
