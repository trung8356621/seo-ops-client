<?php

declare(strict_types=1);

namespace App\Services\H1\Widget;

use App\Models\H1Installation;
use App\Services\H1\Exceptions\H1TransportException;
use App\Services\H1\Persistence\InstallationStateRepository;
use App\Services\H1\Protocol\LicenseCrypto;
use App\Services\H1\Protocol\LicenseProtocol;
use App\Services\H1\Protocol\SignedHttpTransport;
use Illuminate\Support\Facades\Log;

/**
 * Server-to-server Widget Token mint via enrolled H1 machine credential.
 * Never returns H1 secrets to callers — only the short-lived widget bearer.
 */
class WidgetTokenExchangeClient
{
    public function __construct(
        private readonly InstallationStateRepository $installations,
        private readonly SignedHttpTransport $http,
    ) {}

    /**
     * @return array{
     *     access_token: string,
     *     expires_at: string,
     *     installation_id: string,
     *     scopes: list<string>,
     *     api_base_url: string,
     *     bundle_url: string,
     *     api_timeout_ms: int
     * }
     */
    public function exchange(): array
    {
        $installation = $this->installations->currentInstallation();
        $credential = $this->installations->activeCredential();
        if (! $installation instanceof H1Installation || $credential === null) {
            throw new H1TransportException('not_enrolled', 'Installation is not enrolled.');
        }

        $installationId = (string) $installation->installation_id;
        $path = str_replace(
            '{installation_id}',
            rawurlencode($installationId),
            (string) config('h1-license.widget.path_template'),
        );

        $payload = [
            'protocol' => LicenseProtocol::ID,
            'installation_id' => $installationId,
            'purpose' => 'service_widget',
        ];
        $rawBody = LicenseCrypto::canonicalJson($payload);

        $base = filled($installation->control_server_url)
            ? rtrim((string) $installation->control_server_url, '/')
            : $this->installations->getEffectiveControlServerUrl();

        if ($base === '') {
            throw new H1TransportException('server_unconfigured', 'OPS-SERVER base URL is not configured.');
        }

        $previousTimeout = (int) config('h1-license.timeout_seconds', 15);
        $widgetTimeout = (int) config('h1-license.widget.timeout_seconds', 5);
        config(['h1-license.timeout_seconds' => $widgetTimeout]);

        try {
            $result = $this->http->postSigned(
                $base.$path,
                $path,
                $rawBody,
                $credential->credential_id,
                $credential->secretBase64Url(),
            );
        } finally {
            config(['h1-license.timeout_seconds' => $previousTimeout]);
        }

        return $this->parseSuccessResponse($result, $installationId, $base);
    }

    /**
     * @param  array{status: int, body: string, headers: array<string, string>, decoded: array<string, mixed>}  $result
     * @return array{
     *     access_token: string,
     *     expires_at: string,
     *     installation_id: string,
     *     scopes: list<string>,
     *     api_base_url: string,
     *     bundle_url: string,
     *     api_timeout_ms: int
     * }
     */
    public function parseSuccessResponse(array $result, string $installationId, string $apiBaseUrl): array
    {
        $status = (int) ($result['status'] ?? 0);
        $decoded = is_array($result['decoded'] ?? null) ? $result['decoded'] : [];

        Log::info('service_widget.token_exchange', [
            'http_status' => $status,
            'json_keys' => array_keys($decoded),
            'has_access_token' => filled($decoded['access_token'] ?? null),
            'has_expires_at' => filled($decoded['expires_at'] ?? null),
            'ops_error_code' => $decoded['code'] ?? null,
            'signed' => filled($result['headers'][strtolower(LicenseProtocol::HEADER_SIGNATURE)] ?? null),
            'host' => parse_url($apiBaseUrl, PHP_URL_HOST),
        ]);

        // Unsigned OPS error bodies ({code,error}) must not be treated as tokens.
        if ($status < 200 || $status >= 300 || isset($decoded['code'])) {
            $code = is_string($decoded['code'] ?? null) && $decoded['code'] !== ''
                ? (string) $decoded['code']
                : 'ops_error';
            throw new H1TransportException(
                $code,
                $this->friendlyOpsError($code, is_string($decoded['error'] ?? null) ? (string) $decoded['error'] : null)
            );
        }

        $accessToken = trim((string) ($decoded['access_token'] ?? ''));
        $expiresAt = trim((string) ($decoded['expires_at'] ?? ''));
        $responseInstallationId = trim((string) ($decoded['installation_id'] ?? ''));
        $scopes = is_array($decoded['scopes'] ?? null)
            ? array_values(array_filter(array_map('strval', $decoded['scopes'])))
            : [];

        if ($accessToken === '') {
            throw new H1TransportException('invalid_response', 'OPS-SERVER không trả access_token.');
        }
        if ($expiresAt === '') {
            throw new H1TransportException('invalid_response', 'OPS-SERVER không trả expires_at.');
        }
        if ($responseInstallationId !== '' && $responseInstallationId !== $installationId) {
            throw new H1TransportException('scope_mismatch', 'Widget token không khớp Installation hiện tại.');
        }
        if (! in_array('widget:status', $scopes, true) || ! in_array('widget:renew', $scopes, true)) {
            throw new H1TransportException('invalid_scope', 'Widget token thiếu scope bắt buộc.');
        }

        $expiresTs = strtotime($expiresAt);
        if ($expiresTs === false || $expiresTs <= time()) {
            throw new H1TransportException('token_expired', 'Widget token đã hết hạn ngay khi cấp.');
        }

        // Prefer same-origin relative paths so APP_URL scheme/host mismatch cannot
        // break script load on https installations (CDN absolute URLs still allowed).
        $bundleUrl = (string) config('h1-license.widget.bundle_url', '/vendor/ops-service-widget/ops-service-widget.js');
        if (! str_starts_with($bundleUrl, 'http://') && ! str_starts_with($bundleUrl, 'https://') && ! str_starts_with($bundleUrl, '/')) {
            $bundleUrl = '/'.$bundleUrl;
        }

        return [
            'access_token' => $accessToken,
            'expires_at' => $expiresAt,
            'installation_id' => $installationId,
            'scopes' => $scopes,
            'api_base_url' => $apiBaseUrl,
            'bundle_url' => $bundleUrl,
            'api_timeout_ms' => (int) config('h1-license.widget.api_timeout_ms', 5000),
        ];
    }

    private function friendlyOpsError(string $code, ?string $detail): string
    {
        return match ($code) {
            'secret_unconfigured' => 'OPS-SERVER chưa cấu hình khóa Widget Token. Liên hệ quản trị viên.',
            'missing_header', 'signature_mismatch', 'digest_mismatch' => 'Xác thực máy với OPS-SERVER thất bại.',
            'request_expired', 'invalid_timestamp' => 'Đồng hồ máy chủ lệch quá giới hạn cho phép.',
            'scope_mismatch', 'installation_mismatch' => 'Installation không được phép lấy Widget Token này.',
            'installation_not_found' => 'Installation chưa gắn Customer trên OPS-SERVER.',
            'installation_inactive', 'credential_inactive', 'unknown_credential' => 'Credential Installation không còn hiệu lực.',
            'replay_detected' => 'Yêu cầu bị từ chối (replay). Thử lại.',
            'server_offline' => 'Không kết nối được OPS-SERVER. Thử lại sau.',
            default => 'Không lấy được phiên Dịch vụ từ OPS-SERVER. Thử lại.',
        };
    }
}
