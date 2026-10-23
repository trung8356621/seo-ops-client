<?php

declare(strict_types=1);

namespace App\Services\H1\Widget;

use App\Models\H1Installation;
use App\Services\H1\Exceptions\H1TransportException;
use App\Services\H1\Persistence\InstallationStateRepository;
use App\Services\H1\Protocol\LicenseCrypto;
use App\Services\H1\Protocol\LicenseProtocol;
use App\Services\H1\Protocol\SignedHttpTransport;

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

        $decoded = $result['decoded'];
        $accessToken = (string) ($decoded['access_token'] ?? '');
        $expiresAt = (string) ($decoded['expires_at'] ?? '');
        $responseInstallationId = (string) ($decoded['installation_id'] ?? '');
        $scopes = is_array($decoded['scopes'] ?? null) ? array_values(array_map('strval', $decoded['scopes'])) : [];

        if ($accessToken === '' || $expiresAt === '') {
            throw new H1TransportException('invalid_response', 'Widget token response is incomplete.');
        }
        if ($responseInstallationId !== '' && $responseInstallationId !== $installationId) {
            throw new H1TransportException('scope_mismatch', 'Widget token installation mismatch.');
        }

        $bundleUrl = (string) config('h1-license.widget.bundle_url', '/vendor/ops-service-widget/ops-service-widget.js');
        if (! str_starts_with($bundleUrl, 'http://') && ! str_starts_with($bundleUrl, 'https://') && ! str_starts_with($bundleUrl, '/')) {
            $bundleUrl = '/'.$bundleUrl;
        }
        if (str_starts_with($bundleUrl, '/')) {
            $bundleUrl = url($bundleUrl);
        }

        return [
            'access_token' => $accessToken,
            'expires_at' => $expiresAt,
            'installation_id' => $installationId,
            'scopes' => $scopes,
            'api_base_url' => $base,
            'bundle_url' => $bundleUrl,
            'api_timeout_ms' => (int) config('h1-license.widget.api_timeout_ms', 5000),
        ];
    }
}
