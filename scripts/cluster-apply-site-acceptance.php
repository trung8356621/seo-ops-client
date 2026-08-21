<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ApplyTopicClusterProposalService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterProposalEngine;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterProposalStrategy;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\DissolveTopicClusterService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\ApplyTopicClusterProposalInput;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\ApplyTopicClusterProposalStatus;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterProposalCluster;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterQualityMetrics;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterEligibility;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterQuery;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

app(SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();

$siteId = (int) ($argv[1] ?? 5);
$strategy = KeywordClusterProposalStrategy::normalize((string) ($argv[2] ?? KeywordClusterProposalStrategy::BALANCED));
$needle = (string) ($argv[3] ?? 'siêu thị');

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
    echo '  planner: '.(SeoAccessControl::canAccessPlannerFeatures() ? 'yes' : 'no')."\n";
    echo '  mutate: '.(SeoAccessControl::canMutateInSeoPanel() ? 'yes' : 'no')."\n";
    echo '  site: '.(SeoAccessControl::canAccessSite($siteId) ? 'yes' : 'no')."\n";
    exit(1);
}

/** @var KeywordClusterEligibility $eligibility */
$eligibility = app(KeywordClusterEligibility::class);
/** @var KeywordClusterQuery $clusters */
$clusters = app(KeywordClusterQuery::class);
/** @var KeywordClusterProposalEngine $engine */
$engine = app(KeywordClusterProposalEngine::class);
/** @var ApplyTopicClusterProposalService $applyService */
$applyService = app(ApplyTopicClusterProposalService::class);
/** @var DissolveTopicClusterService $dissolveService */
$dissolveService = app(DissolveTopicClusterService::class);

$hubBefore = $eligibility->summaryMetrics($siteId);
$previewBefore = $engine->previewForSite($siteId, $strategy);

$target = null;
foreach ($previewBefore->proposedClusters as $cluster) {
    if ($cluster->finalStatus !== KeywordClusterProposalCluster::FINAL_READY) {
        continue;
    }
    if ($cluster->memberCount < 2 || $cluster->memberCount > 6) {
        continue;
    }
    if ($needle !== '' && mb_stripos($cluster->representativeLabel, $needle) === false) {
        continue;
    }

    $target = $cluster;
    break;
}

if ($target === null) {
    echo "No small READY cluster found for needle '{$needle}'.\n";
    exit(1);
}

$memberIds = array_values(array_filter(array_map(
    static fn (array $member): int => (int) ($member['keyword_id'] ?? 0),
    $target->members,
), static fn (int $id): bool => $id > 0));
sort($memberIds, SORT_NUMERIC);

echo "=== SITE {$siteId} PHASE 2A ACCEPTANCE ===\n\n";
echo 'Target: '.$target->representativeLabel.' | '.$target->memberCount." members | READY\n";
echo 'Member IDs: '.implode(', ', $memberIds)."\n";
echo 'Preview fingerprint: '.substr($previewBefore->previewFingerprint, 0, 16)."...\n\n";

echo "Hub BEFORE:\n";
echo '  clustered: '.($hubBefore['clustered'] ?? 0)."\n";
echo '  unclustered: '.($hubBefore['unclustered'] ?? 0)."\n";
echo '  candidate proposals: '.count($previewBefore->proposedClusters)."\n\n";

$input = new ApplyTopicClusterProposalInput(
    siteId: $siteId,
    strategy: $strategy,
    previewFingerprint: $previewBefore->previewFingerprint,
    proposalFingerprint: $target->proposalFingerprint,
    memberKeywordIds: $memberIds,
    representativeKeywordId: $target->representativeKeywordId,
    representativeLabel: $target->representativeLabel,
    finalStatus: $target->finalStatus,
    qualityState: $target->quality?->qualityState ?? KeywordClusterQualityMetrics::STATE_ACCEPTABLE,
);

$applied = $applyService->apply($input);
echo 'Apply status: '.$applied->status."\n";
echo 'Cluster key: '.$applied->clusterKey."\n";

if ($applied->status !== ApplyTopicClusterProposalStatus::APPLIED) {
    echo "Apply failed — aborting acceptance.\n";
    exit(1);
}

$hubAfterApply = $eligibility->summaryMetrics($siteId);
$previewAfterApply = $engine->previewForSite($siteId, $strategy);

echo "\nHub AFTER APPLY:\n";
echo '  clustered: '.($hubAfterApply['clustered'] ?? 0).' (delta +'.(($hubAfterApply['clustered'] ?? 0) - ($hubBefore['clustered'] ?? 0)).")\n";
echo '  unclustered: '.($hubAfterApply['unclustered'] ?? 0).' (delta '.(($hubAfterApply['unclustered'] ?? 0) - ($hubBefore['unclustered'] ?? 0)).")\n";
echo '  preview proposals: '.count($previewAfterApply->proposedClusters).' (was '.count($previewBefore->proposedClusters).")\n";
echo '  preview fingerprint changed: '.($previewAfterApply->previewFingerprint !== $previewBefore->previewFingerprint ? 'YES' : 'NO')."\n";

$exists = $clusters->clusterExists($applied->clusterKey);
echo '  cluster visible in hub: '.($exists ? 'YES' : 'NO')."\n";

$retry = $applyService->apply($input);
echo "\nIdempotent retry: ".$retry->status."\n";

$dissolved = $dissolveService->dissolve($siteId, $applied->clusterKey, $applied->representativeLabel);
echo 'Dissolve success: '.($dissolved->success ? 'yes' : 'no').' | affected '.$dissolved->affectedKeywordCount."\n";

$hubAfterDissolve = $eligibility->summaryMetrics($siteId);
echo "\nHub AFTER DISSOLVE (rollback check):\n";
echo '  clustered: '.($hubAfterDissolve['clustered'] ?? 0)."\n";
echo '  unclustered: '.($hubAfterDissolve['unclustered'] ?? 0)."\n";

foreach ($memberIds as $keywordId) {
    $key = SeoKeywordClassification::query()->whereKey($keywordId)->value('cluster_key');
    if ($key !== null && $key !== '') {
        echo "ROLLBACK FAIL: keyword {$keywordId} still has cluster_key={$key}\n";
        exit(1);
    }
}

echo "\nStale test: mutate classification_hash on one member...\n";
SeoKeywordClassification::query()->whereKey($memberIds[0])->update([
    'classification_hash' => hash('sha256', 'acceptance-stale-'.time()),
]);

$stale = $applyService->apply($input);
echo 'Stale apply after mutation: '.$stale->status."\n";

$rollbackOk = ($hubAfterDissolve['clustered'] ?? 0) === ($hubBefore['clustered'] ?? 0)
    && ($hubAfterDissolve['unclustered'] ?? 0) === ($hubBefore['unclustered'] ?? 0);
$pass = $retry->status === ApplyTopicClusterProposalStatus::ALREADY_APPLIED
    && $stale->status === ApplyTopicClusterProposalStatus::STALE
    && $previewAfterApply->previewFingerprint !== $previewBefore->previewFingerprint
    && $rollbackOk;

echo "\n=== PHASE 2A LOCAL ACCEPTANCE ===\n";
echo ($pass ? "PASS\n" : "FAIL\n");
echo 'Recovery restored hub counters: '.($rollbackOk ? 'YES' : 'NO')."\n";
echo "DONE\n";

exit($pass ? 0 : 1);
