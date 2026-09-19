<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$base = rtrim((string) env('SYSTEM_API_BASE_URL'), '/');
$token = (string) env('SYSTEM_API_TOKEN');
$url = $base . '/api/system/v1/ai/executions/probe-8553-no-gen';

function req(string $url, string $method, ?string $token, ?array $json = null): array {
    $headers = ['Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $json !== null ? json_encode($json) : null,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return compact('code', 'err', 'body');
}

$r = req($url, 'GET', null);
echo "GET_NO_TOKEN\tcode={$r['code']}\tbody=" . substr(trim((string)$r['body']), 0, 180) . PHP_EOL;

$r = req($url, 'GET', 'bad-token');
echo "GET_BAD_TOKEN\tcode={$r['code']}\tbody=" . substr(trim((string)$r['body']), 0, 180) . PHP_EOL;

$r = req($url, 'GET', $token);
echo "GET_GOOD_TOKEN\tcode={$r['code']}\tbody=" . substr(trim((string)$r['body']), 0, 180) . PHP_EOL;

// Invalid POST (missing required fields) — proves auth + endpoint reachability without generation
$postUrl = $base . '/api/system/v1/ai/executions';
$r = req($postUrl, 'POST', $token, ['capability' => 'article.content.generate']); // incomplete on purpose
echo "POST_INCOMPLETE_AUTH\tcode={$r['code']}\tbody=" . substr(trim((string)$r['body']), 0, 300) . PHP_EOL;

$r = req($postUrl, 'POST', null, ['capability' => 'article.content.generate']);
echo "POST_INCOMPLETE_NO_AUTH\tcode={$r['code']}\tbody=" . substr(trim((string)$r['body']), 0, 180) . PHP_EOL;

echo "DONE_PHASE3B" . PHP_EOL;
