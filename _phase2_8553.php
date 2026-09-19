<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Services\AiRuntimeHealthService;
use Omnichannel\Addons\AiPrompt\Services\RouteCapacity\AiRouteCapacityPolicy;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;

echo "=== PHASE2 ROUTING PREFLIGHT (no provider AI) ===" . PHP_EOL;

$project = DB::connection('omi_seo_ai')->table('seo_projects')->where('id', 900)->first();
echo json_encode([
    'project_id' => $project->id ?? null,
    'site_id' => $project->site_id ?? null,
    'user_id' => $project->user_id ?? null,
    'created_by' => $project->created_by ?? null,
    'owner_user_id' => $project->owner_user_id ?? null,
], JSON_UNESCAPED_UNICODE) . PHP_EOL;

$userId = (int) ($project->user_id ?? $project->created_by ?? $project->owner_user_id ?? 0);
if ($userId <= 0) {
    // Fall back to owner of successful physical route (seo-ops-3 / conn 5)
    $userId = 2;
}
echo "routing_user_id={$userId}" . PHP_EOL;

$profileEnum = AiExecutionProfile::TextLongform;
$profile = $profileEnum->value;
echo "profile={$profile}" . PHP_EOL;

$context = new AiRoutingContext(
    userId: $userId,
    hookKey: 'article.content.generate',
    canonicalPromptKey: 'article.content.generate',
    projectItemId: 8799,
);

$router = app(AiModelRouterService::class);
$health = app(AiRuntimeHealthService::class);
$capacity = app(AiRouteCapacityPolicy::class);

// Do NOT refresh wallets (no connection mutation). Read snapshots only.
$capacity->balances()::clear();

$candidates = $router->resolveAll($profile, $context);
echo 'candidate_count=' . count($candidates) . PHP_EOL;

$firstUsable = null;
$seq = 0;
foreach ($candidates as $cand) {
    $seq++;
    $conn = $cand->connection;
    $hSkip = $health->skipReason($userId, $cand);
    $paidBudgetSkip = $health->paidProfileBudgetSkipReason($cand);
    $snapshot = $capacity->balances()->snapshotFor($conn, allowRefresh: false);
    $decision = $capacity->evaluate($cand, $profileEnum, $context, $snapshot);

    $paidLocked = (bool) ($conn->paid_locked ?? false);
    $reasons = [];
    if ($hSkip) {
        $reasons[] = 'health:' . $hSkip;
    }
    if ($paidBudgetSkip) {
        $reasons[] = 'paid_budget:' . $paidBudgetSkip;
    }
    if ($paidLocked) {
        $reasons[] = 'paid_locked';
    }
    if (! $decision->eligible) {
        $reasons[] = 'capacity:' . ($decision->reason ?? 'rejected');
    }

    $usable = $reasons === [];
    if ($usable && $firstUsable === null) {
        $firstUsable = $cand;
    }

    $ageSec = null;
    if ($snapshot->observedAt !== null) {
        $ageSec = time() - $snapshot->observedAt->getTimestamp();
    }

    echo "CAND\t" . json_encode([
        'seq' => $seq,
        'logical_model' => $cand->logicalModelKey(),
        'provider' => $cand->provider,
        'model' => $cand->model,
        'connection_id' => (int) $conn->id,
        'connection_name' => (string) $conn->name,
        'free_paid' => $cand->isFree ? 'FREE' : 'PAID',
        'priority' => $cand->priority,
        'physical_route' => $cand->physicalRouteKey(),
        'health_skip' => $hSkip,
        'paid_budget_skip' => $paidBudgetSkip,
        'paid_locked' => $paidLocked,
        'paid_lock_reasons' => $conn->paid_lock_reasons ?? null,
        'balance_column' => $conn->balance ?? null,
        'balance_status_column' => $conn->balance_status ?? null,
        'balance_checked_at' => (string) ($conn->balance_checked_at ?? ''),
        'snapshot_balance' => $snapshot->balanceUsd,
        'snapshot_source' => $snapshot->source,
        'snapshot_trustworthy' => $snapshot->trustworthy,
        'snapshot_status' => $snapshot->status,
        'snapshot_age_sec' => $ageSec,
        'capacity_eligible' => $decision->eligible,
        'capacity_reason' => $decision->reason,
        'capacity_source' => $decision->source,
        'capacity_threshold' => $decision->thresholdUsd,
        'skip_reasons' => $reasons,
        'usable' => $usable,
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

if ($firstUsable === null) {
    echo "FIRST_USABLE=NONE" . PHP_EOL;
    echo "EXPECTED_SHAPE=BLOCKED" . PHP_EOL;
    echo "BLOCKER=ZERO_USABLE_CANDIDATES" . PHP_EOL;
} else {
    echo "FIRST_USABLE=" . json_encode([
        'physical_route' => $firstUsable->physicalRouteKey(),
        'connection_id' => (int) $firstUsable->connection->id,
        'connection_name' => (string) $firstUsable->connection->name,
        'provider' => $firstUsable->provider,
        'model' => $firstUsable->model,
        'free_paid' => $firstUsable->isFree ? 'FREE' : 'PAID',
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    echo "EXPECTED_SHAPE=" . ($firstUsable->isFree ? 'SPLIT' : 'SINGLE') . PHP_EOL;
}

echo "DONE_PHASE2" . PHP_EOL;
