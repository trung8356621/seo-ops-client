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
$outline = $snap['steps'][0] ?? [];
$attempts = $outline['ai_routing']['routing_attempts'] ?? [];
$failed = array_values(array_filter($attempts, static fn ($a) => ($a['result'] ?? '') === 'failed'));

echo "failed_count=".count($failed).PHP_EOL;
foreach ($failed as $i => $a) {
    echo 'FAILED'.($i+1).'='.json_encode([
        'model' => $a['model'] ?? null,
        'connection_id' => $a['connection_id'] ?? null,
        'connection_name' => $a['connection_name'] ?? null,
        'provider_finish_reason' => $a['provider_finish_reason'] ?? null,
        'provider_terminal_reason' => $a['provider_terminal_reason'] ?? null,
        'seo_ai_model_id' => $a['seo_ai_model_id'] ?? null,
        'has_token_usage' => array_key_exists('token_usage', $a),
        'request_sent' => $a['request_sent'] ?? null,
        'response_received' => $a['response_received'] ?? null,
    ], JSON_UNESCAPED_UNICODE).PHP_EOL;
}

// Run 327 PR 2144 had completion evidence
$pr2144 = DB::connection($conn)->table('prompt_results')->where('id', 2144)->first();
if ($pr2144) {
    $u = json_decode((string) ($pr2144->token_usage ?? ''), true) ?: [];
    echo "\nPR2144_usage=".substr(json_encode($u, JSON_UNESCAPED_UNICODE), 0, 2500).PHP_EOL;
}

echo "\n=== RECONSTRUCTED PREFLIGHT ===\n";
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
    $seoModelId = (int) DB::table('seo_ai_models')
        ->where('api_connection_id', $t['conn'])
        ->where('raw_model_name', $t['model'])
        ->value('id');
    $cap = $resolver->resolveFor($connection, $t['model'], $seoModelId > 0 ? $seoModelId : null);
    $strategy = $registry->forHook('article.outline.structure.generate');
    $reserve = $strategy->estimateOutputReserve([], $cap);
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
    $plan = $preflight->plan(
        $candidate,
        str_repeat('outline input ', 80),
        'article.outline.structure.generate',
        ['desired_output_tokens' => $reserve, 'minimum_required_output_tokens' => max(64, (int) floor($reserve * 0.35))],
    );
    echo json_encode([
        'connection_id' => $t['conn'],
        'connection_name' => $connection->name,
        'model' => $t['model'],
        'capability.maxOutputTokens' => $cap->maxOutputTokens,
        'capability.isReasoningModel' => $cap->isReasoningModel,
        'capability_source' => $cap->capabilitySource,
        'strategy_reserve_desired' => $reserve,
        'desired_output_tokens' => $plan->desiredOutputTokens,
        'minimum_required_output_tokens' => $plan->minimumRequiredOutputTokens,
        'requested_max_output_tokens' => $plan->requestedMaxOutputTokens,
        'model_max_output_tokens' => $plan->modelMaxOutputTokens,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
}
