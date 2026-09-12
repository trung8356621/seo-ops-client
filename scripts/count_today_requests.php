<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

app(\Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();

use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Carbon\Carbon;

echo "=== COUNTING PROMPT RESULTS FROM OPENROUTER CONNECTION #1 ON 2026-09-12 ===\n";

$resultsToday = PromptResult::whereDate('created_at', '2026-09-12')
    ->orderBy('id')
    ->get();

echo "Total Prompt Results created on 2026-09-12: " . $resultsToday->count() . "\n";

$statuses = [];
$models = [];
$firstTime = null;
$lastTime = null;

foreach ($resultsToday as $r) {
    $time = $r->created_at?->toIso8601String();
    if ($firstTime === null) $firstTime = $time;
    $lastTime = $time;
    $status = $r->status;
    $statuses[$status] = ($statuses[$status] ?? 0) + 1;
    $model = $r->token_usage['routing']['model'] ?? ($r->model ?? 'unknown');
    $models[$model] = ($models[$model] ?? 0) + 1;
}

echo "First result at: $firstTime\n";
echo "Last result at: $lastTime\n";
echo "Statuses breakdown:\n";
print_r($statuses);
echo "Models breakdown:\n";
print_r($models);
