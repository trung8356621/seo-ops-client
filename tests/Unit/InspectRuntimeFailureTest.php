<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ApiConnection;
use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Tests\TestCase;

final class InspectRuntimeFailureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (class_exists(\Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)) {
            app(\Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();
        }
    }

    public function test_inspect_latest_failures(): void
    {
        echo "\n=== 1. LATEST SEO PROJECT RUNS ===\n";
        $runs = SeoProjectRun::query()
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        foreach ($runs as $run) {
            echo sprintf(
                "Run #%d | Project #%d | Mode: %s | Status: %s | Created: %s\nSettings: %s\n\n",
                $run->id,
                $run->project_id,
                $run->mode,
                $run->status,
                $run->created_at,
                json_encode($run->settings, JSON_UNESCAPED_UNICODE)
            );
        }

        echo "\n=== 2. LATEST FAILED RUN ITEMS ===\n";
        $failedItems = SeoProjectRunItem::query()
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        foreach ($failedItems as $item) {
            echo sprintf(
                "Item #%d | Run #%d | Task #%d | Step: %s | Status: %s | Step Status: %s | Error: %s\nDiagnostics: %s\n\n",
                $item->id,
                $item->run_id,
                $item->task_id,
                $item->step ?? 'N/A',
                $item->status,
                $item->step_status ?? 'N/A',
                $item->error_message ?? 'N/A',
                json_encode($item->diagnostics ?? [], JSON_UNESCAPED_UNICODE)
            );
        }

        echo "\n=== 3. LATEST PROMPT RESULTS ===\n";
        $promptResults = PromptResult::query()
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        foreach ($promptResults as $pr) {
            $usage = is_array($pr->token_usage) ? $pr->token_usage : [];
            $routing = $usage['routing'] ?? [];
            $routingAttempts = $routing['routing_attempts'] ?? [];
            $snapshot = is_array($pr->input_snapshot) ? $pr->input_snapshot : [];

            echo sprintf(
                "PromptResult #%d | Prompt #%d | Status: %s | Hook: %s | Model Used: %s | Error: %s\nRouting Plan: %s\nAttempts Count: %d\nAttempts: %s\n\n",
                $pr->id,
                $pr->prompt_id,
                $pr->status,
                $snapshot['hook_key'] ?? $routing['hook_key'] ?? 'N/A',
                $pr->model_used ?? 'N/A',
                $pr->error_message ?? 'N/A',
                json_encode($routing['routing_plan'] ?? [], JSON_UNESCAPED_UNICODE),
                count($routingAttempts),
                json_encode($routingAttempts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );
        }

        self::assertTrue(true);
    }
}
