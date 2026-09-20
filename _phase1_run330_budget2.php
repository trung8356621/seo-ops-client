<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

app(\Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)
    ->bootstrapLegacySharedConnection();

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\AiPrompt\Services\ModelContextCapabilityResolver;
use Omnichannel\Addons\AiPrompt\PromptBudget\PromptSplitStrategyRegistry;
use Omnichannel\Addons\AiPrompt\PromptBudget\PromptBudgetPreflightService;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiModelCapability;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use App\Models\ApiConnection;

$conn = 'omi_seo_ai';
$ri = DB::connection($conn)->table('seo_project_run_items')->where('id', 903)->first();
$snap = json_decode((string) ($ri->output_snapshot ?? ''), true);
$outline = $snap['steps'][0] ?? null;

echo 'outline_result_id='.json_encode($outline['outline_result_id'] ?? null).PHP_EOL;
echo 'prompt_result_ids='.json_encode($outline['prompt_result_ids'] ?? null).PHP_EOL;
echo 'result_id='.json_encode($outline['result_id'] ?? null).PHP_EOL;
echo 'ai_routing_keys='.json_encode(array_keys($outline['ai_routing'] ?? [])).PHP_EOL;

$ai = $outline['ai_routing'] ?? [];
foreach (['routing_attempts', 'attempts', 'token_usage', 'budget', 'eligible_models'] as $k) {
    if (isset($ai[$k])) {
        $v = $ai[$k];
        echo "ai_routing.{$k}_type=".gettype($v);
        if (is_array($v)) {
            echo ' count='.count($v);
            if ($v !== [] && array_is_list($v)) {
                echo ' first_keys='.json_encode(array_keys($v[0] ?? []));
            } else {
                echo ' keys='.json_encode(array_keys($v));
            }
        }
        echo PHP_EOL;
    }
}

$attempts = $ai['routing_attempts'] ?? [];
$failed = array_values(array_filter($attempts, static fn ($a) => ($a['result'] ?? '') === 'failed'));
echo 'failed_count='.count($failed).PHP_EOL;

foreach ($failed as $i => $a) {
    echo "\n---- FAILED ".($i + 1)." FULL ATTEMPT (truncated) ----\n";
    $copy = $a;
    // keep everything but truncate long strings
    array_walk_recursive($copy, static function (&$v): void {
        if (is_string($v) && strlen($v) > 400) {
            $v = substr($v, 0, 400).'…';
        }
    });
    echo substr(json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 0, 4000).PHP_EOL;
}

// Load PR if any
$prIds = [];
foreach (['outline_result_id', 'result_id'] as $k) {
    if (! empty($outline[$k])) {
        $prIds[] = (int) $outline[$k];
    }
}
foreach (($outline['prompt_result_ids'] ?? []) as $id) {
    $prIds[] = (int) $id;
}
$prIds = array_values(array_unique(array_filter($prIds)));
echo "\npr_ids=".json_encode($prIds).PHP_EOL;
foreach ($prIds as $pid) {
    $pr = DB::connection($conn)->table('prompt_results')->where('id', $pid)->first();
    if (! $pr) {
        echo "PR {$pid} missing\n";
        continue;
    }
    $usage = json_decode((string) ($pr->token_usage ?? ''), true) ?: [];
    echo "PR{$pid} status={$pr->status} key={$pr->canonical_prompt_key} failure={$pr->failure_code}\n";
    echo '  usage='.substr(json_encode($usage, JSON_UNESCAPED_UNICODE), 0, 2000).PHP_EOL;
    echo '  err='.substr((string) $pr->error_message, 0, 300).PHP_EOL;
}

// Reconstruct capability + planned budget for the 3 models/connections
echo "\n=== RECONSTRUCTED PREFLIGHT (same hooks/code path) ===\n";
$registry = new PromptSplitStrategyRegistry();
$resolver = new ModelContextCapabilityResolver();
$preflight = new PromptBudgetPreflightService();

$targets = [
    ['conn' => 1, 'model' => 'nvidia/nemotron-3-ultra-550b-a55b:free'],
    ['conn' => 5, 'model' => 'nvidia/nemotron-3-ultra-550b-a55b:free'],
    ['conn' => 1, 'model' => 'nvidia/nemotron-3.5-lightning:free'],
];

foreach ($targets as $t) {
    $connection = ApiConnection::query()->find($t['conn']);
    if (! $connection) {
        echo "conn {$t['conn']} missing\n";
        continue;
    }
    $seoModelId = (int) DB::table('seo_ai_models')
        ->where('api_connection_id', $t['conn'])
        ->where('raw_model_name', $t['model'])
        ->value('id');
    $cap = $resolver->resolveFor($connection, $t['model'], $seoModelId > 0 ? $seoModelId : null);
    $reserve = $registry->forHook('article.outline.structure.generate')->estimateOutputReserve([], $cap);
    $candidate = new RoutedAiCandidate(
        AiExecutionProfile::TextReasoning->value,
        $connection,
        ApiConnectionProviders::OPENROUTER,
        $t['model'],
        [AiModelCapability::TextGenerate->value, AiModelCapability::TextReasoning->value],
        1,
        isFree: true,
        seoAiModelId: $seoModelId > 0 ? $seoModelId : null,
    );
    $plan = $preflight->plan($candidate, str_repeat('outline input ', 50), 'article.outline.structure.generate');
    echo json_encode([
        'connection_id' => $t['conn'],
        'connection_name' => $connection->name,
        'model' => $t['model'],
        'seo_ai_model_id' => $seoModelId,
        'capability_maxOutputTokens' => $cap->maxOutputTokens,
        'capability_contextWindow' => $cap->contextWindow ?? ($cap->maxContextTokens ?? null),
        'strategy_reserve' => $reserve,
        'plan_desired' => $plan->desiredOutputTokens ?? null,
        'plan_requestedMaxOutputTokens' => $plan->requestedMaxOutputTokens,
        'plan_minimumRequired' => $plan->minimumRequiredOutputTokens ?? null,
        'plan_diagnostics' => method_exists($plan, 'toDiagnostics') ? $plan->toDiagnostics() : null,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
}
