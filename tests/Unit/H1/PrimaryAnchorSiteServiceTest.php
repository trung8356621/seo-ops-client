<?php

declare(strict_types=1);

namespace Tests\Unit\H1;

use App\Models\H1Credential;
use App\Models\H1Installation;
use App\Models\Site;
use App\Models\User;
use App\Services\H1\Protocol\LicenseCrypto;
use App\Services\H1\Sites\AdminSiteStatusPolicy;
use App\Services\H1\Sites\PrimaryAnchorSiteService;
use App\Services\H1\Sites\PublicWebsiteProbe;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PrimaryAnchorSiteServiceTest extends TestCase
{
    use UsesH1LicenseSchema;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootH1LicenseSchema();

        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('staff');
            $table->string('status')->default('normal');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        $this->app->instance(PublicWebsiteProbe::class, new PublicWebsiteProbe(
            static fn (string $host): array => match (true) {
                str_contains($host, 'private') => ['10.0.0.8'],
                default => ['93.184.216.34'],
            },
        ));

        $this->owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner_anchor_'.bin2hex(random_bytes(3)).'@test.com',
            'password' => bcrypt('password'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);
    }

    public function test_register_online_anchor_succeeds(): void
    {
        Http::fake([
            'https://anchor-ok.example.com/*' => Http::response('ok', 200),
        ]);

        $result = app(PrimaryAnchorSiteService::class)->registerFromInstaller(
            'https://www.anchor-ok.example.com',
            (int) $this->owner->id,
        );

        self::assertTrue($result['ok'], $result['message'] ?? '');
        self::assertSame('anchor-ok.example.com', $result['domain']);
        self::assertTrue($result['created'] ?? false);

        $site = Site::query()->where('domain', 'anchor-ok.example.com')->first();
        self::assertNotNull($site);
        self::assertTrue((bool) $site->h1_is_anchor);
        self::assertSame('inactive', (string) $site->status);
    }

    public function test_http_404_is_rejected(): void
    {
        Http::fake([
            'https://missing.example.com/*' => Http::response('gone', 404),
            'http://missing.example.com/*' => Http::response('gone', 404),
        ]);

        $result = app(PrimaryAnchorSiteService::class)->registerFromInstaller(
            'missing.example.com',
            (int) $this->owner->id,
        );

        self::assertFalse($result['ok']);
        self::assertStringContainsString('404', (string) $result['message']);
        self::assertSame(0, Site::query()->count());
    }

    public function test_offline_domain_is_rejected(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
        });

        $result = app(PrimaryAnchorSiteService::class)->registerFromInstaller(
            'offline.example.com',
            (int) $this->owner->id,
        );

        self::assertFalse($result['ok']);
        self::assertStringContainsString('kết nối', mb_strtolower((string) $result['message']));
        self::assertSame(0, Site::query()->count());
    }

    public function test_private_ip_ssrf_is_blocked(): void
    {
        $rawIp = app(PrimaryAnchorSiteService::class)->registerFromInstaller(
            '127.0.0.1',
            (int) $this->owner->id,
        );
        self::assertFalse($rawIp['ok']);

        $privateDns = app(PrimaryAnchorSiteService::class)->registerFromInstaller(
            'private-ssrf.example.com',
            (int) $this->owner->id,
        );
        self::assertFalse($privateDns['ok']);
        self::assertStringContainsString('SSRF', (string) $privateDns['message']);
        self::assertSame(0, Site::query()->count());
    }

    public function test_retry_does_not_duplicate_anchor_site(): void
    {
        Http::fake([
            'https://retry.example.com/*' => Http::response('ok', 200),
        ]);

        $service = app(PrimaryAnchorSiteService::class);
        $first = $service->registerFromInstaller('retry.example.com', (int) $this->owner->id);
        $second = $service->registerFromInstaller('retry.example.com', (int) $this->owner->id);

        self::assertTrue($first['ok']);
        self::assertTrue($second['ok']);
        self::assertFalse($second['created'] ?? true);
        self::assertSame(1, Site::query()->count());
        self::assertSame(1, Site::query()->where('h1_is_anchor', true)->count());
    }

    public function test_anchor_consumes_quota_while_inactive_and_blocks_delete_rename(): void
    {
        $this->seedEnforcingInstallation(quota: 1);

        Http::fake([
            'https://quota.example.com/*' => Http::response('ok', 200),
        ]);

        $service = app(PrimaryAnchorSiteService::class);
        $result = $service->registerFromInstaller('quota.example.com', (int) $this->owner->id);
        self::assertTrue($result['ok']);

        $installation = H1Installation::query()->first();
        self::assertNotNull($installation);
        $installation->anchor_identity = 'quota.example.com';
        $installation->save();
        app(AdminSiteStatusPolicy::class)->bindAnchorFromInstallation($installation->fresh());

        $policy = app(AdminSiteStatusPolicy::class);
        self::assertSame(1, $policy->quotaConsumedCount());

        $anchor = Site::query()->where('domain', 'quota.example.com')->first();
        self::assertNotNull($anchor);
        self::assertSame('inactive', (string) $anchor->status);

        $other = Site::query()->create([
            'user_id' => $this->owner->id,
            'domain' => 'other.example.com',
            'status' => 'inactive',
            'ssl' => true,
        ]);

        $blocked = false;
        try {
            $policy->activate($other, 1);
        } catch (\Throwable) {
            $blocked = true;
        }
        self::assertTrue($blocked, 'Inactive anchor must still consume the only quota slot.');

        $deleteBlocked = false;
        try {
            $anchor->delete();
        } catch (\Throwable) {
            $deleteBlocked = true;
        }
        self::assertTrue($deleteBlocked);

        $renameBlocked = false;
        try {
            $anchor->domain = 'renamed.example.com';
            $anchor->save();
        } catch (\Throwable) {
            $renameBlocked = true;
        }
        self::assertTrue($renameBlocked);
        self::assertSame('quota.example.com', (string) $anchor->fresh()->domain);

        $other->delete();
        self::assertSoftDeleted($other);
    }

    private function seedEnforcingInstallation(int $quota): void
    {
        H1Installation::query()->create([
            'installation_id' => 'ins_anchor_01',
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
                ],
            ],
            'validated_at' => now(),
        ]);
        $cred = new H1Credential([
            'installation_id' => 'ins_anchor_01',
            'credential_id' => 'cred_anchor_01',
            'version' => 1,
            'status' => 'active',
            'activated_at' => now(),
        ]);
        $cred->setSecretBase64Url(LicenseCrypto::base64UrlEncode(random_bytes(32)));
        $cred->save();
    }
}
