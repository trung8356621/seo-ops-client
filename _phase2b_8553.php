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
use Omnichannel\Addons\AiPrompt\Services\AiRoutingOwnerResolver;

echo "=== OWNER RESOLVER ===" . PHP_EOL;
$resolver = app(AiRoutingOwnerResolver::class);
foreach ([null, 0, 8, 2, 1] as $uid) {
    try {
        $resolved = $resolver->resolve(explicitUserId: $uid);
        echo "resolve(explicit={$uid}) => {$resolved}" . PHP_EOL;
    } catch (Throwable $e) {
        echo "resolve(explicit={$uid}) ERR " . $e->getMessage() . PHP_EOL;
    }
}

// Reflect other methods
echo 'owner_methods=' . implode(',', get_class_methods($resolver)) . PHP_EOL;

$profileEnum = AiExecutionProfile::TextLongform;
$router = app(AiModelRouterService::class);
$health = app(AiRuntimeHealthService::class);
$capacity = app(AiRouteCapacityPolicy::class);
$capacity->balances()::clear();

foreach ([8, 2, 1] as $userId) {
    echo "=== USER {$userId} CANDIDATES ===" . PHP_EOL;
    $context = new AiRoutingContext(
        userId: $userId,
        hookKey: 'article.content.generate',
        canonicalPromptKey: 'article.content.generate',
        projectItemId: 8799,
    );
    try {
        $candidates = $router->resolveAll($profileEnum->value, $context);
    } catch (Throwable $e) {
        echo "ERR " . $e->getMessage() . PHP_EOL;
        continue;
    }
    echo 'count=' . count($candidates) . PHP_EOL;
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
        if ($hSkip) $reasons[] = 'health:' . $hSkip;
        if ($paidBudgetSkip) $reasons[] = 'paid_budget:' . $paidBudgetSkip;
        if ($paidLocked) $reasons[] = 'paid_locked';
        if (! $decision->eligible) $reasons[] = 'capacity:' . ($decision->reason ?? 'rejected');
        $usable = $reasons === [];
        if ($usable && $firstUsable === null) $firstUsable = $cand;
        $ageSec = $snapshot->observedAt ? (time() - $snapshot->observedAt->getTimestamp()) : null;
        echo "CAND\t" . json_encode([
            'seq' => $seq,
            'logical' => $cand->logicalModelKey(),
            'provider' => $cand->provider,
            'model' => $cand->model,
            'conn_id' => (int) $conn->id,
            'conn_name' => (string) $conn->name,
            'lane' => $cand->isFree ? 'FREE' : 'PAID',
            'priority' => $cand->priority,
            'health_skip' => $hSkip,
            'paid_budget_skip' => $paidBudgetSkip,
            'paid_locked' => $paidLocked,
            'snap_bal' => $snapshot->balanceUsd,
            'snap_src' => $snapshot->source,
            'snap_trust' => $snapshot->trustworthy,
            'snap_age' => $ageSec,
            'cap_ok' => $decision->eligible,
            'cap_reason' => $decision->reason,
            'cap_src' => $decision->source,
            'cap_thr' => $decision->thresholdUsd,
            'usable' => $usable,
            'skips' => $reasons,
        ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
    if ($firstUsable) {
        echo "FIRST_USABLE={$firstUsable->physicalRouteKey()} SHAPE=" . ($firstUsable->isFree ? 'SPLIT' : 'SINGLE') . PHP_EOL;
    } else {
        echo "FIRST_USABLE=NONE" . PHP_EOL;
    }

    // routing targets count
    try {
        $targets = app(\Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService::class);
        $t = $targets->targetsFor($userId, $profileEnum->value);
        echo 'targets_count=' . count($t) . PHP_EOL;
    } catch (Throwable $e) {
        echo 'targets_err=' . $e->getMessage() . PHP_EOL;
    }
}

echo "=== CONNECTIONS BY USER ===" . PHP_EOL;
$rows = DB::table('api_connections')->orderBy('id')->get(['id','user_id','provider','name','status','balance','balance_status','balance_checked_at','paid_locked']);
foreach ($rows as $r) {
    echo "CONN\t" . json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

echo "=== PR2107 input owner hints ===" . PHP_EOL;
$pr = DB::connection('omi_seo_ai')->table('prompt_results')->where('id', 2107)->first();
$snap = json_decode((string) $pr->input_snapshot, true);
foreach (['user_id','owner_user_id','routing_user_id','connection_id','provider','model'] as $k) {
    if (isset($snap[$k])) echo "{$k}=" . json_encode($snap[$k]) . PHP_EOL;
}
// dig common nests
foreach (['routing','context','variables','meta'] as $nest) {
    if (!isset($snap[$nest]) || !is_array($snap[$nest])) continue;
    foreach (['user_id','owner_user_id','routing_user_id','connection_id'] as $k) {
        if (isset($snap[$nest][$k])) echo "{$nest}.{$k}=" . json_encode($snap[$nest][$k]) . PHP_EOL;
    }
}

echo "DONE" . PHP_EOL;
