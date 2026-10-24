<?php

declare(strict_types=1);

namespace Tests\Unit\H1;

use App\Models\H1ClientNotification;
use App\Models\H1Credential;
use App\Models\H1Installation;
use App\Models\Service;
use App\Models\Site;
use App\Services\H1\Commands\EntitlementCommandApplier;
use App\Services\H1\Enforcement\ServiceEntitlementGate;
use App\Services\H1\Notifications\ClientNotificationStore;
use App\Services\H1\Protocol\LicenseCrypto;
use App\Services\H1\Rotation\FixtureRotationSecretTransport;
use App\Services\H1\Rotation\RotationSecretTransport;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
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

    public function test_commercial_scenarios_a_through_e_gate_and_quota_enforcement(): void
    {
        $applier = app(EntitlementCommandApplier::class);
        $gate = app(ServiceEntitlementGate::class);

        // Start from unassigned enrollment (Revision 1: all commercial services disabled)
        H1Installation::query()->where('installation_id', 'ins_01')->update([
            'entitlement_revision' => 1,
            'last_command_id' => 'cmd_init_01',
            'verified_snapshot' => [
                'protocol' => 'seo-ops-license/1',
                'installation_id' => 'ins_01',
                'product' => 'seo-ops',
                'entitlement_revision' => 1,
                'plan_key' => 'unassigned',
                'valid_until' => '2029-10-21T00:00:00Z',
                'services' => [
                    'agent' => ['key' => 'agent', 'enabled' => true, 'scope' => 'global'],
                    'seo' => ['key' => 'seo', 'enabled' => false, 'license_model' => 'domain_quota', 'quota' => null, 'valid_until' => '2029-10-21T00:00:00Z'],
                    'seeding' => ['key' => 'seeding', 'enabled' => false, 'license_model' => 'flat', 'valid_until' => '2029-10-21T00:00:00Z'],
                ],
            ],
        ]);

        self::assertFalse($gate->allows('seo'));
        self::assertFalse($gate->allows('seeding'));
        self::assertFalse($gate->allows('social-automation'));
        self::assertFalse($gate->allows('pbn'));

        // Scenario A: Grant SEO 5 domains (Revision 2)
        $ackA = $applier->apply([
            'message_type' => 'entitlement_command',
            'command_id' => 'cmd_ord_a',
            'installation_id' => 'ins_01',
            'product' => 'seo-ops',
            'operation' => 'grant',
            'target_service' => 'seo',
            'entitlement_revision' => 2,
            'credential_version' => 1,
            'issued_at' => '2026-10-22T08:00:00Z',
            'effective_at' => '2026-10-22T08:00:00Z',
            'entitlement' => [
                'plan_key' => 'seo-5-domains',
                'valid_until' => '2027-10-22T08:00:00Z',
                'services' => [
                    ['key' => 'agent', 'enabled' => true, 'scope' => 'global'],
                    ['key' => 'seo', 'enabled' => true, 'scope' => 'licensed', 'license_model' => 'domain_quota', 'quota' => 5, 'valid_until' => '2027-10-22T08:00:00Z'],
                    ['key' => 'seeding', 'enabled' => false, 'scope' => 'licensed', 'license_model' => 'flat', 'quota' => null, 'valid_until' => '2027-10-22T08:00:00Z'],
                    ['key' => 'social-automation', 'enabled' => false, 'scope' => 'licensed', 'license_model' => 'flat', 'quota' => null, 'valid_until' => '2027-10-22T08:00:00Z'],
                    ['key' => 'pbn', 'enabled' => false, 'scope' => 'licensed', 'license_model' => 'flat', 'quota' => null, 'valid_until' => '2027-10-22T08:00:00Z'],
                ],
            ],
        ]);
        self::assertSame('applied', $ackA['result']);
        self::assertTrue($gate->allows('seo'));
        self::assertSame(5, $gate->seoQuota());
        self::assertFalse($gate->allows('seeding'));
        self::assertFalse($gate->allows('social-automation'));
        self::assertFalse($gate->allows('pbn'));

        Site::query()->create(['domain' => 'site1.example', 'status' => 'active']);

        // Scenario C: Add Seeding Standard (flat) to same installation (Revision 3)
        $ackC = $applier->apply([
            'message_type' => 'entitlement_command',
            'command_id' => 'cmd_ord_c',
            'installation_id' => 'ins_01',
            'product' => 'seo-ops',
            'operation' => 'grant',
            'target_service' => 'seeding',
            'entitlement_revision' => 3,
            'credential_version' => 1,
            'issued_at' => '2026-10-22T08:05:00Z',
            'effective_at' => '2026-10-22T08:05:00Z',
            'entitlement' => [
                'plan_key' => 'seeding-standard',
                'valid_until' => '2027-10-22T08:05:00Z',
                'services' => [
                    ['key' => 'agent', 'enabled' => true, 'scope' => 'global'],
                    ['key' => 'seo', 'enabled' => true, 'scope' => 'licensed', 'license_model' => 'domain_quota', 'quota' => 5, 'valid_until' => '2027-10-22T08:00:00Z'],
                    ['key' => 'seeding', 'enabled' => true, 'scope' => 'licensed', 'license_model' => 'flat', 'quota' => null, 'valid_until' => '2027-10-22T08:05:00Z'],
                ],
            ],
        ]);
        self::assertSame('applied', $ackC['result']);
        self::assertTrue($gate->allows('seo'));
        self::assertSame(5, $gate->seoQuota());
        self::assertTrue($gate->allows('seeding'));
        self::assertSame('active', Site::query()->where('domain', 'site1.example')->value('status'));

        // Scenario D: Upgrade SEO from 5 -> 30 domains (Revision 4)
        $ackD = $applier->apply([
            'message_type' => 'entitlement_command',
            'command_id' => 'cmd_ord_d',
            'installation_id' => 'ins_01',
            'product' => 'seo-ops',
            'operation' => 'change_plan',
            'target_service' => 'seo',
            'entitlement_revision' => 4,
            'credential_version' => 1,
            'issued_at' => '2026-10-22T08:10:00Z',
            'effective_at' => '2026-10-22T08:10:00Z',
            'entitlement' => [
                'plan_key' => 'seo-30-domains',
                'valid_until' => '2027-10-22T08:05:00Z',
                'services' => [
                    ['key' => 'agent', 'enabled' => true, 'scope' => 'global'],
                    ['key' => 'seo', 'enabled' => true, 'scope' => 'licensed', 'license_model' => 'domain_quota', 'quota' => 30, 'valid_until' => '2027-10-22T08:00:00Z'],
                    ['key' => 'seeding', 'enabled' => true, 'scope' => 'licensed', 'license_model' => 'flat', 'quota' => null, 'valid_until' => '2027-10-22T08:05:00Z'],
                ],
            ],
        ]);
        self::assertSame('applied', $ackD['result']);
        self::assertSame(30, $gate->seoQuota());
        self::assertTrue($gate->allows('seeding'));
        self::assertSame('active', Site::query()->where('domain', 'site1.example')->value('status'));

        // Scenario E: Renew SEO (Revision 5) then Revoke Seeding (Revision 6)
        $ackERenew = $applier->apply([
            'message_type' => 'entitlement_command',
            'command_id' => 'cmd_ord_e_renew',
            'installation_id' => 'ins_01',
            'product' => 'seo-ops',
            'operation' => 'renew',
            'target_service' => 'seo',
            'entitlement_revision' => 5,
            'credential_version' => 1,
            'issued_at' => '2026-10-22T08:15:00Z',
            'effective_at' => '2026-10-22T08:15:00Z',
            'entitlement' => [
                'plan_key' => 'seo-30-domains',
                'valid_until' => '2028-10-21T08:00:00Z',
                'services' => [
                    ['key' => 'agent', 'enabled' => true, 'scope' => 'global'],
                    ['key' => 'seo', 'enabled' => true, 'scope' => 'licensed', 'license_model' => 'domain_quota', 'quota' => 30, 'valid_until' => '2028-10-21T08:00:00Z'],
                    ['key' => 'seeding', 'enabled' => true, 'scope' => 'licensed', 'license_model' => 'flat', 'quota' => null, 'valid_until' => '2027-10-22T08:05:00Z'],
                ],
            ],
        ]);
        self::assertSame('applied', $ackERenew['result']);

        $ackERevoke = $applier->apply([
            'message_type' => 'entitlement_command',
            'command_id' => 'cmd_ord_e_revoke',
            'installation_id' => 'ins_01',
            'product' => 'seo-ops',
            'operation' => 'revoke',
            'target_service' => 'seeding',
            'entitlement_revision' => 6,
            'credential_version' => 1,
            'issued_at' => '2026-10-22T08:20:00Z',
            'effective_at' => '2026-10-22T08:20:00Z',
            'entitlement' => [
                'plan_key' => 'seo-30-domains',
                'valid_until' => '2028-10-21T08:00:00Z',
                'services' => [
                    ['key' => 'agent', 'enabled' => true, 'scope' => 'global'],
                    ['key' => 'seo', 'enabled' => true, 'scope' => 'licensed', 'license_model' => 'domain_quota', 'quota' => 30, 'valid_until' => '2028-10-21T08:00:00Z'],
                    ['key' => 'seeding', 'enabled' => false, 'scope' => 'licensed', 'license_model' => 'flat', 'quota' => null, 'valid_until' => '2027-10-22T08:05:00Z', 'revoked_at' => '2026-10-22T08:20:00Z'],
                ],
            ],
        ]);
        self::assertSame('applied', $ackERevoke['result']);
        self::assertTrue($gate->allows('seo'));
        self::assertSame(30, $gate->seoQuota());
        self::assertFalse($gate->allows('seeding'));
        self::assertSame('active', Site::query()->where('domain', 'site1.example')->value('status'));
    }

    public function test_suspend_resume_revoke_notifications_idempotent_and_scoped(): void
    {
        Schema::dropIfExists('h1_client_notifications');
        Schema::create('h1_client_notifications', function (Blueprint $table): void {
            $table->id();
            $table->string('notification_id', 128)->unique();
            $table->string('installation_id', 128);
            $table->string('command_id', 128)->nullable();
            $table->string('action', 32);
            $table->string('service_key', 64)->nullable();
            $table->text('message');
            $table->string('status_before', 32)->nullable();
            $table->string('status_after', 32)->nullable();
            $table->unsignedBigInteger('entitlement_revision')->default(0);
            $table->timestamp('issued_at')->nullable();
            $table->boolean('display_active')->default(true);
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();
        });

        $applier = app(EntitlementCommandApplier::class);
        $suspendCmd = $this->lifecycleCommand(
            commandId: 'cmd_suspend_seo',
            revision: 2,
            operation: 'change_plan',
            target: 'seo',
            services: $this->sampleServicesWithSeoEnabled(false),
            notification: [
                'id' => 'cmd_suspend_seo',
                'action' => 'suspend',
                'service_key' => 'seo',
                'message' => 'Tạm ngưng SEO bảo trì',
                'status_before' => 'active',
                'status_after' => 'suspended',
                'entitlement_revision' => 2,
                'issued_at' => '2026-10-24T09:00:00Z',
            ],
        );

        self::assertSame('applied', $applier->apply($suspendCmd)['result']);
        self::assertSame('duplicate', $applier->apply($suspendCmd)['result']);
        self::assertSame(1, H1ClientNotification::query()->count());

        $resumeCmd = $this->lifecycleCommand(
            commandId: 'cmd_resume_seo',
            revision: 3,
            operation: 'change_plan',
            target: 'seo',
            services: $this->sampleServicesWithSeoEnabled(true),
            notification: [
                'id' => 'cmd_resume_seo',
                'action' => 'resume',
                'service_key' => 'seo',
                'message' => 'Đã khôi phục SEO',
                'status_before' => 'suspended',
                'status_after' => 'active',
                'entitlement_revision' => 3,
                'issued_at' => '2026-10-24T10:00:00Z',
            ],
        );
        self::assertSame('applied', $applier->apply($resumeCmd)['result']);

        $services = $this->sampleServices();
        $services[2]['enabled'] = false;
        $services[2]['revoked_at'] = '2026-10-24T11:00:00Z';
        $revokeSeeding = $this->lifecycleCommand(
            commandId: 'cmd_revoke_seeding',
            revision: 4,
            operation: 'revoke',
            target: 'seeding',
            services: $services,
            notification: [
                'id' => 'cmd_revoke_seeding',
                'action' => 'revoke',
                'service_key' => 'seeding',
                'message' => 'Thu hồi Seeding',
                'status_before' => 'active',
                'status_after' => 'revoked',
                'entitlement_revision' => 4,
                'issued_at' => '2026-10-24T11:00:00Z',
            ],
        );
        self::assertSame('applied', $applier->apply($revokeSeeding)['result']);
        self::assertSame('duplicate', $applier->apply($revokeSeeding)['result']);

        $latest = app(ClientNotificationStore::class)->latestByService('ins_01');
        self::assertSame('Đã khôi phục SEO', $latest['seo']['message']);
        self::assertSame('resume', $latest['seo']['action']);
        self::assertSame('Thu hồi Seeding', $latest['seeding']['message']);
        self::assertSame(3, H1ClientNotification::query()->count());
    }

    /**
     * @param  list<array<string, mixed>>  $services
     * @param  array<string, mixed>  $notification
     * @return array<string, mixed>
     */
    private function lifecycleCommand(
        string $commandId,
        int $revision,
        string $operation,
        string $target,
        array $services,
        array $notification,
    ): array {
        return [
            'message_type' => 'entitlement_command',
            'command_id' => $commandId,
            'installation_id' => 'ins_01',
            'product' => 'seo-ops',
            'operation' => $operation,
            'target_service' => $target,
            'entitlement_revision' => $revision,
            'credential_version' => 1,
            'issued_at' => $notification['issued_at'] ?? '2026-10-24T09:00:00Z',
            'effective_at' => $notification['issued_at'] ?? '2026-10-24T09:00:00Z',
            'entitlement' => [
                'plan_key' => 'seo-pro-30',
                'valid_until' => '2029-10-21T00:00:00Z',
                'grace_until' => null,
                'revoked_at' => null,
                'services' => $services,
            ],
            'client_notification' => $notification,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sampleServicesWithSeoEnabled(bool $enabled): array
    {
        $services = $this->sampleServices();
        $services[1]['enabled'] = $enabled;

        return $services;
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
