<?php

declare(strict_types=1);

namespace Tests\Unit\H1;

use App\Models\H1Credential;
use App\Models\H1Installation;
use App\Models\Service;
use App\Services\H1\Enrollment\EnrollmentConsumer;
use App\Services\H1\Enrollment\FixtureEnrollmentTransport;
use App\Services\H1\Exceptions\H1EnrollmentException;
use App\Services\H1\Protocol\LicenseCrypto;
use Tests\TestCase;

final class EnrollmentConsumerTest extends TestCase
{
    use UsesH1LicenseSchema;

    private FixtureEnrollmentTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootH1LicenseSchema();
        Service::query()->create(['name' => 'SEO', 'slug' => 'seo', 'is_active' => true]);
        Service::query()->create(['name' => 'Seeding', 'slug' => 'seeding', 'is_active' => true]);

        $this->transport = new FixtureEnrollmentTransport;
        $this->app->instance(FixtureEnrollmentTransport::class, $this->transport);
        $this->app->instance(\App\Services\H1\Enrollment\EnrollmentTransport::class, $this->transport);
    }

    public function test_first_enrollment_persists_encrypted_credential(): void
    {
        $secret = LicenseCrypto::base64UrlEncode(random_bytes(32));
        $this->transport->registerToken('tok_first_enroll_abcdef', [
            'installation_id' => 'ins_01',
            'product' => 'seo-ops',
            'credential' => ['id' => 'cred_01', 'version' => 1, 'secret' => $secret],
            'entitlement' => [
                'plan_key' => 'seo-pro-30',
                'valid_until' => '2029-10-21T00:00:00Z',
                'services' => $this->sampleServices(),
            ],
            'entitlement_revision' => 1,
            'anchor_identity' => 'anchor.example',
        ]);

        $result = app(EnrollmentConsumer::class)->enroll('tok_first_enroll_abcdef');
        self::assertFalse($result['is_retry']);
        self::assertSame('ins_01', $result['installation_id']);

        $installation = H1Installation::query()->first();
        self::assertNotNull($installation);
        self::assertSame(1, (int) $installation->entitlement_revision);
        self::assertSame('anchor.example', $installation->anchor_identity);

        $cred = H1Credential::query()->first();
        self::assertNotNull($cred);
        self::assertSame($secret, $cred->secretBase64Url());
        self::assertStringNotContainsString($secret, (string) $cred->secret_encrypted);
    }

    public function test_enrollment_retry_is_idempotent_for_same_installation(): void
    {
        $secret = LicenseCrypto::base64UrlEncode(random_bytes(32));
        $payload = [
            'installation_id' => 'ins_01',
            'credential' => ['id' => 'cred_01', 'version' => 1, 'secret' => $secret],
            'entitlement' => [
                'plan_key' => 'seo-pro-30',
                'valid_until' => '2029-10-21T00:00:00Z',
                'services' => $this->sampleServices(),
            ],
            'entitlement_revision' => 1,
        ];
        $this->transport->registerToken('tok_retry_abcdef123456', $payload);

        $first = app(EnrollmentConsumer::class)->enroll('tok_retry_abcdef123456');
        $second = app(EnrollmentConsumer::class)->enroll('tok_retry_abcdef123456');
        self::assertFalse($first['is_retry']);
        self::assertTrue($second['is_retry']);
        self::assertSame(1, H1Installation::query()->count());
        self::assertSame(1, H1Credential::query()->count());
    }

    public function test_expired_token_is_rejected(): void
    {
        $this->transport->registerToken('tok_expired_abcdef123456', [
            'installation_id' => 'ins_x',
            'credential' => ['id' => 'cred_x', 'version' => 1, 'secret' => LicenseCrypto::base64UrlEncode(random_bytes(32))],
            'entitlement' => ['services' => []],
            'entitlement_revision' => 1,
        ], expired: true);

        $this->expectException(H1EnrollmentException::class);
        $this->expectExceptionMessage('expired');
        app(EnrollmentConsumer::class)->enroll('tok_expired_abcdef123456');
    }

    public function test_duplicate_enrollment_different_installation_blocked(): void
    {
        $secret = LicenseCrypto::base64UrlEncode(random_bytes(32));
        $this->transport->registerToken('tok_a_abcdef1234567890', [
            'installation_id' => 'ins_01',
            'credential' => ['id' => 'cred_01', 'version' => 1, 'secret' => $secret],
            'entitlement' => ['plan_key' => 'p', 'valid_until' => '2029-10-21T00:00:00Z', 'services' => $this->sampleServices()],
            'entitlement_revision' => 1,
        ]);
        app(EnrollmentConsumer::class)->enroll('tok_a_abcdef1234567890');

        $this->transport->registerToken('tok_b_abcdef1234567890', [
            'installation_id' => 'ins_02',
            'credential' => ['id' => 'cred_02', 'version' => 1, 'secret' => LicenseCrypto::base64UrlEncode(random_bytes(32))],
            'entitlement' => ['plan_key' => 'p', 'valid_until' => '2029-10-21T00:00:00Z', 'services' => $this->sampleServices()],
            'entitlement_revision' => 1,
        ]);

        $this->expectException(H1EnrollmentException::class);
        app(EnrollmentConsumer::class)->enroll('tok_b_abcdef1234567890');
    }

    public function test_blocked_transport_reports_missing_server_route(): void
    {
        $this->app->instance(
            \App\Services\H1\Enrollment\EnrollmentTransport::class,
            new \App\Services\H1\Enrollment\BlockedEnrollmentTransport,
        );

        try {
            app(EnrollmentConsumer::class)->enroll('tok_live_abcdef12345678');
            self::fail('Expected blocked transport exception');
        } catch (H1EnrollmentException $e) {
            self::assertSame('server_endpoint_missing', $e->errorCode);
            self::assertStringContainsString('/api/v1/enrollment/consume', $e->getMessage());
        }
    }
}
