<?php

declare(strict_types=1);

namespace Tests\Unit\H1;

use App\Services\H1\Protocol\LicenseCrypto;
use App\Services\H1\Protocol\LicenseProtocol;
use App\Services\H1\Protocol\SignedHttpTransport;
use Tests\TestCase;

final class LicenseCryptoAuthVectorsTest extends TestCase
{
    public function test_normative_auth_vectors_match_byte_for_byte(): void
    {
        $path = base_path('tests/Fixtures/h1/auth-vectors.json');
        $doc = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($doc);
        $secret = (string) $doc['test_secret_base64url'];

        foreach ($doc['vectors'] as $vector) {
            $body = (string) $vector['body_utf8'];
            $digest = LicenseCrypto::contentSha256($body);
            self::assertSame($vector['content_sha256_base64url'], $digest, $vector['name'].' digest');

            $signing = (string) $vector['signing_string_utf8'];
            $signature = LicenseCrypto::sign($signing, $secret);
            self::assertSame('v1='.$vector['signature_base64url'], $signature, $vector['name'].' signature');
        }
    }

    public function test_wrong_signature_is_rejected(): void
    {
        $transport = new SignedHttpTransport;
        $secret = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8';
        $body = '{"protocol":"seo-ops-license/1","request_id":"req_vector_01","server_time":"2026-10-17T08:00:01Z","commands":[]}';
        $digest = LicenseCrypto::contentSha256($body);
        $nonce = 'AAECAwQFBgcICQoLDA0ODw';
        $ts = gmdate('Y-m-d\TH:i:s\Z');
        $respNonce = LicenseCrypto::nonce();
        $badSig = 'v1='.LicenseCrypto::base64UrlEncode(random_bytes(32));

        $this->expectExceptionMessage('Response HMAC verification failed.');
        $transport->verifyResponse(200, $body, [
            strtolower(LicenseProtocol::HEADER_PROTOCOL) => LicenseProtocol::ID,
            strtolower(LicenseProtocol::HEADER_CREDENTIAL_ID) => 'cred_01',
            strtolower(LicenseProtocol::HEADER_TIMESTAMP) => $ts,
            strtolower(LicenseProtocol::HEADER_NONCE) => $respNonce,
            strtolower(LicenseProtocol::HEADER_CONTENT_SHA256) => $digest,
            strtolower(LicenseProtocol::HEADER_SIGNATURE) => $badSig,
        ], 'cred_01', $secret, $nonce);
    }

    public function test_response_body_tamper_fails_digest(): void
    {
        $transport = new SignedHttpTransport;
        $secret = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8';
        $body = '{"ok":true}';
        $digest = LicenseCrypto::contentSha256('{"ok":false}');
        $ts = gmdate('Y-m-d\TH:i:s\Z');
        $reqNonce = LicenseCrypto::nonce();
        $respNonce = LicenseCrypto::nonce();
        $sig = LicenseCrypto::sign(
            LicenseCrypto::responseSigningString(200, $reqNonce, $ts, $respNonce, $digest),
            $secret,
        );

        $this->expectExceptionMessage('Response content digest mismatch.');
        $transport->verifyResponse(200, $body, [
            strtolower(LicenseProtocol::HEADER_PROTOCOL) => LicenseProtocol::ID,
            strtolower(LicenseProtocol::HEADER_CREDENTIAL_ID) => 'cred_01',
            strtolower(LicenseProtocol::HEADER_TIMESTAMP) => $ts,
            strtolower(LicenseProtocol::HEADER_NONCE) => $respNonce,
            strtolower(LicenseProtocol::HEADER_CONTENT_SHA256) => $digest,
            strtolower(LicenseProtocol::HEADER_SIGNATURE) => $sig,
        ], 'cred_01', $secret, $reqNonce);
    }
}
