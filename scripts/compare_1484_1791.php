<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

app(\Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();

use Omnichannel\Addons\AiPrompt\Models\PromptResult;

echo "=== COMPARING PROMPT RESULT 1484 (OLD SUCCESS) vs 1791 (NEW FAILURE) ===\n";

$prSuccess = PromptResult::find(1484);
$prFail = PromptResult::find(1791);

echo "--- 1484 (SUCCESS) ---\n";
echo "Created: " . $prSuccess->created_at?->toIso8601String() . "\n";
echo "Status: " . $prSuccess->status . "\n";
echo "Model: " . ($prSuccess->token_usage['routing']['model'] ?? 'unknown') . "\n";
echo "Provider: " . ($prSuccess->token_usage['routing']['provider'] ?? 'unknown') . "\n";
echo "Tokens: In=" . ($prSuccess->token_usage['prompt_tokens'] ?? '') . ", Out=" . ($prSuccess->token_usage['completion_tokens'] ?? '') . "\n";
echo "Routing in Token Usage: " . json_encode($prSuccess->token_usage['routing'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
echo "Input snapshot keys: " . implode(', ', array_keys($prSuccess->input_snapshot ?? [])) . "\n";
echo "Variables in snapshot: " . json_encode($prSuccess->input_snapshot['variables'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

echo "\n--- 1791 (FAILURE) ---\n";
echo "Created: " . $prFail->created_at?->toIso8601String() . "\n";
echo "Status: " . $prFail->status . "\n";
echo "Error message: " . $prFail->error_message . "\n";
echo "Routing in Token Usage: " . json_encode($prFail->token_usage['routing'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
echo "Input snapshot keys: " . implode(', ', array_keys($prFail->input_snapshot ?? [])) . "\n";
echo "Variables in snapshot: " . json_encode($prFail->input_snapshot['variables'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
