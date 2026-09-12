<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Omnichannel\Addons\AiPrompt\Services\OpenRouterFreePoolService;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;

$pool = app(OpenRouterFreePoolService::class);
$candidates = $pool->catalogCandidates(1, AiModelArea::TextReasoning);

echo "catalogCandidates for TextReasoning:\n";
foreach ($candidates as $idx => $row) {
    $m = $row['model'];
    echo sprintf(
        "  %d. %s (ID: %d, RankScore: %d, LangState: %s)\n",
        $idx + 1,
        $row['provider_model_id'],
        $m->id,
        $row['rank_score'],
        $row['language_state']->value
    );
}

$candidatesFast = $pool->catalogCandidates(1, AiModelArea::TextFast);
echo "\ncatalogCandidates for TextFast:\n";
foreach ($candidatesFast as $idx => $row) {
    $m = $row['model'];
    echo sprintf(
        "  %d. %s (ID: %d, RankScore: %d, LangState: %s)\n",
        $idx + 1,
        $row['provider_model_id'],
        $m->id,
        $row['rank_score'],
        $row['language_state']->value
    );
}
