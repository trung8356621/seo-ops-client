<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Omnichannel\Addons\AiPrompt\Services\OpenRouterFreePoolService;
use Omnichannel\Addons\AiPrompt\Services\OpenRouterTextRoutingCatalog;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;

$pool = app(OpenRouterFreePoolService::class);
$catalog = app(OpenRouterTextRoutingCatalog::class);

$members = $pool->runtimeMembers(1, AiModelArea::TextReasoning);
echo "Runtime members for ReasoningText (User 1):\n";
foreach ($members as $idx => $m) {
    $rawName = $m->raw_model_name;
    $entry = $catalog->findByRawModelName($rawName);
    echo sprintf(
        "  %d. %s (ID: %d, rank_score: %s, rpm: %s, rpd: %s)\n",
        $idx + 1,
        $rawName,
        $m->id,
        $entry['rank_score'] ?? 'null',
        $entry['rpm'] ?? 'null',
        $entry['rpd'] ?? 'null'
    );
}

$fastMembers = $pool->runtimeMembers(1, AiModelArea::TextFast);
echo "\nRuntime members for FastText (User 1):\n";
foreach ($fastMembers as $idx => $m) {
    $rawName = $m->raw_model_name;
    $entry = $catalog->findByRawModelName($rawName);
    echo sprintf(
        "  %d. %s (ID: %d, rank_score: %s, rpm: %s, rpd: %s)\n",
        $idx + 1,
        $rawName,
        $m->id,
        $entry['rank_score'] ?? 'null',
        $entry['rpm'] ?? 'null',
        $entry['rpd'] ?? 'null'
    );
}
