<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$base = rtrim((string) env('SYSTEM_API_BASE_URL'), '/');
$token = (string) env('SYSTEM_API_TOKEN');
echo "base={$base}" . PHP_EOL;
echo "token_set=" . ($token !== '' ? 'yes' : 'no') . PHP_EOL;
echo "cap_mode=" . env('SYSTEM_CAP_ARTICLE_CONTENT_GENERATE') . PHP_EOL;

// Prove host resolves / HTTP app responds
$hostProbes = ['/', '/up', '/api/system/v1/ai/executions'];
foreach ($hostProbes as $path) {
    $url = $base . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HEADER => true,
        CURLOPT_NOBODY => false,
        CURLOPT_CUSTOMREQUEST => $path === '/api/system/v1/ai/executions' ? 'GET' : 'GET',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            // intentionally no token on first probe for auth wiring check on API path
        ],
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    echo "PROBE_NO_AUTH\t{$path}\tcode={$code}\terr={$err}" . PHP_EOL;
}

// Auth wiring: GET with bearer should not 500; 404/405/401/422 acceptable without id
$url = $base . '/api/system/v1/ai/executions';
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_HEADER => true,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
    ],
]);
$raw = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
$headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);
$body = is_string($raw) ? substr($raw, $headerSize) : '';
echo "PROBE_AUTH_GET\t/api/system/v1/ai/executions\tcode={$code}\terr={$err}\tbody=" . substr(trim((string)$body), 0, 200) . PHP_EOL;

// Wrong token should be rejected
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_HEADER => true,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Authorization: Bearer totally-invalid-token',
    ],
]);
$raw = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
$headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);
$body = is_string($raw) ? substr($raw, $headerSize) : '';
echo "PROBE_BAD_TOKEN\tcode={$code}\terr={$err}\tbody=" . substr(trim((string)$body), 0, 200) . PHP_EOL;

// Transport class bound?
$remote = app(\App\System\Ai\Transport\RemoteHttpAiTransport::class);
echo 'remote_transport_class=' . $remote::class . PHP_EOL;
$client = app(\App\System\Ai\Contracts\SystemAiClient::class);
echo 'system_ai_client=' . $client::class . PHP_EOL;

$mode = app(\App\System\Support\CapabilityModeResolver::class)->resolve('article.content.generate', 'ai');
echo 'resolved_mode=' . (is_object($mode) ? ($mode->value ?? (string)$mode) : (string)$mode) . PHP_EOL;

echo "DONE_PHASE3" . PHP_EOL;
