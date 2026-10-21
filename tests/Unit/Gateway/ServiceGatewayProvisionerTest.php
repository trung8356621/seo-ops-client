<?php

declare(strict_types=1);

namespace Tests\Unit\Gateway;

use App\Api\Auth\ApiKeyGenerator;
use App\Api\Auth\ServiceApiCredentialManager;
use App\Enums\ServiceApiCredentialKind;
use App\Enums\ServiceGatewayProvisionStatus;
use App\Filament\Pages\Gateway;
use App\Models\Service;
use App\Models\ServiceApiCredential;
use App\Models\ServiceGatewayProvision;
use App\Services\Gateway\EntitlementAppliedHook;
use App\Services\Gateway\EntitlementPresentationStore;
use App\Services\Gateway\ServiceGatewayProvisioner;
use App\Services\Gateway\VerifiedEntitlementSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Unit\Api\UsesServiceApiCredentialSchema;

final class ServiceGatewayProvisionerTest extends TestCase
{
    use UsesServiceApiCredentialSchema;

    private Service $seo;

    private Service $seeding;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootServiceApiCredentialSchema();

        config([
            'service_gateway.services.seo.requires_managed_api_credential' => true,
            'service_gateway.services.seo.managed_scopes' => ['service:read'],
            'service_gateway.services.seeding.requires_managed_api_credential' => false,
            'service_gateway.services.seeding.managed_scopes' => [],
        ]);

        $this->seo = $this->makeService('SEO', 'seo');
        $this->seeding = $this->makeService('Seeding', 'seeding');
    }

    public function test_entitled_service_requiring_credential_provisions_once(): void
    {
        $hook = app(EntitlementAppliedHook::class);
        $snapshot = $this->activeSnapshot(['seo' => true, 'seeding' => true]);

        $first = $hook->afterVerifiedEntitlementApplied($snapshot);
        $second = $hook->afterVerifiedEntitlementApplied($snapshot);

        $seoFirst = collect($first)->first(
            static fn ($r) => $r->provision->public_slug === 'seo'
        );
        $seoSecond = collect($second)->first(
            static fn ($r) => $r->provision->public_slug === 'seo'
        );

        self::assertNotNull($seoFirst);
        self::assertTrue($seoFirst->credentialCreated);
        self::assertSame(ServiceGatewayProvisionStatus::Ready, $seoFirst->status());
        self::assertFalse($seoSecond?->credentialCreated);

        self::assertSame(1, ServiceApiCredential::query()
            ->where('service_id', $this->seo->id)
            ->where('kind', ServiceApiCredentialKind::Managed->value)
            ->whereNull('revoked_at')
            ->count());

        $cred = ServiceApiCredential::query()
            ->where('service_id', $this->seo->id)
            ->where('kind', ServiceApiCredentialKind::Managed->value)
            ->first();
        self::assertNotNull($cred);
        self::assertStringStartsWith('svc_gate_', (string) $cred->key_prefix);
    }

    public function test_concurrent_provision_does_not_duplicate(): void
    {
        $snapshot = $this->activeSnapshot(['seo' => true]);
        app(EntitlementPresentationStore::class)->save($snapshot);
        $provisioner = app(ServiceGatewayProvisioner::class);

        $results = [];
        DB::transaction(function () use ($provisioner, &$results): void {
            $results[] = $provisioner->provisionPublicSlug('seo');
            $results[] = $provisioner->provisionPublicSlug('seo');
        });

        self::assertSame(1, ServiceApiCredential::query()
            ->where('service_id', $this->seo->id)
            ->where('kind', ServiceApiCredentialKind::Managed->value)
            ->whereNull('revoked_at')
            ->count());
        self::assertTrue($results[0]->credentialCreated);
        self::assertFalse($results[1]->credentialCreated);
    }

    public function test_service_without_credential_requirement_does_not_create_key(): void
    {
        $hook = app(EntitlementAppliedHook::class);
        $results = $hook->afterVerifiedEntitlementApplied(
            $this->activeSnapshot(['seo' => false, 'seeding' => true])
        );

        $seeding = collect($results)->first(
            static fn ($r) => $r->provision->public_slug === 'seeding'
        );
        self::assertNotNull($seeding);
        self::assertSame(ServiceGatewayProvisionStatus::NotRequired, $seeding->status());
        self::assertFalse($seeding->credentialCreated);
        self::assertSame(0, ServiceApiCredential::query()
            ->where('service_id', $this->seeding->id)
            ->count());
    }

    public function test_not_entitled_service_is_not_execution_allowed(): void
    {
        $hook = app(EntitlementAppliedHook::class);
        $hook->afterVerifiedEntitlementApplied($this->activeSnapshot(['seo' => false]));

        $provision = ServiceGatewayProvision::query()->where('service_id', $this->seo->id)->first();
        self::assertNotNull($provision);
        self::assertFalse($provision->execution_allowed);
        self::assertFalse($provision->entitled);
        self::assertSame(0, ServiceApiCredential::query()
            ->where('service_id', $this->seo->id)
            ->where('kind', ServiceApiCredentialKind::Managed->value)
            ->count());
    }

    public function test_revoke_disables_execution_and_regrant_reuses_key(): void
    {
        $hook = app(EntitlementAppliedHook::class);
        $hook->afterVerifiedEntitlementApplied($this->activeSnapshot(['seo' => true]));

        $managedId = (int) ServiceGatewayProvision::query()
            ->where('service_id', $this->seo->id)
            ->value('managed_credential_id');
        self::assertGreaterThan(0, $managedId);

        $hook->afterVerifiedEntitlementApplied(new VerifiedEntitlementSnapshot(
            connected: true,
            licenseStatus: 'revoked',
            productName: 'SEO-OPS',
            planName: 'Pro',
            startsAt: Carbon::parse('2026-01-01'),
            endsAt: Carbon::parse('2026-12-31'),
            entitledServices: ['seo' => false],
        ));

        $revoked = ServiceGatewayProvision::query()->where('service_id', $this->seo->id)->first();
        self::assertNotNull($revoked);
        self::assertFalse($revoked->execution_allowed);
        self::assertSame(ServiceGatewayProvisionStatus::Revoked, $revoked->status);
        self::assertSame($managedId, (int) $revoked->managed_credential_id);
        self::assertNull(ServiceApiCredential::query()->find($managedId)?->revoked_at);

        $regrant = $hook->afterVerifiedEntitlementApplied($this->activeSnapshot(['seo' => true]));
        $seo = collect($regrant)->first(static fn ($r) => $r->provision->public_slug === 'seo');
        self::assertNotNull($seo);
        self::assertFalse($seo->credentialCreated);
        self::assertTrue($seo->provision->execution_allowed);
        self::assertSame($managedId, (int) $seo->provision->managed_credential_id);
        self::assertSame(1, ServiceApiCredential::query()
            ->where('service_id', $this->seo->id)
            ->where('kind', ServiceApiCredentialKind::Managed->value)
            ->whereNull('revoked_at')
            ->count());
    }

    public function test_managed_key_execution_requires_entitlement_and_user_key_still_works(): void
    {
        $material = app(ApiKeyGenerator::class)->generate(ApiKeyGenerator::PREFIX_MANAGED);
        $managed = ServiceApiCredential::query()->create([
            'service_id' => $this->seo->id,
            'kind' => ServiceApiCredentialKind::Managed,
            'name' => 'Service Gate managed',
            'key_prefix' => $material['key_prefix'],
            'key_hash' => $material['key_hash'],
            'scopes' => ['service:read'],
        ]);

        $user = app(ServiceApiCredentialManager::class)->create($this->seo, 'User', ['service:read']);

        $hook = app(EntitlementAppliedHook::class);
        $hook->afterVerifiedEntitlementApplied($this->activeSnapshot(['seo' => true]));

        $this->getJson('/api/v1/services/seo/status', [
            'Authorization' => 'Bearer '.$material['raw_key'],
        ])->assertOk();

        $this->getJson('/api/v1/me/capabilities', [
            'Authorization' => 'Bearer '.$user->rawKey,
        ])->assertOk();

        $hook->afterVerifiedEntitlementApplied(new VerifiedEntitlementSnapshot(
            connected: true,
            licenseStatus: 'revoked',
            entitledServices: ['seo' => false],
        ));

        $this->getJson('/api/v1/services/seo/status', [
            'Authorization' => 'Bearer '.$material['raw_key'],
        ])->assertStatus(403);

        // User API Access key still authenticates when service is active (C-API-1).
        $this->getJson('/api/v1/me/capabilities', [
            'Authorization' => 'Bearer '.$user->rawKey,
        ])->assertOk();

        self::assertSame($managed->id, (int) ServiceGatewayProvision::query()
            ->where('service_id', $this->seo->id)
            ->value('managed_credential_id'));
    }

    public function test_missing_h1_state_is_not_licensed(): void
    {
        $store = app(EntitlementPresentationStore::class);
        $snapshot = $store->current();
        self::assertFalse($snapshot->connected);
        self::assertSame('disconnected', $snapshot->licenseStatus);

        app(EntitlementAppliedHook::class)->afterVerifiedEntitlementApplied(
            $this->activeSnapshot(['seo' => true])
        );

        $applied = $store->current();
        self::assertTrue($applied->connected);
        self::assertSame('SEO-OPS', $applied->productName);
        self::assertSame('2026-01-01', $applied->startsAt?->toDateString());
        self::assertNotSame($applied->startsAt?->toDateString(), optional(
            ServiceApiCredential::query()->where('kind', ServiceApiCredentialKind::Managed->value)->first()
        )?->created_at?->toDateString());

        $store->save(VerifiedEntitlementSnapshot::disconnected());
        $disconnected = $store->current();
        self::assertFalse($disconnected->connected);
        self::assertSame('disconnected', $disconnected->licenseStatus);
        self::assertNull($disconnected->productName);
        self::assertNull($disconnected->startsAt);

        $user = app(ServiceApiCredentialManager::class)->create($this->seo, 'Visible', ['service:read']);
        self::assertTrue($user->credential->isUserKey());
        self::assertFalse($user->credential->isManaged());
        self::assertGreaterThan(
            0,
            ServiceApiCredential::query()->where('kind', ServiceApiCredentialKind::Managed->value)->count()
        );
    }

    public function test_api_access_key_cannot_reach_legacy_gateway_as_session(): void
    {
        $user = app(ServiceApiCredentialManager::class)->create($this->seo, 'X', ['service:read']);
        $res = $this->getJson('/admin/gateway', [
            'Authorization' => 'Bearer '.$user->rawKey,
        ]);
        self::assertTrue(in_array($res->status(), [401, 302, 303, 307, 308], true));
    }

    public function test_legacy_gateway_ui_is_redirect_only_without_secrets(): void
    {
        self::assertFalse(Gateway::shouldRegisterNavigation());
        $page = (string) file_get_contents(app_path('Filament/Pages/Gateway.php'));
        $view = (string) file_get_contents(resource_path('views/filament/pages/gateway-redirect.blade.php'));

        self::assertStringContainsString('ServiceStatusOverview::getUrl', $page);
        self::assertStringContainsString('shouldRegisterNavigation = false', $page);
        self::assertStringNotContainsString('createApiCredential', $page);
        self::assertStringNotContainsString('controlGateStatus', $page);
        self::assertStringNotContainsString('svc_gate_', $view);
        self::assertStringNotContainsString('key_hash', $view);
        self::assertStringNotContainsString('installation_secret', $view);
        self::assertStringNotContainsString('service_key', $view);
    }

    /**
     * @param  array<string, bool>  $services
     */
    private function activeSnapshot(array $services): VerifiedEntitlementSnapshot
    {
        return new VerifiedEntitlementSnapshot(
            connected: true,
            licenseStatus: 'active',
            productName: 'SEO-OPS',
            planName: 'Pro',
            startsAt: Carbon::parse('2026-01-01'),
            endsAt: Carbon::parse('2026-12-31'),
            entitledServices: $services,
        );
    }

    private function makeService(string $name, string $slug): Service
    {
        return Service::query()->create([
            'name' => $name,
            'slug' => $slug,
            'addon_namespace' => 'App\\Addons\\Test',
            'db_connection' => 'mysql',
            'is_active' => true,
            'config' => [],
            'service_key' => 'provisioned-internal-key',
        ]);
    }
}
