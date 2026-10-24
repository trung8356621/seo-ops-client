<?php

declare(strict_types=1);

namespace Tests\Unit\H1;

use App\Core\Addon\AddonEntitlementGate;
use App\Models\H1Credential;
use App\Models\H1Installation;
use App\Services\H1\Enforcement\ServiceEntitlementGate;
use App\Services\H1\Protocol\LicenseCrypto;
use Tests\TestCase;

final class ServiceEntitlementGateEnrolledTest extends TestCase
{
    use UsesH1LicenseSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootH1LicenseSchema();
        config([
            'h1-license.enforcement_mode' => 'pre_enrollment',
            'addons.entitlement.enabled' => false,
        ]);
    }

    public function test_enrolled_pre_enrollment_enforces_disabled_and_revoked_services(): void
    {
        $this->seedEnrolledSnapshot([
            'seo' => [
                'key' => 'seo',
                'enabled' => false,
                'revoked_at' => null,
                'valid_until' => '2030-01-01T00:00:00Z',
                'license_model' => 'domain_quota',
                'quota' => 10,
            ],
            'seeding' => [
                'key' => 'seeding',
                'enabled' => false,
                'revoked_at' => '2026-10-24T00:00:00Z',
                'valid_until' => '2030-01-01T00:00:00Z',
                'license_model' => 'flat',
            ],
            'social-automation' => [
                'key' => 'social-automation',
                'enabled' => false,
                'revoked_at' => null,
                'valid_until' => '2030-01-01T00:00:00Z',
                'license_model' => 'flat',
            ],
            'agent' => [
                'key' => 'agent',
                'enabled' => true,
                'scope' => 'global',
            ],
        ]);

        $gate = app(ServiceEntitlementGate::class);
        self::assertTrue($gate->enforcementActive());
        self::assertFalse($gate->allows('seo'));
        self::assertFalse($gate->allows('seeding'));
        self::assertFalse($gate->allows('social-automation'));
        self::assertTrue($gate->allows('agent'));
        self::assertSame('suspended', $gate->state('seo'));
        self::assertSame('suspended', $gate->state('seeding'));

        $addonGate = app(AddonEntitlementGate::class);
        self::assertFalse($addonGate->allows('seo'));
        self::assertFalse($addonGate->allows('seeding'));
        self::assertFalse($addonGate->allows('social-automation'));
        self::assertTrue($addonGate->allows('content'));
    }

    public function test_enrolled_resume_allows_service_again(): void
    {
        $this->seedEnrolledSnapshot([
            'seo' => [
                'key' => 'seo',
                'enabled' => false,
                'valid_until' => '2030-01-01T00:00:00Z',
                'license_model' => 'domain_quota',
                'quota' => 10,
            ],
            'agent' => ['key' => 'agent', 'enabled' => true, 'scope' => 'global'],
        ]);

        $gate = app(ServiceEntitlementGate::class);
        self::assertFalse($gate->allows('seo'));

        $installation = H1Installation::query()->firstOrFail();
        $snap = $installation->verified_snapshot;
        $snap['services']['seo']['enabled'] = true;
        unset($snap['services']['seo']['revoked_at']);
        $installation->forceFill(['verified_snapshot' => $snap])->save();

        self::assertTrue($gate->allows('seo'));
        self::assertSame('allowed', $gate->state('seo'));
    }

    /**
     * @param  array<string, array<string, mixed>>  $services
     */
    private function seedEnrolledSnapshot(array $services): void
    {
        $secret = LicenseCrypto::base64UrlEncode(str_repeat('c', 32));
        H1Installation::query()->create([
            'installation_id' => 'ins_gate_enrolled',
            'product' => 'seo-ops',
            'status' => 'enrolled',
            'cutover_mode' => 'pre_enrollment',
            'entitlement_revision' => 10,
            'credential_version' => 1,
            'verified_snapshot' => [
                'protocol' => 'seo-ops-license/1',
                'installation_id' => 'ins_gate_enrolled',
                'product' => 'seo-ops',
                'entitlement_revision' => 10,
                'services' => $services,
            ],
            'validated_at' => now(),
            'pending_acks' => [],
        ]);
        $cred = new H1Credential([
            'installation_id' => 'ins_gate_enrolled',
            'credential_id' => 'cred_gate_enrolled',
            'version' => 1,
            'status' => H1Credential::STATUS_ACTIVE,
            'activated_at' => now(),
        ]);
        $cred->setSecretBase64Url($secret);
        $cred->save();
    }
}
