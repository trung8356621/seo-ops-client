<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\AiPrompt\Services\ModelContextCapabilityResolver;
use App\Models\ApiConnection;

$ids = [160, 603, 93];
foreach ($ids as $id) {
    $m = DB::table('seo_ai_models')->where('id', $id)->first();
    if (! $m) {
        echo "missing model {$id}\n";
        continue;
    }
    $caps = json_decode((string) $m->capabilities, true) ?: [];
    $meta = $caps['provider_metadata'] ?? [];
    echo "\nMODEL {$id} {$m->raw_model_name} conn={$m->api_connection_id}\n";
    echo 'top_keys='.json_encode(array_keys($caps)).PHP_EOL;
    echo 'max_output_tokens_cap='.json_encode($caps['max_output_tokens'] ?? null).PHP_EOL;
    echo 'meta_max_completion='.json_encode($meta['max_completion_tokens'] ?? null).PHP_EOL;
    echo 'meta_top_provider='.json_encode([
        'context_length' => $meta['context_length'] ?? null,
        'max_completion_tokens' => $meta['max_completion_tokens'] ?? null,
        'architecture' => $meta['architecture'] ?? null,
    ], JSON_UNESCAPED_UNICODE).PHP_EOL;

    $conn = ApiConnection::query()->find((int) $m->api_connection_id);
    $cap = (new ModelContextCapabilityResolver())->resolveFor($conn, (string) $m->raw_model_name, (int) $m->id);
    echo 'resolved='.json_encode([
        'maxOutputTokens' => $cap->maxOutputTokens,
        'contextWindow' => $cap->contextWindow,
        'isReasoningModel' => $cap->isReasoningModel,
        'source' => $cap->capabilitySource,
        'confidence' => $cap->capabilityConfidence,
    ], JSON_UNESCAPED_UNICODE).PHP_EOL;
}

echo "\nDEFAULT_MAX_OUTPUT=".Omnichannel\Addons\AiPrompt\Services\ModelContextCapabilityResolver::DEFAULT_MAX_OUTPUT.PHP_EOL;
