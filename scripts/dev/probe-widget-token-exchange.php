<?php

declare(strict_types=1);

/**
 * Masked diagnostic for Widget Token exchange (no secrets printed).
 * Usage: php scripts/dev/probe-widget-token-exchange.php
 */

// Prefer cwd when scripts/dev is a junction into another checkout.
$root = is_file(getcwd().DIRECTORY_SEPARATOR.'artisan')
    ? getcwd()
    : dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\H1\Exceptions\H1TransportException;
use App\Services\H1\Persistence\InstallationStateRepository;
use App\Services\H1\Protocol\LicenseCrypto;
use App\Services\H1\Protocol\LicenseProtocol;
use App\Services\H1\Protocol\SignedHttpTransport;
use Illuminate\Support\Facades\Http;

$installations = app(InstallationStateRepository::class);
$installation = $installations->currentInstallation();
$credential = $installations->activeCredential();

$base = $installation && filled($installation->control_server_url)
    ? rtrim((string) $installation->control_server_url, '/')
    : $installations->getEffectiveControlServerUrl();

$out = [
    'enrolled' => $credential !== null,
    'installation_id_present' => $installation !== null && filled($installation->installation_id ?? null),
    'control_server_host' => $base !== '' ? (parse_url($base, PHP_URL_HOST) ?: 'unknown') : null,
    'path' => (string) config('h1-license.widget.path_template'),
];

if ($installation === null || $credential === null || $base === '') {
    $out['probe'] = 'skipped_not_enrolled_or_unconfigured';
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}

$installationId = (string) $installation->installation_id;
$path = str_replace('{installation_id}', rawurlencode($installationId), (string) config('h1-license.widget.path_template'));
$payload = [
    'protocol' => LicenseProtocol::ID,
    'installation_id' => $installationId,
    'purpose' => 'service_widget',
];
$rawBody = LicenseCrypto::canonicalJson($payload);

$timestamp = LicenseCrypto::timestamp();
$nonce = LicenseCrypto::nonce();
$digest = LicenseCrypto::contentSha256($rawBody);
$signing = LicenseCrypto::requestSigningString('POST', $path, $timestamp, $nonce, $digest);
$signature = LicenseCrypto::sign($signing, $credential->secretBase64Url());

try {
    $response = Http::withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        LicenseProtocol::HEADER_PROTOCOL => LicenseProtocol::ID,
        LicenseProtocol::HEADER_CREDENTIAL_ID => $credential->credential_id,
        LicenseProtocol::HEADER_TIMESTAMP => $timestamp,
        LicenseProtocol::HEADER_NONCE => $nonce,
        LicenseProtocol::HEADER_CONTENT_SHA256 => $digest,
        LicenseProtocol::HEADER_SIGNATURE => $signature,
    ])
        ->timeout(8)
        ->withBody($rawBody, 'application/json')
        ->post($base.$path);
} catch (Throwable $e) {
    $out['probe'] = 'transport_exception';
    $out['error_class'] = $e::class;
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(1);
}

$body = (string) $response->body();
$decoded = json_decode($body, true);
$keys = is_array($decoded) ? array_keys($decoded) : [];

$out['http_status'] = $response->status();
$out['content_type'] = (string) $response->header('Content-Type');
$out['json_keys'] = $keys;
$out['has_access_token'] = is_array($decoded) && filled($decoded['access_token'] ?? null);
$out['has_expires_at'] = is_array($decoded) && filled($decoded['expires_at'] ?? null);
$out['has_error_code'] = is_array($decoded) && filled($decoded['code'] ?? null);
$out['error_code'] = is_array($decoded) ? ($decoded['code'] ?? null) : null;
$out['signed_response'] = filled($response->header(LicenseProtocol::HEADER_SIGNATURE));
$out['body_looks_html'] = str_starts_with(ltrim($body), '<!');

// Verify via transport (without printing token).
try {
    $transport = app(SignedHttpTransport::class);
    $result = $transport->postSigned(
        $base.$path,
        $path,
        $rawBody,
        $credential->credential_id,
        $credential->secretBase64Url(),
    );
    $decoded2 = $result['decoded'];
    $out['transport_ok'] = true;
    $out['transport_status'] = $result['status'];
    $out['transport_has_token'] = filled($decoded2['access_token'] ?? null);
    $out['transport_has_expires'] = filled($decoded2['expires_at'] ?? null);
    $out['transport_scopes_count'] = is_array($decoded2['scopes'] ?? null) ? count($decoded2['scopes']) : 0;
} catch (H1TransportException $e) {
    $out['transport_ok'] = false;
    $out['transport_error_code'] = $e->errorCode;
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
