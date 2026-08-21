<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ApplyTopicClusterProposalBatchService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterProposalEngine;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterProposalStrategy;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\DissolveTopicClusterService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\ApplyTopicClusterProposalBatchInput;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\ApplyTopicClusterProposalBatchMode;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\ApplyTopicClusterProposalBatchStatus;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterProposalCluster;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterEligibility;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterQuery;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

app(SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();

$siteId = (int) ($argv[1] ?? 5);
$strategy = KeywordClusterProposalStrategy::normalize((string) ($argv[2] ?? KeywordClusterProposalStrategy::BALANCED));
$pickCount = max(2, (int) ($argv[3] ?? 2));

$actor = User::query()->where('role', User::ROLE_OWNER)->orderBy('id')->first()
    ?? User::query()->orderBy('id')->first();
if ($actor instanceof User) {
    Auth::login($actor);
}

if (! SeoAccessControl::canAccessPlannerFeatures()
    || ! SeoAccessControl::canMutateInSeoPanel()
    || ! SeoAccessControl::canAccessSite($siteId)
) {
    echo "Authorization failed for user #".(auth()->id() ?? 0)." on site {$siteId}.\n";
    exit(1);
}

/** @var KeywordClusterEligibility $eligibility */
$eligibility = app(KeywordClusterEligibility::class);
/** @var KeywordClusterQuery $clusters */
$clusters = app(KeywordClusterQuery::class);
/** @var KeywordClusterProposalEngine $engine */
$engine = app(KeywordClusterProposalEngine::class);
/** @var ApplyTopicClusterProposalBatchService $batchService */
$batchService = app(ApplyTopicClusterProposalBatchService::class);
/** @var DissolveTopicClusterService $dissolveService */
$dissolveService = app(DissolveTopicClusterService::class);

$hubBefore = $eligibility->summaryMetrics($siteId);
$previewBefore = $engine->previewForSite($siteId, $strategy);

$readyClusters = [];
$needsReviewCount = 0;
$readyKeywordCount = 0;

foreach ($previewBefore->proposedClusters as $cluster) {
    if ($cluster->finalStatus === KeywordClusterProposalCluster::FINAL_READY) {
        if ($cluster->memberCount >= 2 && $cluster->memberCount <= 6 && $cluster->proposalFingerprint !== '') {
            $readyClusters[] = $cluster;
        }
    } elseif ($cluster->finalStatus === KeywordClusterProposalCluster::FINAL_NEEDS_REVIEW) {
        $needsReviewCount++;
    }
}

foreach ($previewBefore->proposedClusters as $cluster) {
    if ($cluster->finalStatus === KeywordClusterProposalCluster::FINAL_READY) {
        $readyKeywordCount += $cluster->memberCount;
    }
}

$allReadyCount = count(array_filter(
    $previewBefore->proposedClusters,
    static fn (KeywordClusterProposalCluster $c): bool => $c->finalStatus === KeywordClusterProposalCluster::FINAL_READY,
));

echo "=== SITE {$siteId} PHASE 2B ACCEPTANCE ===\n\n";

echo "=== TEST B — All READY modal counts (NO APPLY) ===\n";
echo '  READY clusters: '.$allReadyCount."\n";
echo '  READY keywords: '.$readyKeywordCount."\n";
echo '  NEEDS_REVIEW (skipped): '.$needsReviewCount."\n";
echo "  Action: modal only — All READY NOT executed.\n\n";

if (count($readyClusters) < $pickCount) {
    echo "Need at least {$pickCount} small READY clusters (2–6 members). Found ".count($readyClusters).".\n";
    exit(1);
}

usort($readyClusters, static fn (KeywordClusterProposalCluster $a, KeywordClusterProposalCluster $b): int => $a->memberCount <=> $b->memberCount);
$picked = array_slice($readyClusters, 0, $pickCount);

$fingerprints = array_map(static fn (KeywordClusterProposalCluster $c): string => $c->proposalFingerprint, $picked);
$expectedMemberIds = [];
$expectedKeywordCount = 0;

echo "=== TEST A — Selected batch ({$pickCount} small READY clusters) ===\n";
foreach ($picked as $index => $cluster) {
    $memberIds = array_values(array_filter(array_map(
        static fn (array $member): int => (int) ($member['keyword_id'] ?? 0),
        $cluster->members,
    ), static fn (int $id): bool => $id > 0));
    sort($memberIds, SORT_NUMERIC);

    echo '  Cluster '.($index + 1).': '.$cluster->representativeLabel
        .' | '.$cluster->memberCount.' members | IDs: '.implode(', ', $memberIds)."\n";

    foreach ($memberIds as $id) {
        $expectedMemberIds[$id] = true;
    }
    $expectedKeywordCount += count($memberIds);
}

echo "\nHub BEFORE:\n";
echo '  clustered: '.($hubBefore['clustered'] ?? 0)."\n";
echo '  unclustered: '.($hubBefore['unclustered'] ?? 0)."\n";
echo '  topic_clusters: '.($hubBefore['topic_clusters'] ?? $clusters->countClusters($siteId))."\n\n";

$batchInput = new ApplyTopicClusterProposalBatchInput(
    siteId: $siteId,
    strategy: $strategy,
    previewFingerprint: $previewBefore->previewFingerprint,
    mode: ApplyTopicClusterProposalBatchMode::SELECTED,
    selectedProposalFingerprints: $fingerprints,
);

$result = $batchService->apply($batchInput);
echo 'Batch apply status: '.$result->status."\n";
echo '  proposals: '.$result->proposalCount."\n";
echo '  keywords: '.$result->keywordCount."\n";
echo '  cluster keys: '.implode(', ', $result->clusterKeys)."\n";

if ($result->status !== ApplyTopicClusterProposalBatchStatus::APPLIED) {
    echo "Batch apply failed — aborting Test A.\n";
    exit(1);
}

if ($result->proposalCount !== $pickCount || $result->keywordCount !== $expectedKeywordCount) {
    echo "Count mismatch: expected {$pickCount}/{$expectedKeywordCount}, got {$result->proposalCount}/{$result->keywordCount}.\n";
    exit(1);
}

$hubAfterApply = $eligibility->summaryMetrics($siteId);
$clusteredDelta = ($hubAfterApply['clustered'] ?? 0) - ($hubBefore['clustered'] ?? 0);
$unclusteredDelta = ($hubAfterApply['unclustered'] ?? 0) - ($hubBefore['unclustered'] ?? 0);
$topicClustersBefore = (int) ($hubBefore['topic_clusters'] ?? $clusters->countClusters($siteId));
$topicClustersAfter = (int) ($hubAfterApply['topic_clusters'] ?? $clusters->countClusters($siteId));

echo "\nHub AFTER BATCH APPLY:\n";
echo '  clustered: '.($hubAfterApply['clustered'] ?? 0).' (delta +'.$clusteredDelta.")\n";
echo '  unclustered: '.($hubAfterApply['unclustered'] ?? 0).' (delta '.$unclusteredDelta.")\n";
echo '  topic_clusters: '.$topicClustersAfter.' (delta +'.($topicClustersAfter - $topicClustersBefore).")\n";

foreach (array_keys($expectedMemberIds) as $keywordId) {
    $key = SeoKeywordClassification::query()->whereKey($keywordId)->value('cluster_key');
    if (! is_string($key) || $key === '') {
        echo "FAIL: keyword {$keywordId} missing cluster_key after apply.\n";
        exit(1);
    }
}

foreach ($result->clusterKeys as $clusterKey) {
    if (! $clusters->clusterExists($clusterKey)) {
        echo "FAIL: cluster {$clusterKey} not visible in hub.\n";
        exit(1);
    }
}

$retry = $batchService->apply($batchInput);
echo "\nIdempotent batch retry: ".$retry->status."\n";

echo "\n=== DISSOLVE rollback (Bỏ cụm) ===\n";
foreach ($picked as $index => $cluster) {
    $clusterKey = $result->clusterKeys[$index] ?? '';
    if ($clusterKey === '') {
        continue;
    }
    $dissolved = $dissolveService->dissolve($siteId, $clusterKey, $cluster->representativeLabel);
    echo '  Dissolve '.$cluster->representativeLabel.': '.($dissolved->success ? 'OK' : 'FAIL')
        .' | affected '.$dissolved->affectedKeywordCount."\n";
}

$hubAfterDissolve = $eligibility->summaryMetrics($siteId);
echo "\nHub AFTER DISSOLVE:\n";
echo '  clustered: '.($hubAfterDissolve['clustered'] ?? 0)."\n";
echo '  unclustered: '.($hubAfterDissolve['unclustered'] ?? 0)."\n";
echo '  topic_clusters: '.($hubAfterDissolve['topic_clusters'] ?? $clusters->countClusters($siteId))."\n";

foreach (array_keys($expectedMemberIds) as $keywordId) {
    $key = SeoKeywordClassification::query()->whereKey($keywordId)->value('cluster_key');
    if ($key !== null && $key !== '') {
        echo "ROLLBACK FAIL: keyword {$keywordId} still has cluster_key={$key}\n";
        exit(1);
    }
}

$rollbackOk = ($hubAfterDissolve['clustered'] ?? 0) === ($hubBefore['clustered'] ?? 0)
    && ($hubAfterDissolve['unclustered'] ?? 0) === ($hubBefore['unclustered'] ?? 0);

$pass = $clusteredDelta === $expectedKeywordCount
    && $unclusteredDelta === -$expectedKeywordCount
    && ($topicClustersAfter - $topicClustersBefore) === $pickCount
    && $retry->status === ApplyTopicClusterProposalBatchStatus::ALREADY_APPLIED
    && $rollbackOk;

echo "\n=== PHASE 2B LOCAL ACCEPTANCE ===\n";
echo ($pass ? "PASS\n" : "FAIL\n");
echo 'Test A selected batch: '.($clusteredDelta === $expectedKeywordCount ? 'OK' : 'MISMATCH')."\n";
echo 'Recovery (Bỏ cụm): '.($rollbackOk ? 'OK' : 'MISMATCH')."\n";
echo 'Test B All READY modal counts printed — NOT auto-applied.'."\n";
echo "HARD STOP: All READY bulk apply was NOT executed on Site {$siteId}.\n";
echo "DONE\n";

exit($pass ? 0 : 1);
