<?php

declare(strict_types=1);

/**
 * TASK 6 / 6.1 Topic Semantic cutover acceptance harness.
 *
 * READ-ONLY by default. Live Apply requires:
 *   TOPIC_GROUPING_LIVE_ACCEPTANCE=1
 *
 * Optional offline proof:
 *   TOPIC_GROUPING_ACCEPTANCE_EXPECT_SEMANTIC_OFFLINE=1
 *
 * Usage:
 *   php scripts/dev/topic-grouping-final-acceptance.php [siteId]
 */

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/lib/TopicGroupingAcceptanceSupport.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicGroupingRun;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeywordDna;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicTagAssignment;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingApplyPlan;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingApplyService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicGroupingAnalysisService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicGroupingProviderMode;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicLinkedArticleCounter;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicMcpExclusionService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicTagAssignmentSource;

$siteId = (int) ($argv[1] ?? 4);
$live = TopicGroupingAcceptanceSupport::envFlag('TOPIC_GROUPING_LIVE_ACCEPTANCE');
$expectOffline = TopicGroupingAcceptanceSupport::envFlag('TOPIC_GROUPING_ACCEPTANCE_EXPECT_SEMANTIC_OFFLINE');
$autoConfirm = TopicGroupingAcceptanceSupport::envFlag('TOPIC_GROUPING_ACCEPTANCE_AUTO_CONFIRM');

$snapDir = storage_path('app/tmp/topic-grouping-acceptance');
if (! is_dir($snapDir)) {
    mkdir($snapDir, 0775, true);
}

$semanticReady = static function (): bool {
    $base = rtrim((string) config('semantic.url', env('SEMANTIC_URL', 'http://127.0.0.1:8088')), '/');
    try {
        $response = Http::timeout(3)->acceptJson()->get($base.'/health/ready');
        if (! $response->successful()) {
            return false;
        }
        $json = $response->json();
        if (is_array($json) && array_key_exists('ready', $json)) {
            return (bool) $json['ready'];
        }

        return true;
    } catch (Throwable) {
        return false;
    }
};

/**
 * @return array<string, mixed>
 */
$captureSnapshot = static function (int $siteId, string $label) use ($snapDir): array {
    $topics = SeoTopic::query()->where('site_id', $siteId)->orderBy('id')->get();
    $topicIds = $topics->pluck('id')->map(static fn ($id): int => (int) $id)->all();

    $membershipRows = SeoTopicKeyword::query()
        ->where('site_id', $siteId)
        ->orderBy('topic_id')
        ->orderBy('keyword_id')
        ->get(['topic_id', 'keyword_id', 'source', 'is_seed', 'is_locked']);
    $memberships = [];
    foreach ($membershipRows as $row) {
        $memberships[] = [
            'topic_id' => (int) $row->topic_id,
            'keyword_id' => (int) $row->keyword_id,
            'source' => (string) $row->source,
            'is_seed' => (bool) $row->is_seed,
            'is_locked' => (bool) $row->is_locked,
        ];
    }

    $tagAssignments = [];
    if (Schema::connection('omi_seo_ai')->hasTable('seo_topic_tag_assignments') && $topicIds !== []) {
        $tagRows = SeoTopicTagAssignment::query()
            ->whereIn('topic_id', $topicIds)
            ->orderBy('topic_id')
            ->orderBy('tag_id')
            ->get(['topic_id', 'tag_id', 'source']);
        foreach ($tagRows as $row) {
            $tagAssignments[] = [
                'topic_id' => (int) $row->topic_id,
                'tag_id' => (int) $row->tag_id,
                'source' => (string) ($row->source ?? TopicTagAssignmentSource::MANUAL),
            ];
        }
    }

    /** @var TopicLinkedArticleCounter $focusCounter */
    $focusCounter = app(TopicLinkedArticleCounter::class);
    $focusMap = $focusCounter->siteFocusArticleIdMap($siteId);
    $focusBindings = [];
    foreach ($focusMap as $keywordId => $articleId) {
        $focusBindings[] = [
            'keyword_id' => (int) $keywordId,
            'article_id' => (int) $articleId,
        ];
    }

    $articleIds = [];
    if (Schema::connection('omi_seo_ai')->hasTable('articles')) {
        $articleIds = DB::connection('omi_seo_ai')
            ->table('articles')
            ->where('site_id', $siteId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    $topicRows = [];
    foreach ($topics as $t) {
        $topicRows[] = [
            'id' => (int) $t->id,
            'name' => (string) $t->name,
            'source' => (string) $t->source,
            'status' => (string) $t->status,
            'is_locked' => (bool) $t->is_locked,
            'mcp_excluded' => (bool) ($t->mcp_excluded ?? false),
        ];
    }

    $payload = [
        'site_id' => $siteId,
        'label' => $label,
        'captured_at' => now()->toIso8601String(),
        'topic_count' => count($topicRows),
        'membership_count' => count($memberships),
        'manual_topics' => count(array_filter($topicRows, static fn (array $t): bool => $t['source'] === 'manual')),
        'topic_locks' => count(array_filter($topicRows, static fn (array $t): bool => $t['is_locked'])),
        'membership_locks' => count(array_filter($memberships, static fn (array $m): bool => $m['is_locked'])),
        'mcp_excluded' => TopicMcpExclusionService::columnReady()
            ? count(array_filter($topicRows, static fn (array $t): bool => $t['mcp_excluded']))
            : 0,
        'tag_assignments' => count($tagAssignments),
        'manual_tag_assignments' => count(array_filter(
            $tagAssignments,
            static fn (array $t): bool => ($t['source'] ?? '') === TopicTagAssignmentSource::MANUAL,
        )),
        'focus_article_bindings' => count($focusBindings),
        'article_count' => count($articleIds),
        'topics' => $topicRows,
        'memberships' => $memberships,
        'topic_tag_assignments' => $tagAssignments,
        'focus_bindings' => $focusBindings,
        'focus_map' => $focusMap,
        'article_ids' => $articleIds,
    ];

    $path = $snapDir.'/site_'.$siteId.'_'.$label.'_'.date('Ymd_His').'.json';
    file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $payload['snapshot_path'] = $path;

    return $payload;
};

/**
 * @param  array<string, mixed>  $before
 * @param  array<string, mixed>  $after
 * @param  TopicGroupingApplyPlan  $plan
 * @param  array<string|int, mixed>  $topicIdsByGroupKey
 * @return array<string, string>
 */
$runIntegrity = static function (array $before, array $after, TopicGroupingApplyPlan $plan, int $siteId, array $topicIdsByGroupKey = []): array {
    $checks = [];

    $dupKw = DB::connection('omi_seo_ai')->table('seo_topic_keywords')
        ->select('keyword_id', DB::raw('COUNT(*) as c'))
        ->where('site_id', $siteId)
        ->groupBy('keyword_id')
        ->having('c', '>', 1)
        ->count();
    $checks['membership_unique'] = $dupKw === 0 ? 'PASS' : 'FAIL';

    $orphanMembership = SeoTopicKeyword::query()
        ->where('site_id', $siteId)
        ->whereNotIn('topic_id', SeoTopic::query()->where('site_id', $siteId)->select('id'))
        ->count();
    $checks['orphan_membership'] = $orphanMembership === 0 ? 'PASS' : 'FAIL';

    $orphanDna = 0;
    if (Schema::connection('omi_seo_ai')->hasTable('seo_topic_keyword_dna')) {
        $orphanDna = SeoTopicKeywordDna::query()
            ->where('site_id', $siteId)
            ->whereNotIn('topic_id', SeoTopic::query()->where('site_id', $siteId)->select('id'))
            ->count();
    }
    $checks['orphan_dna'] = $orphanDna === 0 ? 'PASS' : 'FAIL';

    $afterTopicIdSet = [];
    foreach ($after['topics'] ?? [] as $t) {
        $afterTopicIdSet[(int) $t['id']] = true;
    }
    $orphanTags = 0;
    foreach ($after['topic_tag_assignments'] ?? [] as $row) {
        $tid = (int) ($row['topic_id'] ?? 0);
        if ($tid > 0 && ! isset($afterTopicIdSet[$tid])) {
            $orphanTags++;
        }
    }
    $checks['orphan_tags'] = $orphanTags === 0 ? 'PASS' : 'FAIL';

    $missingManual = TopicGroupingAcceptanceSupport::missingManualTopicIds(
        $before['topics'] ?? [],
        $after['topics'] ?? [],
    );
    $checks['manual_state'] = $missingManual === [] ? 'PASS' : 'FAIL';

    $missingLocks = TopicGroupingAcceptanceSupport::missingLockedTopicIds(
        $before['topics'] ?? [],
        $after['topics'] ?? [],
    );
    $lockedMembershipFails = TopicGroupingAcceptanceSupport::lockedMembershipFailures(
        $before['memberships'] ?? [],
        $after['memberships'] ?? [],
    );
    $checks['locks'] = ($missingLocks === [] && $lockedMembershipFails === []) ? 'PASS' : 'FAIL';

    $tagFails = TopicGroupingAcceptanceSupport::manualTagFailures(
        $before['topic_tag_assignments'] ?? [],
        $after['topic_tag_assignments'] ?? [],
        $plan->businessState['metadata_migrations'] ?? [],
    );
    $checks['manual_tags'] = $tagFails === [] ? 'PASS' : 'FAIL';

    $mcpFails = TopicGroupingAcceptanceSupport::mcpExclusionFailures(
        $before['topics'] ?? [],
        $after['topics'] ?? [],
        $plan->businessState['policy_migrations'] ?? [],
        $topicIdsByGroupKey,
    );
    $checks['mcp'] = $mcpFails === [] ? 'PASS' : 'FAIL';

    $checks['focus_bindings'] = TopicGroupingAcceptanceSupport::focusMapsIdentical(
        $before['focus_map'] ?? [],
        $after['focus_map'] ?? [],
    ) ? 'PASS' : 'FAIL';

    $checks['articles'] = TopicGroupingAcceptanceSupport::articleIdSetsIdentical(
        $before['article_ids'] ?? [],
        $after['article_ids'] ?? [],
    ) ? 'PASS' : 'FAIL';

    if ($missingManual !== []) {
        fwrite(STDERR, 'FAIL manual_state missing_ids='.json_encode($missingManual).PHP_EOL);
    }
    if ($missingLocks !== []) {
        fwrite(STDERR, 'FAIL locks topics='.json_encode($missingLocks).PHP_EOL);
    }
    foreach ($lockedMembershipFails as $msg) {
        fwrite(STDERR, 'FAIL '.$msg.PHP_EOL);
    }
    foreach ($tagFails as $msg) {
        fwrite(STDERR, 'FAIL '.$msg.PHP_EOL);
    }
    foreach ($mcpFails as $msg) {
        fwrite(STDERR, 'FAIL '.$msg.PHP_EOL);
    }

    return $checks;
};

$planChurn = static function (TopicGroupingApplyPlan $plan, ?int $currentTopicCount = null): array {
    $c = $plan->counts;

    return [
        'topics' => $currentTopicCount ?? (int) ($plan->identityMigration['existing_topics'] ?? $c['existing_topics'] ?? 0),
        'semantic_groups' => (int) ($c['semantic_groups'] ?? 0),
        'reuse' => (int) ($c['topics_reused'] ?? 0),
        'create' => (int) ($c['topics_created'] ?? 0),
        'dissolve' => (int) ($c['topics_dissolved'] ?? 0),
        'keep' => (int) ($c['keywords_kept'] ?? 0),
        'assign' => (int) ($c['keywords_assigned'] ?? 0),
        'move' => (int) ($c['keywords_moved'] ?? 0),
        'unassign' => (int) ($c['keywords_unassigned'] ?? 0),
    ];
};

echo 'provider='.TopicGroupingProviderMode::current().PHP_EOL;
echo 'live_acceptance_flag='.($live ? '1' : '0').PHP_EOL;
echo 'expect_semantic_offline='.($expectOffline ? '1' : '0').PHP_EOL;

$run = SeoTopicGroupingRun::query()
    ->where('site_id', $siteId)
    ->whereIn('status', ['proposal_ready', 'apply_failed'])
    ->orderByDesc('id')
    ->first();
if ($run === null) {
    fwrite(STDERR, "No proposal_ready/apply_failed run for site {$siteId}. Run Analyze first.\n");
    exit(2);
}

$service = app(TopicGroupingApplyService::class);
$preview = $service->preview((int) $run->id);
if (! $preview->ok() || $preview->plan === null) {
    fwrite(STDERR, 'Preview failed: '.$preview->status.' '.$preview->errorMessage.PHP_EOL);
    exit(3);
}
$plan = $preview->plan;
$hardBlock = $plan->isHardBlocked();
$firstChurn = $planChurn($plan); // topics filled after before-snapshot

echo "run_id={$run->id}".PHP_EOL;
echo 'plan_hash='.$plan->planHash.PHP_EOL;
echo 'hard_block='.($hardBlock ? 'true' : 'false').PHP_EOL;
echo 'counts='.json_encode($plan->counts).PHP_EOL;
echo 'identity='.json_encode([
    'existing' => $plan->identityMigration['existing_topics'] ?? null,
    'semantic' => $plan->identityMigration['semantic_groups'] ?? null,
    'reuse' => $plan->counts['topics_reused'] ?? null,
    'create' => $plan->counts['topics_created'] ?? null,
    'dissolve' => $plan->counts['topics_dissolved'] ?? null,
]).PHP_EOL;
echo 'business='.json_encode($plan->businessState['summary'] ?? []).PHP_EOL;

$before = $captureSnapshot($siteId, 'before');
$firstChurn['topics'] = (int) $before['topic_count'];
echo 'before_snapshot='.$before['snapshot_path'].PHP_EOL;
echo 'before_topics='.$before['topic_count'].' memberships='.$before['membership_count'].PHP_EOL;

if ($hardBlock) {
    echo 'LIVE APPLY READY = NO (hard_block)'.PHP_EOL;
    exit(4);
}

if (! $live) {
    echo 'LIVE APPLY = NOT EXECUTED'.PHP_EOL;
    echo 'LIVE APPLY READY = YES'.PHP_EOL;
    echo 'Set TOPIC_GROUPING_LIVE_ACCEPTANCE=1 to perform controlled Apply.'.PHP_EOL;
    exit(0);
}

echo PHP_EOL.'READY FOR OFFLINE APPLY TEST'.PHP_EOL;
echo 'Stop semantic-api now if you want to prove offline Apply.'.PHP_EOL;
echo 'Then confirm/continue.'.PHP_EOL;

if ($expectOffline) {
    if ($semanticReady()) {
        fwrite(STDERR, "ABORT: TOPIC_GROUPING_ACCEPTANCE_EXPECT_SEMANTIC_OFFLINE=1 but semantic /health/ready is reachable.\n");
        exit(6);
    }
    echo 'OFFLINE APPLY = VERIFIED (semantic unreachable before Apply)'.PHP_EOL;
} else {
    echo 'OFFLINE APPLY = NOT VERIFIED THIS RUN'.PHP_EOL;
    if (! $autoConfirm) {
        echo 'Press Enter to Apply (Ctrl+C to abort)...'.PHP_EOL;
        fgets(STDIN);
    }
}

echo 'LIVE APPLY ENABLED — applying once.'.PHP_EOL;
$applied = $service->apply((int) $run->id, $plan->planHash);
echo 'apply_status='.$applied->status.PHP_EOL;
if (! $applied->ok()) {
    fwrite(STDERR, 'Apply failed: '.$applied->errorCode.' '.$applied->errorMessage.PHP_EOL);
    exit(5);
}

$after = $captureSnapshot($siteId, 'after');
echo 'after_snapshot='.$after['snapshot_path'].PHP_EOL;
echo 'after_topics='.$after['topic_count'].' memberships='.$after['membership_count'].PHP_EOL;

$topicIdsByGroupKey = TopicGroupingAcceptanceSupport::normalizeTopicIdsByGroupKey(
    is_array($applied->metrics['topic_ids_by_group_key'] ?? null)
        ? $applied->metrics['topic_ids_by_group_key']
        : [],
);
echo 'topic_ids_by_group_key='.json_encode($topicIdsByGroupKey).PHP_EOL;

$integrity = $runIntegrity($before, $after, $plan, $siteId, $topicIdsByGroupKey);
$previewVsActual = TopicGroupingAcceptanceSupport::previewVsActual($plan->counts, $applied->metrics);

$integrityFail = in_array('FAIL', $integrity, true);
$previewFail = in_array('FAIL', $previewVsActual, true);

echo PHP_EOL.'LIVE APPLY'.PHP_EOL;
echo "run_id={$run->id}".PHP_EOL;
echo 'status='.(($integrityFail || $previewFail) ? 'FAIL' : 'PASS').PHP_EOL;

echo PHP_EOL.'SNAPSHOTS'.PHP_EOL;
echo 'before='.$before['snapshot_path'].PHP_EOL;
echo 'after='.$after['snapshot_path'].PHP_EOL;

echo PHP_EOL.'INTEGRITY'.PHP_EOL;
foreach ($integrity as $k => $v) {
    echo "{$k}={$v}".PHP_EOL;
}

echo PHP_EOL.'PREVIEW VS ACTUAL'.PHP_EOL;
foreach ($previewVsActual as $k => $v) {
    echo "{$k}={$v}".PHP_EOL;
}
echo 'apply_metrics='.json_encode($applied->metrics).PHP_EOL;

if ($integrityFail || $previewFail) {
    echo PHP_EOL.'FINAL'.PHP_EOL;
    echo 'PRODUCTION CUTOVER ACCEPTANCE = FAIL'.PHP_EOL;
    echo 'No automatic rollback/recluster/second-Apply performed. Investigate snapshots.'.PHP_EOL;
    exit(7);
}

// --- Convergence: fresh Analyze + Preview only ---
echo PHP_EOL.'CONVERGENCE'.PHP_EOL;

if ($expectOffline && ! $semanticReady()) {
    echo 'OFFLINE APPLY VERIFIED — restart semantic-api now for convergence'.PHP_EOL;
    if (! $autoConfirm) {
        echo 'Press Enter after semantic-api is ready (Ctrl+C to abort)...'.PHP_EOL;
        fgets(STDIN);
    } else {
        echo 'AUTO_CONFIRM: polling /health/ready up to 120s (does not start Docker)...'.PHP_EOL;
        $deadline = time() + 120;
        while (time() < $deadline) {
            if ($semanticReady()) {
                break;
            }
            sleep(2);
        }
    }
}

if (! $semanticReady()) {
    echo 'second_run_id='.PHP_EOL;
    echo 'first='.json_encode($firstChurn).PHP_EOL;
    echo 'second='.PHP_EOL;
    echo 'classification=PENDING'.PHP_EOL;
    echo 'CONVERGENCE = PENDING — semantic unavailable'.PHP_EOL;
    echo PHP_EOL.'FINAL'.PHP_EOL;
    echo 'PRODUCTION CUTOVER ACCEPTANCE = PARTIAL'.PHP_EOL;
    exit(8);
}

$analysis = app(TopicGroupingAnalysisService::class);
$secondRun = $analysis->analyzeSite($siteId);
if ((string) $secondRun->status !== 'proposal_ready') {
    fwrite(STDERR, 'Second Analyze failed: status='.$secondRun->status.' code='.$secondRun->error_code.PHP_EOL);
    echo 'classification=PENDING'.PHP_EOL;
    echo PHP_EOL.'FINAL'.PHP_EOL;
    echo 'PRODUCTION CUTOVER ACCEPTANCE = PARTIAL'.PHP_EOL;
    exit(9);
}

$secondPreview = $service->preview((int) $secondRun->id);
if (! $secondPreview->ok() || $secondPreview->plan === null) {
    fwrite(STDERR, 'Second Preview failed: '.$secondPreview->status.PHP_EOL);
    echo PHP_EOL.'FINAL'.PHP_EOL;
    echo 'PRODUCTION CUTOVER ACCEPTANCE = PARTIAL'.PHP_EOL;
    exit(10);
}

$secondChurn = $planChurn($secondPreview->plan, (int) $after['topic_count']);
$conv = TopicGroupingAcceptanceSupport::classifyConvergence(
    ['move' => $firstChurn['move'], 'create' => $firstChurn['create'], 'dissolve' => $firstChurn['dissolve']],
    ['move' => $secondChurn['move'], 'create' => $secondChurn['create'], 'dissolve' => $secondChurn['dissolve']],
);

echo 'second_run_id='.$secondRun->id.PHP_EOL;
echo 'first='.json_encode($firstChurn).PHP_EOL;
echo 'second='.json_encode($secondChurn).PHP_EOL;
echo 'ratios='.json_encode($conv['ratios']).PHP_EOL;
echo 'classification='.$conv['classification'].PHP_EOL;
echo 'NOTE: second proposal Preview-only — NOT Applied.'.PHP_EOL;

$final = match ($conv['classification']) {
    'STABLE' => 'PASS',
    'NEEDS_REVIEW' => 'PARTIAL',
    default => 'FAIL',
};

echo PHP_EOL.'FINAL'.PHP_EOL;
echo 'PRODUCTION CUTOVER ACCEPTANCE = '.$final.PHP_EOL;

exit(match ($final) {
    'PASS' => 0,
    'PARTIAL' => 11,
    default => 12,
});
