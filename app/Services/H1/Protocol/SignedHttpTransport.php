<?php

declare(strict_types=1);

namespace App\Services\H1\Protocol;

use App\Services\H1\Endpoint\ServerEndpointValidator;
use App\Services\H1\Exceptions\H1TransportException;
use Illuminate\Support\Facades\Http;

/**
 * Signs outbound seo-ops-license/1 requests and verifies signed responses.
 */
final class SignedHttpTransport
{
    /**
     * @param  array<string, string>  $extraHeaders
     * @return array{status: int, body: string, headers: array<string, string>, decoded: array<string, mixed>}
     */
    public function postSigned(
        string $absoluteUrl,
        string $path,
        string $rawBody,
        string $credentialId,
        string $secretBase64Url,
        array $extraHeaders = [],
    ): array {
        $this->assertUrl($absoluteUrl);
        if (strlen($rawBody) > (int) config('h1-license.max_body_bytes', 262144)) {
            throw new H1TransportException('payload_too_large', 'Request body exceeds H1 size bound.');
        }

        $timestamp = LicenseCrypto::timestamp();
        $nonce = LicenseCrypto::nonce();
        $digest = LicenseCrypto::contentSha256($rawBody);
        $signing = LicenseCrypto::requestSigningString('POST', $path, $timestamp, $nonce, $digest);
        $signature = LicenseCrypto::sign($signing, $secretBase64Url);

        $headers = array_merge([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            LicenseProtocol::HEADER_PROTOCOL => LicenseProtocol::ID,
            LicenseProtocol::HEADER_CREDENTIAL_ID => $credentialId,
            LicenseProtocol::HEADER_TIMESTAMP => $timestamp,
            LicenseProtocol::HEADER_NONCE => $nonce,
            LicenseProtocol::HEADER_CONTENT_SHA256 => $digest,
            LicenseProtocol::HEADER_SIGNATURE => $signature,
        ], $extraHeaders);

        try {
            $response = Http::withHeaders($headers)
                ->timeout((int) config('h1-license.timeout_seconds', 15))
                ->withBody($rawBody, 'application/json')
                ->post($absoluteUrl);
        } catch (\Throwable) {
            throw new H1TransportException('server_offline', 'OPS-SERVER license endpoint is unreachable.');
        }

        $body = (string) $response->body();
        if (strlen($body) > (int) config('h1-license.max_body_bytes', 262144)) {
            throw new H1TransportException('payload_too_large', 'Response body exceeds H1 size bound.');
        }

        $headerMap = [];
        foreach ($response->headers() as $name => $values) {
            $headerMap[strtolower((string) $name)] = is_array($values) ? (string) ($values[0] ?? '') : (string) $values;
        }

        $this->verifyResponse($response->status(), $body, $headerMap, $credentialId, $secretBase64Url, $nonce);

        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            throw new H1TransportException('invalid_json', 'License response is not valid JSON.');
        }

        return [
            'status' => $response->status(),
            'body' => $body,
            'headers' => $headerMap,
            'decoded' => $decoded,
        ];
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function verifyResponse(
        int $status,
        string $body,
        array $headers,
        string $credentialId,
        string $secretBase64Url,
        string $requestNonce,
    ): void {
        $protocol = $headers[strtolower(LicenseProtocol::HEADER_PROTOCOL)] ?? '';
        $responseCredential = $headers[strtolower(LicenseProtocol::HEADER_CREDENTIAL_ID)] ?? '';
        $responseTimestamp = $headers[strtolower(LicenseProtocol::HEADER_TIMESTAMP)] ?? '';
        $responseNonce = $headers[strtolower(LicenseProtocol::HEADER_NONCE)] ?? '';
        $contentSha = $headers[strtolower(LicenseProtocol::HEADER_CONTENT_SHA256)] ?? '';
        $signature = $headers[strtolower(LicenseProtocol::HEADER_SIGNATURE)] ?? '';

        if ($signature === '') {
            if ($status >= 200 && $status < 300) {
                throw new H1TransportException('unsigned_success', 'Successful license response must be signed.');
            }

            return;
        }

        if ($protocol !== LicenseProtocol::ID) {
            throw new H1TransportException('protocol_mismatch', 'Response protocol header mismatch.');
        }
        if ($responseCredential !== $credentialId) {
            throw new H1TransportException('credential_mismatch', 'Response credential id mismatch.');
        }
        if ($responseTimestamp === '' || $responseNonce === '' || $contentSha === '') {
            throw new H1TransportException('missing_auth_headers', 'Signed response missing auth headers.');
        }

        $expectedDigest = LicenseCrypto::contentSha256($body);
        if (! LicenseCrypto::signaturesMatch($expectedDigest, $contentSha)) {
            throw new H1TransportException('digest_mismatch', 'Response content digest mismatch.');
        }

        $expected = LicenseCrypto::sign(
            LicenseCrypto::responseSigningString($status, $requestNonce, $responseTimestamp, $responseNonce, $contentSha),
            $secretBase64Url,
        );
        if (! LicenseCrypto::signaturesMatch($expected, $signature)) {
            throw new H1TransportException('signature_invalid', 'Response HMAC verification failed.');
        }

        $this->assertTimestampFresh($responseTimestamp);
    }

    private function assertUrl(string $url): void
    {
        if ($url === '') {
            throw new H1TransportException('server_unconfigured', 'OPS-SERVER base URL is not configured.');
        }

        $requireHttps = (bool) config('h1-license.require_https', true);
        try {
            ServerEndpointValidator::validate($url, $requireHttps, allowPath: true);
        } catch (\InvalidArgumentException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'HTTPS')) {
                throw new H1TransportException('insecure_transport', 'H1 license transport requires HTTPS.');
            }
            throw new H1TransportException('invalid_url', $msg);
        }
    }

    private function assertTimestampFresh(string $timestamp): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $timestamp, new \DateTimeZone('UTC'));
        if (! $parsed instanceof \DateTimeImmutable) {
            throw new H1TransportException('timestamp_invalid', 'Response timestamp is not RFC3339 Z.');
        }
        $skew = (int) config('h1-license.timestamp_skew_seconds', 300);
        $delta = abs(time() - $parsed->getTimestamp());
        if ($delta > $skew) {
            throw new H1TransportException('timestamp_skew', 'Response timestamp outside allowed skew.');
        }
    }
}
