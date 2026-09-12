<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

app(\Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();

use Omnichannel\Addons\AiPrompt\Models\PromptResultRoutingAttempt;

$attemptsToday = PromptResultRoutingAttempt::whereDate('created_at', '2026-09-12')
    ->where('attempted', true)
    ->get();

echo "Total physical API attempts executed today: " . $attemptsToday->count() . "\n";

$byModel = [];
$byHttpStatus = [];
foreach ($attemptsToday as $att) {
    $m = $att->model ?? ($att->raw['model'] ?? 'unknown');
    $byModel[$m] = ($byModel[$m] ?? 0) + 1;
    $st = $att->http_status ?? 'null';
    $byHttpStatus[$st] = ($byHttpStatus[$st] ?? 0) + 1;
}

echo "Attempts by HTTP Status:\n";
print_r($byHttpStatus);

echo "Attempts by Model:\n";
print_r($byModel);
