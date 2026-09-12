<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\AiModel;
use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\Services\OpenRouterFreePoolService;
use Omnichannel\Addons\AiPrompt\Services\OpenRouterTextRoutingCatalog;

$connection = ApiConnection::find(1);
$poolService = app(OpenRouterFreePoolService::class);
$catalog = app(OpenRouterTextRoutingCatalog::class);

$freeModels = AiModel::where('api_connection_id', 1)
    ->where(function($q) {
        $q->where('raw_model_name', 'like', '%:free%')
          ->orWhere('display_name', 'like', '%:free%');
    })
    ->get();

echo "Total free models in DB for connection #1: " . $freeModels->count() . "\n";
foreach ($freeModels as $m) {
    echo sprintf(
        "ID: %d | Status: %s | Hidden: %d | Priority: %d | Raw: %s\n",
        $m->id,
        $m->status,
        $m->is_hidden ? 1 : 0,
        $m->priority,
        $m->raw_model_name
    );
}

echo "\n--- RUNTIME CANDIDATE ORDERING (text.reasoning, article.outline.structure.generate) ---\n";
$ordered = $poolService->orderFreeModelsForRuntime(
    $freeModels,
    $connection,
    'article.outline.structure.generate',
    'text.reasoning'
);

$i = 1;
foreach ($ordered as $m) {
    $rawName = $m->raw_model_name;
    $catalogEntry = $catalog->findByRawModelName($rawName);
    echo sprintf(
        "  %d. %s (ID: %d, status: %s, rank_score: %s, rpm: %s, rpd: %s, context: %s, max_out: %s)\n",
        $i++,
        $rawName,
        $m->id,
        $m->status ?? 'active',
        $catalogEntry['rank_score'] ?? 'null',
        $catalogEntry['rpm'] ?? 'null',
        $catalogEntry['rpd'] ?? 'null',
        $catalogEntry['context_length'] ?? 'null',
        $catalogEntry['max_output_tokens'] ?? 'null'
    );
}
