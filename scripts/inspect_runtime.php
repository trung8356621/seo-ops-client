<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Bootstrap SEO connection
app(\Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();

use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\PromptResultRoutingAttempt;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Models\SeoAiConnection;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;

echo "=== 1. LATEST FAILED RUN ITEMS & PROMPT RESULTS ===\n";

$failedItems = SeoProjectRunItem::query()
    ->where('status', 'failed')
    ->orderByDesc('id')
    ->limit(5)
    ->get();

foreach ($failedItems as $item) {
    echo sprintf(
        "RunItem ID: %d | Run ID: %d | Task ID: %d | Step: %s | Status: %s | Error: %s\n",
        $item->id,
        $item->run_id,
        $item->task_id,
        $item->step_name ?? 'unknown',
        $item->status,
        substr((string)$item->error_message, 0, 150)
    );
}

echo "\n=== 2. LATEST PROMPT RESULTS (FAILED) ===\n";
$failedPromptResults = PromptResult::query()
    ->where('status', 'failed')
    ->orderByDesc('id')
    ->limit(5)
    ->get();

foreach ($failedPromptResults as $pr) {
    echo sprintf(
        "PromptResult ID: %d | Run ID: %s | Item ID: %s | Hook: %s | Status: %s | Failure Cat: %s | Error: %s\n",
        $pr->id,
        $pr->run_id ?? 'null',
        $pr->project_item_id ?? 'null',
        $pr->canonical_prompt_key ?? ($pr->input_snapshot['hook_key'] ?? 'unknown'),
        $pr->status,
        $pr->failure_category ?? 'none',
        substr((string)$pr->error_message, 0, 150)
    );

    // Routing attempts
    $attempts = PromptResultRoutingAttempt::query()
        ->where('prompt_result_id', $pr->id)
        ->orderBy('sequence')
        ->get();

    echo "  Routing attempts count: " . $attempts->count() . "\n";
    foreach ($attempts as $att) {
        echo sprintf(
            "    [Seq %d] Provider: %s | Model: %s | Attempted: %s | Result: %s | HTTP: %s | FailClass: %s | Err: %s\n",
            $att->sequence,
            $att->provider ?? 'null',
            $att->model ?? 'null',
            $att->attempted ? 'yes' : 'no',
            $att->result ?? 'null',
            $att->http_status ?? 'null',
            $att->failure_class ?? 'null',
            substr((string)$att->error_message, 0, 120)
        );
        if (!empty($att->raw)) {
            echo "      Raw: " . json_encode($att->raw, JSON_UNESCAPED_SLASHES) . "\n";
        }
    }

    if (!empty($pr->token_usage['routing'])) {
        echo "  Routing metadata in token_usage: " . json_encode($pr->token_usage['routing'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
    echo "--------------------------------------------------\n";
}

echo "\n=== 3. LATEST SUCCESSFUL PROMPT RESULTS (HOOK: article.outline.structure.generate) ===\n";
$successPromptResults = PromptResult::query()
    ->where('status', 'completed')
    ->where(function($q) {
        $q->where('canonical_prompt_key', 'article.outline.structure.generate')
          ->orWhere('input_snapshot->hook_key', 'article.outline.structure.generate');
    })
    ->orderByDesc('id')
    ->limit(5)
    ->get();

foreach ($successPromptResults as $pr) {
    echo sprintf(
        "PromptResult ID: %d | Run ID: %s | Item ID: %s | Hook: %s | Status: %s | Model: %s | Provider: %s\n",
        $pr->id,
        $pr->run_id ?? 'null',
        $pr->project_item_id ?? 'null',
        $pr->canonical_prompt_key ?? ($pr->input_snapshot['hook_key'] ?? 'unknown'),
        $pr->status,
        $pr->model ?? 'null',
        $pr->provider ?? 'null'
    );
    if (!empty($pr->token_usage['routing'])) {
        echo "  Routing metadata: " . json_encode($pr->token_usage['routing'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
    $attempts = PromptResultRoutingAttempt::query()
        ->where('prompt_result_id', $pr->id)
        ->orderBy('sequence')
        ->get();
    foreach ($attempts as $att) {
        echo sprintf(
            "    [Seq %d] Provider: %s | Model: %s | Attempted: %s | Result: %s | HTTP: %s\n",
            $att->sequence,
            $att->provider ?? 'null',
            $att->model ?? 'null',
            $att->attempted ? 'yes' : 'no',
            $att->result ?? 'null',
            $att->http_status ?? 'null'
        );
    }
    echo "--------------------------------------------------\n";
}

echo "\n=== 4. OPENROUTER FREE MODELS IN DB ===\n";
$openrouterConn = SeoAiConnection::query()
    ->where('driver', 'openrouter')
    ->orWhere('name', 'like', '%openrouter%')
    ->first();

if ($openrouterConn) {
    echo "OpenRouter Connection ID: " . $openrouterConn->id . " | Name: " . $openrouterConn->name . "\n";
    $freeModels = SeoAiModel::query()
        ->where('connection_id', $openrouterConn->id)
        ->where(function($q) {
            $q->where('is_free', true)
              ->orWhere('name', 'like', '%:free%');
        })
        ->orderBy('sort_order')
        ->get();

    echo "Free models count: " . $freeModels->count() . "\n";
    foreach ($freeModels as $m) {
        echo sprintf(
            "  ID: %d | Model: %s | Active: %d | IsFree: %d | Sort: %d | RawName: %s\n",
            $m->id,
            $m->model ?? $m->name,
            $m->is_active ? 1 : 0,
            $m->is_free ? 1 : 0,
            $m->sort_order ?? 0,
            $m->raw_model_name ?? $m->model ?? $m->name
        );
    }
} else {
    echo "No OpenRouter connection found!\n";
}
