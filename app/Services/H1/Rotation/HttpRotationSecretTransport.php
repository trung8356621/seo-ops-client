<?php

declare(strict_types=1);

namespace App\Services\H1\Rotation;

use App\Models\H1Credential;
use App\Services\H1\Exceptions\H1CommandException;
use App\Services\H1\Protocol\SignedHttpTransport;

/**
 * Production HTTP transport for fetching replacement machine secrets during H1 key rotation.
 */
final class HttpRotationSecretTransport implements RotationSecretTransport
{
    public function __construct(
        private readonly SignedHttpTransport $http,
    ) {}

    public function fetchReplacementSecret(
        string $installationId,
        string $rotationId,
        string $fromCredentialId,
    ): string {
        $cred = H1Credential::query()
            ->where('installation_id', $installationId)
            ->where('credential_id', $fromCredentialId)
            ->first();

        if (! $cred instanceof H1Credential) {
            throw new H1CommandException(
                'credential_not_found',
                "Retiring credential '{$fromCredentialId}' not found for installation '{$installationId}'.",
            );
        }

        try {
            $fromSecret = $cred->secretBase64Url();
        } catch (\Throwable $e) {
            throw new H1CommandException(
                'credential_unreadable',
                "Failed to decrypt retiring credential secret: {$e->getMessage()}",
            );
        }

        $pathTemplate = (string) config(
            'h1-license.rotation.path_template',
            '/api/v1/installations/{installation_id}/license/rotations/{rotation_id}/secret'
        );
        $path = str_replace(
            ['{installation_id}', '{rotation_id}'],
            [rawurlencode($installationId), rawurlencode($rotationId)],
            $pathTemplate,
        );

        $base = rtrim((string) config('h1-license.base_url', ''), '/');
        $url = $base . $path;

        try {
            $result = $this->http->postSigned(
                $url,
                $path,
                '{}',
                $fromCredentialId,
                $fromSecret,
            );
        } catch (\Throwable $e) {
            throw new H1CommandException(
                'server_offline',
                "Failed to reach rotation secret endpoint: {$e->getMessage()}",
            );
        }

        if ($result['status'] !== 200) {
            $decoded = $result['decoded'] ?? [];
            $code = (string) ($decoded['code'] ?? 'rotation_failed');
            $detail = (string) ($decoded['error'] ?? ($decoded['detail'] ?? 'Rotation secret fetch failed.'));
            throw new H1CommandException($code, $detail);
        }

        $decoded = $result['decoded'];
        $secret = (string) ($decoded['secret'] ?? ($decoded['to_credential']['secret'] ?? ''));
        if ($secret === '') {
            throw new H1CommandException('invalid_response', 'Replacement secret missing from rotation response.');
        }

        return $secret;
    }
}
