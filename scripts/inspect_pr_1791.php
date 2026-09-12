<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

app(\Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();

use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\PromptResultRoutingAttempt;

echo "=== RAW DETAILS OF PROMPT RESULT 1791 (LATEST FAILED) ===\n";
$pr = PromptResult::find(1791);
if ($pr) {
    echo "ID: " . $pr->id . "\n";
    echo "Canonical Key: " . $pr->canonical_prompt_key . "\n";
    echo "Error message: " . $pr->error_message . "\n";
    echo "Token usage: " . json_encode($pr->token_usage, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    $attempts = PromptResultRoutingAttempt::where('prompt_result_id', 1791)->get();
    foreach ($attempts as $a) {
        echo "-----------------------------------------\n";
        echo "Attempt: " . $a->sequence . " | Model: " . $a->model . " | HTTP: " . $a->http_status . "\n";
        echo "Raw: " . json_encode($a->raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
} else {
    echo "PromptResult 1791 not found!\n";
}
