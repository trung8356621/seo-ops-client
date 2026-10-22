<?php

declare(strict_types=1);

namespace App\Services\H1\Enrollment;

use App\Services\H1\Endpoint\ServerEndpointValidator;
use App\Services\H1\Exceptions\H1EnrollmentException;
use App\Services\H1\Persistence\InstallationStateRepository;
use Illuminate\Support\Facades\Http;

/**
 * Production HTTP enrollment transport for consuming OPS-SERVER receipts.
 */
final class HttpEnrollmentTransport implements EnrollmentTransport
{
    public function consume(string $enrollmentToken, string $clientVersion): array
    {
        $installations = app(InstallationStateRepository::class);
        $base = $installations->getEffectiveControlServerUrl();
        $this->assertUrl($base);

        $path = (string) config('h1-license.enrollment.path', '/api/v1/enrollment/consume');
        $url = $base . $path;

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
                ->timeout((int) config('h1-license.timeout_seconds', 15))
                ->post($url, [
                    'enrollment_token' => $enrollmentToken,
                    'client_version' => $clientVersion,
                ]);
        } catch (\Throwable) {
            throw new H1EnrollmentException('server_offline', 'OPS-SERVER enrollment endpoint is unreachable.');
        }

        $decoded = $response->json();
        if (! is_array($decoded)) {
            throw new H1EnrollmentException('invalid_response', 'OPS-SERVER enrollment response is not valid JSON.');
        }

        if ($response->status() !== 200 || ! ($decoded['success'] ?? false)) {
            $code = (string) ($decoded['code'] ?? 'enrollment_failed');
            $detail = (string) ($decoded['detail'] ?? ($decoded['error'] ?? 'Enrollment consume failed.'));
            throw new H1EnrollmentException($code, $detail);
        }

        return $decoded;
    }

    private function assertUrl(string $url): void
    {
        if ($url === '') {
            throw new H1EnrollmentException('server_unconfigured', 'OPS-SERVER base URL is not configured.');
        }

        $requireHttps = (bool) config('h1-license.require_https', true);
        try {
            ServerEndpointValidator::validate($url, $requireHttps);
        } catch (\InvalidArgumentException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'HTTPS')) {
                throw new H1EnrollmentException('insecure_transport', 'H1 license transport requires HTTPS.');
            }
            throw new H1EnrollmentException('invalid_url', $msg);
        }
    }
}
