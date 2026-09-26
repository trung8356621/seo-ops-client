<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use App\Api\Access\TemporaryServiceAccessManager;
use App\Api\Access\TemporaryServiceAccessResolver;
use App\Api\Auth\ServiceApiCredentialManager;
use App\Models\Service;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class TemporaryServiceAccessManagerTest extends TestCase
{
    use UsesServiceApiCredentialSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootServiceApiCredentialSchema();
        Cache::flush();
    }

    public function test_issue_and_resolve_round_trip(): void
    {
        $seo = Service::query()->create([
            'name' => 'SEO',
            'slug' => 'seo',
            'addon_namespace' => 'App\\\\Addons\\\\Test',
            'db_connection' => 'mysql',
            'is_active' => true,
            'config' => [],
            'service_key' => 'provisioned',
        ]);
        $created = app(ServiceApiCredentialManager::class)->create($seo, 'SEO', ['seo:read']);
        $manager = app(TemporaryServiceAccessManager::class);
        $issued = $manager->issue($seo, $created->credential, 42);

        self::assertStringStartsWith('access_tmp_', $issued->rawToken);
        self::assertSame('site:42', $issued->siteRef());

        $payload = Cache::get($manager->cacheKey($issued->lookupId));
        self::assertIsArray($payload);
        self::assertSame(42, (int) $payload['site_id']);
        self::assertSame(['seo:read'], $payload['scopes']);
        self::assertStringNotContainsString($issued->rawToken, json_encode($payload) ?: '');

        $context = app(TemporaryServiceAccessResolver::class)->resolve($issued->rawToken);
        self::assertNotNull($context);
        self::assertSame(42, $context->siteId);
        self::assertSame((int) $seo->id, $context->serviceId());
        self::assertSame((int) $created->credential->id, $context->credentialId);
        self::assertTrue($context->hasScope('seo:read'));
    }

    public function test_wrong_secret_does_not_resolve(): void
    {
        $seo = Service::query()->create([
            'name' => 'SEO',
            'slug' => 'seo',
            'addon_namespace' => 'App\\\\Addons\\\\Test',
            'db_connection' => 'mysql',
            'is_active' => true,
            'config' => [],
            'service_key' => 'provisioned',
        ]);
        $created = app(ServiceApiCredentialManager::class)->create($seo, 'SEO', ['seo:read']);
        $manager = app(TemporaryServiceAccessManager::class);
        $issued = $manager->issue($seo, $created->credential, 7);

        $tampered = preg_replace('/_[^_]+$/', '_tamperedsecret', $issued->rawToken) ?? '';
        self::assertNull(app(TemporaryServiceAccessResolver::class)->resolve($tampered));
    }
}
