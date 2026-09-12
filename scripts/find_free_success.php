<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

app(\Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();

use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\PromptResultRoutingAttempt;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;

echo "=== SEARCHING FOR SUCCESSFUL FREE PROMPT RESULTS ===\n";

// Let's find any completed prompt result that ended up using a free model or had is_free=true
$completedResults = PromptResult::query()
    ->where('status', 'completed')
    ->where(function($q) {
        $q->where('token_usage->routing->is_free', true)
          ->orWhere('token_usage->routing->free', true)
          ->orWhere('token_usage->routing->model', 'like', '%:free%');
    })
    ->orderByDesc('id')
    ->limit(25)
    ->get();

echo "Found " . $completedResults->count() . " completed results with free model:\n";
foreach ($completedResults as $r) {
    $routing = $r->token_usage['routing'] ?? [];
    echo sprintf(
        "ID: %d | Created: %s | Hook: %s | Model: %s | Provider: %s | Tokens: In=%s, Out=%s | Policy: %s\n",
        $r->id,
        $r->created_at?->toIso8601String() ?? 'unknown',
        $r->canonical_prompt_key ?? ($r->input_snapshot['hook_key'] ?? 'unknown'),
        $routing['model'] ?? 'unknown',
        $routing['provider'] ?? 'unknown',
        $r->token_usage['prompt_tokens'] ?? ($r->token_usage['input_tokens'] ?? '?'),
        $r->token_usage['completion_tokens'] ?? ($r->token_usage['output_tokens'] ?? '?'),
        $routing['cost_policy'] ?? ($routing['billing_lane'] ?? '?')
    );
}

echo "\n=== ALSO SEARCHING ALL COMPLETED PROMPT RESULTS BY HOOK article.outline.structure.generate ===\n";
$allOutlines = PromptResult::query()
    ->where('status', 'completed')
    ->where(function($q) {
        $q->where('canonical_prompt_key', 'article.outline.structure.generate')
          ->orWhere('input_snapshot->hook_key', 'article.outline.structure.generate');
    })
    ->orderByDesc('id')
    ->limit(20)
    ->get();

foreach ($allOutlines as $r) {
    $routing = $r->token_usage['routing'] ?? [];
    echo sprintf(
        "ID: %d | Created: %s | Model: %s | Provider: %s | is_free: %s | Fallbacks: %s\n",
        $r->id,
        $r->created_at?->toIso8601String() ?? 'unknown',
        $routing['model'] ?? 'unknown',
        $routing['provider'] ?? 'unknown',
        !empty($routing['is_free']) ? 'yes' : 'no',
        $routing['fallback_count'] ?? 0
    );
}

echo "\n=== CHECKING SEO PROJECT RUNS ===\n";
$runs = SeoProjectRun::query()
    ->orderByDesc('id')
    ->limit(10)
    ->get();

foreach ($runs as $run) {
    echo sprintf(
        "Run ID: %d | Project ID: %d | Status: %s | Triggered By: %s | Created: %s\n",
        $run->id,
        $run->project_id,
        $run->status,
        $run->triggered_by ?? 'unknown',
        $run->created_at?->toIso8601String() ?? 'unknown'
    );
    // count items
    $totalItems = SeoProjectRunItem::where('run_id', $run->id)->count();
    $completedItems = SeoProjectRunItem::where('run_id', $run->id)->where('status', 'completed')->count();
    $failedItems = SeoProjectRunItem::where('run_id', $run->id)->where('status', 'failed')->count();
    echo sprintf("  Total items: %d | Completed: %d | Failed: %d\n", $totalItems, $completedItems, $failedItems);
}
