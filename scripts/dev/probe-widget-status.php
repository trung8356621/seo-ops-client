<?php

declare(strict_types=1);

/**
 * Mint widget token then call status (no secrets printed).
 * Usage (from app root): php scripts/dev/probe-widget-status.php
 */

$root = is_file(getcwd().DIRECTORY_SEPARATOR.'artisan')
    ? getcwd()
    : dirname(__DIR__, 2);

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\H1\Widget\WidgetTokenExchangeClient;

$session = app(WidgetTokenExchangeClient::class)->exchange();
$token = (string) $session['access_token'];
$base = rtrim((string) $session['api_base_url'], '/');
$iid = (string) $session['installation_id'];

$path = '/api/v1/widget/installations/'.rawurlencode($iid).'/services';
$ch = curl_init($base.$path);
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer '.$token,
        'Accept: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 8,
]);
$body = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ct = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

$decoded = json_decode((string) $body, true);
$services = [];
if (is_array($decoded['services'] ?? null)) {
    foreach ($decoded['services'] as $s) {
        if (! is_array($s)) {
            continue;
        }
        $services[] = [
            'key' => $s['serviceKey'] ?? $s['code'] ?? $s['slug'] ?? null,
            'name' => $s['serviceName'] ?? $s['name'] ?? $s['label'] ?? null,
            'status' => $s['status'] ?? $s['state'] ?? null,
        ];
    }
}

echo json_encode([
    'app_url' => (string) config('app.url'),
    'ops_host' => parse_url($base, PHP_URL_HOST),
    'token_scopes' => $session['scopes'],
    'status_http' => $code,
    'status_content_type' => $ct,
    'top_keys' => is_array($decoded) ? array_keys($decoded) : [],
    'services' => $services,
    'service_count' => count($services),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
