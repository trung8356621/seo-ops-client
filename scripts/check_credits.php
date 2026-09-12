<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\ApiConnection;
use Illuminate\Support\Facades\Http;

$conn = ApiConnection::find(1);
$apiKey = (string) $conn->api_key;

echo "Calling OpenRouter /credits...\n";
$res = Http::withHeaders([
    'Authorization' => 'Bearer ' . $apiKey,
])->get('https://openrouter.ai/api/v1/credits');

echo "Status: " . $res->status() . "\n";
echo "Body:\n" . json_encode($res->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
