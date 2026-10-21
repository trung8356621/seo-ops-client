<?php

declare(strict_types=1);

namespace Tests\Unit\H1;

use App\Models\H1Credential;
use App\Models\H1Installation;
use App\Models\Service;
use App\Models\Site;
use App\Services\H1\Commands\EntitlementCommandApplier;
use App\Services\H1\Enforcement\ServiceEntitlementGate;
use App\Services\H1\Protocol\LicenseCrypto;
use App\Services\H1\Rotation\FixtureRotationSecretTransport;
use App\Services\H1\Rotation\RotationSecretTransport;
use Tests\TestCase;

final class EntitlementCommandApplierTest extends TestCase
{
    use UsesH1LicenseSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootH1LicenseSchema();
        Service::query()->create(['name' => 'SEO', 'slug' => 'seo', 'is_active' => true]);
        Service::query()->create(['name' => 'Seeding', 'slug' => 'seeding', 'is_active' => true]);
        $this->seedInstallation();
    }

    public function test_contiguous_renew_seo_leaves_seeding_unchanged(): void
    {
        $applier = app(EntitlementCommandApplier::class);
        $ack = $applier->apply([
            'message_type' => 'entitlement_command',
            'command_id' => 'cmd_02',
            'installation_id' => 'ins_01',
            'product' => 'seo-ops',
            'operation' => 'renew',
            'target_service' => 'seo',
            'entitlement_revision' => 2,
            'credential_version' => 1,
            'issued_at' => '2026-10-21T08:00:00Z',
            'effective_at' => '2026-10-21T08:00:00Z',
            'entitlement' => [
                'plan_key' => 'seo-pro-30',
                'valid_until' => '2030-10-21T00:00:00Z',
                'grace_until' => null,
                'revoked_at' => null,
                'services' => $this->sampleServices(
                    seoUntil: '2030-10-21T00:00:00Z',
                    seedingUntil: '2028-10-21T00:00:00Z',
                ),
            ],
        ]);

        self::assertSame('applied', $ack['result']);
        $snap = H1Installation::query()->first()?->verified_snapshot;
        self::assertSame('2030-10-21T00:00:00Z', $snap['services']['seo']['valid_until']);
        self::assertSame('2028-10-21T00:00:00Z', $snap['services']['seeding']['valid_until']);
        self::assertTrue($snap['services']['seeding']['enabled']);
    }

    public function test_seeding_revoke_leaves_seo_unchanged(): void
    {
        $applier = app(EntitlementCommandApplier::class);
        $services = $this->sampleServices();
        $services[2]['enabled'] = false;
        $services[2]['revoked_at'] = '2026-10-21T09:00:00Z';

        $ack = $applier->apply([
            'message_type' => 'entitlement_command',
            'command_id' => 'cmd_02',
            'installation_id' => 'ins_01',
            'product' => 'seo-ops',
            'operation' => 'revoke',
            'target_service' => 'seeding',
            'entitlement_revision' => 2,
            'credential_version' => 1,
            'issued_at' => '2026-10-21T09:00:00Z',
            'effective_at' => '2026-10-21T09:00:00Z',
            'entitlement' => [
                'plan_key' => 'seo-pro-30',
                'valid_until' => '2029-10-21T00:00:00Z',
                'services' => $services,
            ],
        ]);

        self::assertSame('applied', $ack['result']);
        $gate = app(ServiceEntitlementGate::class);
        self::assertTrue($gate->allows('seo'));
        self::assertFalse($gate->allows('seeding'));
    }

    public function test_revision_gap_rejected(): void
    {
        $ack = app(EntitlementCommandApplier::class)->apply([
            'message_type' => 'entitlement_command',
            'command_id' => 'cmd_99',
            'installation_id' => 'ins_01',
            'product' => 'seo-ops',
            'operation' => 'renew',
            'entitlement_revision' => 5,
            'credential_version' => 1,
            'entitlement' => [
                'plan_key' => 'seo-pro-30',
                'valid_until' => '2029-10-21T00:00:00Z',
                'services' => $this->sampleServices(),
            ],
        ]);
        self::assertSame('rejected', $ack['result']);
        self::assertSame('revision_gap', $ack['reason']);
        self::assertSame(1, (int) H1Installation::query()->value('entitlement_revision'));
    }

    public function test_duplicate_command_returns_stored_ack(): void
    {
        $command = [
            'message_type' => 'entitlement_command',
            'command_id' => 'cmd_02',
            'installation_id' => 'ins_01',
            'product' => 'seo-ops',
            'operation' => 'renew',
            'entitlement_revision' => 2,
            'credential_version' => 1,
            'entitlement' => [
                'plan_key' => 'seo-pro-30',
                'valid_until' => '2029-10-21T00:00:00Z',
                'services' => $this->sampleServices(),
            ],
        ];
        $first = app(EntitlementCommandApplier::class)->apply($command);
        $second = app(EntitlementCommandApplier::class)->apply($command);
        self::assertSame('applied', $first['result']);
        self::assertSame('duplicate', $second['result']);
    }

    public function test_quota_downgrade_resets_sites_once(): void
    {
        Site::query()->create(['domain' => 'a.example', 'status' => 'active']);
        Site::query()->create(['domain' => 'b.example', 'status' => 'active']);
        Site::query()->create(['domain' => 'c.example', 'status' => 'maintenance']);

        $ack = app(EntitlementCommandApplier::class)->apply([
            'message_type' => 'entitlement_command',
            'command_id' => 'cmd_02',
            'installation_id' => 'ins_01',
            'product' => 'seo-ops',
            'operation' => 'change_plan',
            'entitlement_revision' => 2,
            'credential_version' => 1,
            'entitlement' => [
                'plan_key' => 'seo-basic-1',
                'valid_until' => '2029-10-21T00:00:00Z',
                'services' => $this->sampleServices(seoQuota: 1),
            ],
        ]);
        self::assertSame('applied', $ack['result']);
        self::assertTrue($ack['observed_state']['quota_overflow_reset']);
        self::assertSame(0, Site::query()->where('status', 'active')->count());
        self::assertSame(3, Site::query()->count());

        // Reactivate one, replay must not reset again.
        Site::query()->where('domain', 'a.example')->update(['status' => 'active']);
        $dup = app(EntitlementCommandApplier::class)->apply([
            'message_type' => 'entitlement_command',
            'command_id' => 'cmd_02',
            'installation_id' => 'ins_01',
            'product' => 'seo-ops',
            'operation' => 'change_plan',
            'entitlement_revision' => 2,
            'credential_version' => 1,
            'entitlement' => [
                'plan_key' => 'seo-basic-1',
                'valid_until' => '2029-10-21T00:00:00Z',
                'services' => $this->sampleServices(seoQuota: 1),
            ],
        ]);
        self::assertSame('duplicate', $dup['result']);
        self::assertSame(1, Site::query()->where('status', 'active')->count());
    }

    public function test_renew_does_not_reset_sites(): void
    {
        Site::query()->create(['domain' => 'a.example', 'status' => 'active']);
        $ack = app(EntitlementCommandApplier::class)->apply([
            'message_type' => 'entitlement_command',
            'command_id' => 'cmd_02',
            'installation_id' => 'ins_01',
            'product' => 'seo-ops',
            'operation' => 'renew',
            'entitlement_revision' => 2,
            'credential_version' => 1,
            'entitlement' => [
                'plan_key' => 'seo-pro-30',
                'valid_until' => '2031-10-21T00:00:00Z',
                'services' => $this->sampleServices(),
            ],
        ]);
        self::assertSame('applied', $ack['result']);
        self::assertFalse($ack['observed_state']['quota_overflow_reset']);
        self::assertSame('active', Site::query()->where('domain', 'a.example')->value('status'));
    }

    public function test_rotation_resets_sites_once_with_fixture_secret(): void
    {
        Site::query()->create(['domain' => 'a.example', 'status' => 'active']);
        $fixture = new FixtureRotationSecretTransport;
        $newSecret = LicenseCrypto::base64UrlEncode(random_bytes(32));
        $fixture->register('rot_02', $newSecret);
        $this->app->instance(RotationSecretTransport::class, $fixture);
        $this->app->forgetInstance(\App\Services\H1\Commands\RotationCommandApplier::class);
        $this->app->forgetInstance(EntitlementCommandApplier::class);

        $ack = app(EntitlementCommandApplier::class)->apply([
            'message_type' => 'rotate_key',
            'command_id' => 'cmd_rotate_02',
            'rotation_id' => 'rot_02',
            'installation_id' => 'ins_01',
            'product' => 'seo-ops',
            'from_credential' => ['id' => 'cred_01', 'version' => 1],
            'to_credential' => ['id' => 'cred_02', 'version' => 2],
            'issued_at' => '2026-10-21T10:00:00Z',
            'effective_at' => '2026-10-21T10:00:00Z',
            'authorization' => [
                'method' => 'current_credential_and_owner_approval',
                'authorization_id' => 'authz_02',
            ],
        ]);
        self::assertSame('applied', $ack['result']);
        self::assertSame('inactive', Site::query()->where('domain', 'a.example')->value('status'));
        self::assertSame(2, (int) H1Installation::query()->value('credential_version'));
        self::assertSame($newSecret, H1Credential::query()->where('credential_id', 'cred_02')->first()?->secretBase64Url());

        Site::query()->where('domain', 'a.example')->update(['status' => 'active']);
        $dup = app(EntitlementCommandApplier::class)->apply([
            'message_type' => 'rotate_key',
            'command_id' => 'cmd_rotate_02',
            'rotation_id' => 'rot_02',
            'installation_id' => 'ins_01',
            'product' => 'seo-ops',
            'from_credential' => ['id' => 'cred_01', 'version' => 1],
            'to_credential' => ['id' => 'cred_02', 'version' => 2],
            'issued_at' => '2026-10-21T10:00:00Z',
            'effective_at' => '2026-10-21T10:00:00Z',
            'authorization' => [
                'method' => 'current_credential_and_owner_approval',
                'authorization_id' => 'authz_02',
            ],
        ]);
        self::assertSame('duplicate', $dup['result']);
        self::assertSame('active', Site::query()->where('domain', 'a.example')->value('status'));
    }

    private function seedInstallation(): void
    {
        $secret = LicenseCrypto::base64UrlEncode(str_repeat('a', 32));
        $installation = H1Installation::query()->create([
            'installation_id' => 'ins_01',
            'product' => 'seo-ops',
            'status' => 'enrolled',
            'cutover_mode' => 'enforcing',
            'entitlement_revision' => 1,
            'credential_version' => 1,
            'last_command_id' => 'cmd_01',
            'verified_snapshot' => [
                'protocol' => 'seo-ops-license/1',
                'installation_id' => 'ins_01',
                'product' => 'seo-ops',
                'entitlement_revision' => 1,
                'plan_key' => 'seo-pro-30',
                'valid_until' => '2029-10-21T00:00:00Z',
                'services' => [
                    'agent' => ['key' => 'agent', 'enabled' => true, 'scope' => 'global'],
                    'seo' => ['key' => 'seo', 'enabled' => true, 'license_model' => 'domain_quota', 'quota' => 30, 'valid_until' => '2029-10-21T00:00:00Z'],
                    'seeding' => ['key' => 'seeding', 'enabled' => true, 'license_model' => 'flat', 'valid_until' => '2028-10-21T00:00:00Z'],
                ],
            ],
            'validated_at' => now(),
            'pending_acks' => [],
        ]);
        unset($installation);
        $cred = new H1Credential([
            'installation_id' => 'ins_01',
            'credential_id' => 'cred_01',
            'version' => 1,
            'status' => 'active',
            'activated_at' => now(),
        ]);
        $cred->setSecretBase64Url($secret);
        $cred->save();
    }
}
