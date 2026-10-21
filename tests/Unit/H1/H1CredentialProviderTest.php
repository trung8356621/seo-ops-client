<?php

declare(strict_types=1);

namespace Tests\Unit\H1;

use App\Models\H1Credential;
use App\Models\H1Installation;
use App\Services\ConfigurationSync\H1InstallationMachineCredentialProvider;
use App\Services\H1\Protocol\LicenseCrypto;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\InstallationMachineCredentialProvider;
use Tests\TestCase;

final class H1CredentialProviderTest extends TestCase
{
    use UsesH1LicenseSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootH1LicenseSchema();
    }

    public function test_provider_returns_null_when_not_enrolled(): void
    {
        $provider = app(InstallationMachineCredentialProvider::class);
        self::assertInstanceOf(H1InstallationMachineCredentialProvider::class, $provider);
        self::assertNull($provider->current());
    }

    public function test_g2_obtains_enrolled_credential(): void
    {
        $secret = LicenseCrypto::base64UrlEncode(random_bytes(32));
        H1Installation::query()->create([
            'installation_id' => 'ins_01',
            'product' => 'seo-ops',
            'status' => 'enrolled',
            'cutover_mode' => 'pre_enrollment',
            'entitlement_revision' => 1,
            'credential_version' => 1,
            'verified_snapshot' => ['services' => []],
            'validated_at' => now(),
        ]);
        $cred = new H1Credential([
            'installation_id' => 'ins_01',
            'credential_id' => 'cred_01',
            'version' => 1,
            'status' => 'active',
            'activated_at' => now(),
        ]);
        $cred->setSecretBase64Url($secret);
        $cred->save();

        $current = app(InstallationMachineCredentialProvider::class)->current();
        self::assertNotNull($current);
        self::assertSame('ins_01', $current->installationId());
        self::assertSame('cred_01', $current->credentialId());
        self::assertSame($secret, $current->secretBase64Url());
    }
}
