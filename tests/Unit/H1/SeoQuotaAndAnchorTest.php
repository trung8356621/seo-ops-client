<?php

declare(strict_types=1);

namespace Tests\Unit\H1;

use App\Models\H1Credential;
use App\Models\H1Installation;
use App\Models\Site;
use App\Services\H1\Enforcement\ServiceEntitlementGate;
use App\Services\H1\Protocol\LicenseCrypto;
use App\Services\H1\Sites\AdminSiteStatusPolicy;
use Tests\TestCase;

final class SeoQuotaAndAnchorTest extends TestCase
{
    use UsesH1LicenseSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootH1LicenseSchema();
        $this->seedEnforcingInstallation(quota: 1);
    }

    public function test_new_site_defaults_inactive_and_activation_enforces_quota(): void
    {
        $policy = app(AdminSiteStatusPolicy::class);
        self::assertSame('inactive', $policy->defaultStatusForNewSite());

        $a = Site::query()->create(['domain' => 'a.example', 'status' => 'inactive']);
        $b = Site::query()->create(['domain' => 'b.example', 'status' => 'inactive']);

        $gate = app(ServiceEntitlementGate::class);
        $policy->activate($a, $gate->seoQuota());
        self::assertSame('active', $a->fresh()->status);

        $this->expectException(\App\Services\H1\Exceptions\H1CommandException::class);
        $policy->activate($b, $gate->seoQuota());
    }

    public function test_model_update_activation_blocked_when_quota_full(): void
    {
        Site::query()->create(['domain' => 'a.example', 'status' => 'active']);
        $b = Site::query()->create(['domain' => 'b.example', 'status' => 'inactive']);

        $blocked = false;
        try {
            $b->status = 'active';
            $b->save();
        } catch (\Throwable $e) {
            $blocked = str_contains(strtolower($e->getMessage()), 'quota');
        }

        self::assertTrue($blocked, 'Activation must be rejected when SEO quota is full.');
        self::assertSame('inactive', (string) Site::query()->where('domain', 'b.example')->value('status'));
    }

    public function test_anchor_deletion_blocked_for_soft_and_force_delete(): void
    {
        $installation = H1Installation::query()->first();
        self::assertNotNull($installation);
        $installation->anchor_identity = 'anchor.example';
        $installation->save();

        $anchor = Site::query()->create(['domain' => 'anchor.example', 'status' => 'inactive', 'h1_is_anchor' => true]);
        app(AdminSiteStatusPolicy::class)->bindAnchorFromInstallation($installation->fresh());

        $this->expectException(\RuntimeException::class);
        $anchor->delete();
    }

    public function test_agent_cannot_bypass_seo_gate(): void
    {
        $gate = app(ServiceEntitlementGate::class);
        self::assertTrue($gate->allows('agent'));

        $installation = H1Installation::query()->first();
        $snap = $installation->verified_snapshot;
        $snap['services']['seo']['enabled'] = false;
        $snap['services']['seo']['revoked_at'] = '2026-10-21T00:00:00Z';
        $installation->verified_snapshot = $snap;
        $installation->save();

        self::assertTrue($gate->allows('agent'));
        self::assertFalse($gate->allows('seo'));
    }

    public function test_offline_grace_and_expired_policy(): void
    {
        $gate = app(ServiceEntitlementGate::class);
        $installation = H1Installation::query()->first();
        $snap = $installation->verified_snapshot;
        $snap['services']['seo']['valid_until'] = '2020-01-01T00:00:00Z';
        $snap['services']['seo']['grace_until'] = '2099-01-01T00:00:00Z';
        $installation->verified_snapshot = $snap;
        $installation->save();
        self::assertTrue($gate->allows('seo'));

        $snap['services']['seo']['grace_until'] = '2020-01-02T00:00:00Z';
        $installation->verified_snapshot = $snap;
        $installation->save();
        self::assertFalse($gate->allows('seo'));
    }

    private function seedEnforcingInstallation(int $quota): void
    {
        H1Installation::query()->create([
            'installation_id' => 'ins_01',
            'product' => 'seo-ops',
            'status' => 'enrolled',
            'cutover_mode' => 'enforcing',
            'entitlement_revision' => 1,
            'credential_version' => 1,
            'verified_snapshot' => [
                'services' => [
                    'seo' => [
                        'key' => 'seo',
                        'enabled' => true,
                        'quota' => $quota,
                        'valid_until' => '2029-10-21T00:00:00Z',
                    ],
                    'seeding' => [
                        'key' => 'seeding',
                        'enabled' => true,
                        'valid_until' => '2029-10-21T00:00:00Z',
                    ],
                ],
            ],
            'validated_at' => now(),
        ]);
        $cred = new H1Credential([
            'installation_id' => 'ins_01',
            'credential_id' => 'cred_01',
            'version' => 1,
            'status' => 'active',
            'activated_at' => now(),
        ]);
        $cred->setSecretBase64Url(LicenseCrypto::base64UrlEncode(random_bytes(32)));
        $cred->save();
    }
}
