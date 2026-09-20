<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

app(\Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)
    ->bootstrapLegacySharedConnection();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$conn = 'omi_seo_ai';

$ri = DB::connection($conn)->table('seo_project_run_items')->where('id', 903)->first();
$snap = json_decode((string) ($ri->output_snapshot ?? ''), true);
$outline = null;
foreach (($snap['steps'] ?? []) as $step) {
    if (($step['hook_key'] ?? '') === 'article.outline.structure.generate'
        || str_contains((string) ($step['title'] ?? ''), 'Outline')) {
        $outline = $step;
        break;
    }
}

echo "=== RUN ITEM 903 OUTLINE STEP KEYS ===\n";
echo json_encode(is_array($outline) ? array_keys($outline) : null, JSON_PRETTY_PRINT).PHP_EOL;

$attempts = $outline['routing_attempts']
    ?? $outline['ai_routing']['routing_attempts']
    ?? null;
echo "attempts_count=".(is_array($attempts) ? count($attempts) : 0).PHP_EOL;

if (is_array($attempts)) {
    $n = 0;
    foreach ($attempts as $a) {
        if (($a['result'] ?? '') !== 'failed') {
            continue;
        }
        $n++;
        $usage = $a['token_usage'] ?? $a['usage'] ?? [];
        if (! is_array($usage)) {
            $usage = [];
        }
        $budget = $usage['budget'] ?? $a['budget'] ?? [];
        if (! is_array($budget)) {
            $budget = [];
        }
        echo "\n=== FAILED ATTEMPT #{$n} ===\n";
        echo json_encode([
            'model' => $a['model'] ?? null,
            'connection_id' => $a['connection_id'] ?? null,
            'connection_name' => $a['connection_name'] ?? null,
            'is_free' => $a['is_free'] ?? null,
            'result' => $a['result'] ?? null,
            'provider_terminal_reason' => $a['provider_terminal_reason'] ?? null,
            'provider_finish_reason' => $a['provider_finish_reason'] ?? null,
            'finish_reason_usage' => $usage['finish_reason'] ?? null,
            'completion_tokens' => $usage['completion_tokens'] ?? $usage['output_tokens'] ?? null,
            'reasoning_tokens' => $usage['reasoning_tokens']
                ?? ($usage['completion_tokens_details']['reasoning_tokens'] ?? null)
                ?? ($usage['output_tokens_details']['reasoning_tokens'] ?? null),
            'prompt_tokens' => $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? null,
            'desired_output_tokens' => $budget['desired_output_tokens']
                ?? $usage['desired_output_tokens']
                ?? $a['desired_output_tokens']
                ?? null,
            'minimum_required_output_tokens' => $budget['minimum_required_output_tokens']
                ?? $usage['minimum_required_output_tokens']
                ?? null,
            'requested_max_output_tokens' => $budget['requested_max_output_tokens']
                ?? $budget['requestedMaxOutputTokens']
                ?? $usage['requested_max_output_tokens']
                ?? $usage['max_output']
                ?? $a['requested_max_output_tokens']
                ?? null,
            'model_max_output_tokens' => $budget['model_max_output_tokens']
                ?? $budget['max_output_tokens']
                ?? $usage['model_max_output_tokens']
                ?? null,
            'budget_keys' => array_keys($budget),
            'usage_keys' => array_keys($usage),
            'attempt_keys_sample' => array_values(array_intersect(array_keys($a), [
                'max_tokens', 'max_output', 'max_output_tokens', 'options', 'call_options',
                'capability', 'budget', 'token_usage', 'request', 'outbound',
            ])),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;

        // Dump nested budget + usage fully (truncated)
        echo "BUDGET_FULL=".json_encode($budget, JSON_UNESCAPED_UNICODE).PHP_EOL;
        $usageSlim = $usage;
        unset($usageSlim['compiled_prompt'], $usageSlim['raw_response'], $usageSlim['messages']);
        echo "USAGE_SLIM=".substr(json_encode($usageSlim, JSON_UNESCAPED_UNICODE), 0, 2500).PHP_EOL;
    }
}

// Prompt results around run 330 timeframe
echo "\n=== PROMPT RESULTS COLUMNS ===\n";
$cols = Schema::connection($conn)->getColumnListing('prompt_results');
echo json_encode($cols).PHP_EOL;

$prs = DB::connection($conn)->table('prompt_results')
    ->where('content_project_id', 904)
    ->where('project_item_id', 8856)
    ->where('created_at', '>=', '2026-09-20 05:35:00')
    ->orderBy('id')
    ->get();
echo 'pr_count='.$prs->count().PHP_EOL;
foreach ($prs as $pr) {
    $meta = [];
    foreach (['id', 'status', 'canonical_prompt_key', 'stage', 'failure_code', 'model', 'connection_id', 'created_at'] as $c) {
        if (isset($pr->$c)) {
            $meta[$c] = $pr->$c;
        }
    }
    echo 'PR='.json_encode($meta, JSON_UNESCAPED_UNICODE).PHP_EOL;
    foreach (['usage', 'token_usage', 'metadata', 'routing', 'diagnostics', 'error_context', 'context'] as $field) {
        if (! isset($pr->$field) || $pr->$field === null || $pr->$field === '') {
            continue;
        }
        $decoded = is_string($pr->$field) ? json_decode($pr->$field, true) : $pr->$field;
        if (! is_array($decoded)) {
            continue;
        }
        echo "  {$field}_keys=".json_encode(array_keys($decoded)).PHP_EOL;
        $budget = $decoded['budget'] ?? null;
        if (is_array($budget)) {
            echo "  budget=".json_encode($budget, JSON_UNESCAPED_UNICODE).PHP_EOL;
        }
        foreach (['desired_output_tokens', 'requested_max_output_tokens', 'max_output', 'max_tokens', 'finish_reason', 'completion_tokens', 'reasoning_tokens'] as $k) {
            if (array_key_exists($k, $decoded)) {
                echo "  {$k}=".json_encode($decoded[$k]).PHP_EOL;
            }
        }
    }
}

// Routing attempt table if exists
if (Schema::connection($conn)->hasTable('prompt_result_routing_attempts')) {
    $rows = DB::connection($conn)->table('prompt_result_routing_attempts')
        ->whereIn('prompt_result_id', $prs->pluck('id')->all() ?: [0])
        ->orderBy('id')
        ->get();
    echo "\nrouting_attempt_rows=".$rows->count().PHP_EOL;
    foreach ($rows as $row) {
        $arr = (array) $row;
        unset($arr['raw_response'], $arr['response_body']);
        echo 'RA='.substr(json_encode($arr, JSON_UNESCAPED_UNICODE), 0, 2000).PHP_EOL;
    }
}
