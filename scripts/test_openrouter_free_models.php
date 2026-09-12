<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

app(\Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\OpenAiCompatibleProtocolAdapter;
use Illuminate\Support\Facades\Http;

echo "=== TESTING OPENROUTER FREE MODELS DIRECTLY VIA API CONNECTION #1 ===\n";

$connection = ApiConnection::find(1);
if (!$connection) {
    echo "Connection #1 not found!\n";
    exit;
}

echo "Connection found: " . $connection->name . " (provider: " . $connection->provider . ")\n";
$apiKey = (string) $connection->api_key;
if (empty($apiKey)) {
    echo "API Key is empty!\n";
    exit;
}

$testModels = [
    'nvidia/nemotron-3-ultra-550b-a55b:free',
    'google/gemma-4-26b-a4b-it:free',
    'google/gemma-4-31b-it:free',
    'nvidia/nemotron-3-super-120b-a12b:free',
    'poolside/laguna-s-2.1:free',
    'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning:free',
    'liquid/lfm-2.5-2.6b:free',
];

$endpoint = 'https://openrouter.ai/api/v1/chat/completions';

foreach ($testModels as $idx => $model) {
    echo sprintf("\n[%d] Testing model: %s\n", $idx + 1, $model);
    
    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'user', 'content' => 'Hello, reply with 1 word.'],
        ],
        'max_tokens' => 20,
    ];

    $response = Http::withHeaders([
        'Authorization' => 'Bearer ' . $apiKey,
        'HTTP-Referer' => 'http://localhost',
        'X-Title' => 'Omnichannel SEO AI',
        'Content-Type' => 'application/json',
    ])->timeout(30)->post($endpoint, $payload);

    echo "  HTTP Status: " . $response->status() . "\n";
    $body = $response->body();
    echo "  Response Body: " . substr($body, 0, 500) . "\n";
    
    // Also check headers
    $cfRay = $response->header('cf-ray');
    $xRateLimit = $response->header('x-ratelimit-limit');
    $xRateRemaining = $response->header('x-ratelimit-remaining');
    $xRateReset = $response->header('x-ratelimit-reset');
    echo "  RateLimit Headers: Limit=$xRateLimit, Remaining=$xRateRemaining, Reset=$xRateReset, CF-Ray=$cfRay\n";
}
