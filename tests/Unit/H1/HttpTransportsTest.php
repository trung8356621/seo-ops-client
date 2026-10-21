<?php

declare(strict_types=1);

namespace Tests\Unit\H1;

use App\Models\H1Credential;
use App\Models\H1Installation;
use App\Services\H1\Enrollment\HttpEnrollmentTransport;
use App\Services\H1\Exceptions\H1CommandException;
use App\Services\H1\Exceptions\H1EnrollmentException;
use App\Services\H1\Protocol\LicenseCrypto;
use App\Services\H1\Protocol\LicenseProtocol;
use App\Services\H1\Protocol\SignedHttpTransport;
use App\Services\H1\Rotation\HttpRotationSecretTransport;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HttpTransportsTest extends TestCase
{
    use UsesH1LicenseSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootH1LicenseSchema();
    }

    public function test_enrollment_transport_enforces_https_when_configured(): void
    {
        config(['h1-license.require_https' => true, 'h1-license.base_url' => 'http://insecure.local']);

        $transport = new HttpEnrollmentTransport();

        $this->expectException(H1EnrollmentException::class);
        $this->expectExceptionMessage('H1 license transport requires HTTPS.');
        $transport->consume('tok_test123', '1.0.0');
    }

    public function test_enrollment_transport_consumes_remote_receipt_successfully(): void
    {
        config(['h1-license.require_https' => false, 'h1-license.base_url' => 'http://test.local']);

        Http::fake([
            'http://test.local/api/v1/enrollment/consume' => Http::response([
                'success' => true,
                'code' => 'enrolled',
                'installation_id' => 'ins_unit_01',
                'credential' => [
                    'id' => 'cred_unit_01',
                    'version' => 1,
                    'secret' => 'unit-secret-123',
                ],
                'is_retry' => false,
            ], 200),
        ]);

        $transport = new HttpEnrollmentTransport();
        $res = $transport->consume('tok_valid123', '12.50.0');

        $this->assertTrue($res['success']);
        $this->assertSame('ins_unit_01', $res['installation_id']);
        $this->assertSame('cred_unit_01', $res['credential']['id']);
    }

    public function test_enrollment_transport_throws_on_server_rejection(): void
    {
        config(['h1-license.require_https' => false, 'h1-license.base_url' => 'http://test.local']);

        Http::fake([
            'http://test.local/api/v1/enrollment/consume' => Http::response([
                'success' => false,
                'code' => 'token_expired',
                'detail' => 'Enrollment token has expired.',
            ], 410),
        ]);

        $transport = new HttpEnrollmentTransport();

        $this->expectException(H1EnrollmentException::class);
        $this->expectExceptionMessage('Enrollment token has expired.');
        $transport->consume('tok_expired123', '12.50.0');
    }

    public function test_rotation_transport_fetches_replacement_secret_successfully(): void
    {
        config(['h1-license.require_https' => false, 'h1-license.base_url' => 'http://test.local']);

        $installation = new H1Installation();
        $installation->installation_id = 'ins_rot_01';
        $installation->save();

        $cred = new H1Credential();
        $cred->installation_id = 'ins_rot_01';
        $cred->credential_id = 'cred_from_01';
        $cred->version = 1;
        $cred->status = H1Credential::STATUS_ACTIVE;
        $fromSecret = 'dGhpcy1pcy1hLTI1Ni1iaXQtcmFuZG9tLXNlY3JldC0xMjM=';
        $cred->setSecretBase64Url($fromSecret);
        $cred->save();

        // Create signed response for rotation secret
        $respBody = json_encode([
            'success' => true,
            'protocol' => LicenseProtocol::ID,
            'installation_id' => 'ins_rot_01',
            'rotation_id' => 'rot_01',
            'secret' => 'new-secret-456',
            'to_credential' => [
                'id' => 'cred_to_02',
                'version' => 2,
                'secret' => 'new-secret-456',
            ],
        ], JSON_THROW_ON_ERROR);

        Http::fake(function ($request) use ($fromSecret, $respBody) {
            $nonce = $request->header(LicenseProtocol::HEADER_NONCE)[0];
            $respTimestamp = LicenseCrypto::timestamp();
            $respNonce = LicenseCrypto::nonce();
            $respSha = LicenseCrypto::contentSha256($respBody);
            $respSigning = LicenseCrypto::responseSigningString(200, $nonce, $respTimestamp, $respNonce, $respSha);
            $respSig = LicenseCrypto::sign($respSigning, $fromSecret);

            return Http::response($respBody, 200, [
                'Content-Type' => 'application/json',
                LicenseProtocol::HEADER_PROTOCOL => LicenseProtocol::ID,
                LicenseProtocol::HEADER_CREDENTIAL_ID => 'cred_from_01',
                LicenseProtocol::HEADER_TIMESTAMP => $respTimestamp,
                LicenseProtocol::HEADER_NONCE => $respNonce,
                LicenseProtocol::HEADER_CONTENT_SHA256 => $respSha,
                LicenseProtocol::HEADER_SIGNATURE => $respSig,
            ]);
        });

        $transport = new HttpRotationSecretTransport(new SignedHttpTransport());
        $secret = $transport->fetchReplacementSecret('ins_rot_01', 'rot_01', 'cred_from_01');

        $this->assertSame('new-secret-456', $secret);
    }
}
