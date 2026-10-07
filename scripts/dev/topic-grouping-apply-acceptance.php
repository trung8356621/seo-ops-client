<?php

declare(strict_types=1);

/**
 * READ-ONLY acceptance helper for TASK 5 (site Topic grouping Apply).
 * Usage: php artisan tinker --execute="require base_path('scripts/dev/topic-grouping-apply-acceptance.php');"
 * Or: php scripts/dev/topic-grouping-apply-acceptance.php (bootstraps app)
 *
 * Does NOT apply unless argv contains --apply and plan is confirmed coherent.
 */

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicSource;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicGroupingRun;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingApplyService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicGroupingAnalysisService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicLinkedArticleCounter;

$siteId = (int) ($argv[1] ?? 4);
$doApply = in_array('--apply', $argv ?? [], true);
$doAnalyze = in_array('--analyze', $argv ?? [], true);

if (! isset($app)) {
    require __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
}

$snapshotDir = storage_path('app/tmp');
if (! is_dir($snapshotDir)) {
    mkdir($snapshotDir, 0775, true);
}

$snap = static function (int $siteId): array {
    $topics = SeoTopic::query()->where('site_id', $siteId)->get(['id', 'name', 'source', 'is_locked']);
    $members = SeoTopicKeyword::query()->where('site_id', $siteId)->get(['topic_id', 'keyword_id', 'is_locked', 'is_seed', 'source']);
    $manual = $topics->where('source', TopicSource::MANUAL)->count();
    $lockedTopics = $topics->where('is_locked', true)->count();
    $lockedMembers = $members->where('is_locked', true)->count();
    $focus = 0;
    try {
        $counter = app(TopicLinkedArticleCounter::class);
        foreach ($topics as $topic) {
            $focus += $counter->countForTopic($siteId, (int) $topic->id);
        }
    } catch (Throwable) {
        $focus = -1;
    }

    return [
        'site_id' => $siteId,
        'topic_count' => $topics->count(),
        'membership_count' => $members->count(),
        'manual_count' => $manual,
        'locked_topics' => $lockedTopics,
        'locked_memberships' => $lockedMembers,
        'focus_article_bindings_est' => $focus,
        'topics' => $topics->map(static fn ($t): array => [
            'id' => (int) $t->id,
            'name' => (string) $t->name,
            'source' => (string) $t->source,
            'is_locked' => (bool) $t->is_locked,
        ])->values()->all(),
        'memberships' => $members->map(static fn ($m): array => [
            'topic_id' => (int) $m->topic_id,
            'keyword_id' => (int) $m->keyword_id,
            'is_locked' => (bool) $m->is_locked,
            'is_seed' => (bool) $m->is_seed,
            'source' => (string) $m->source,
        ])->values()->all(),
    ];
};

$before = $snap($siteId);
$beforePath = $snapshotDir.'/topic-apply-site'.$siteId.'-before-'.date('Ymd-His').'.json';
file_put_contents($beforePath, json_encode($before, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "BEFORE topics={$before['topic_count']} memberships={$before['membership_count']} manual={$before['manual_count']} locked_t={$before['locked_topics']} locked_m={$before['locked_memberships']} focus≈{$before['focus_article_bindings_est']}\n";
echo "snapshot=$beforePath\n";

if ($doAnalyze) {
    $run = app(TopicGroupingAnalysisService::class)->analyzeSite($siteId);
    echo "ANALYZE status={$run->status} run_id={$run->id} groups={$run->group_count} unassigned={$run->unassigned_count} low={$run->low_confidence_count}\n";
} else {
    $run = SeoTopicGroupingRun::query()
        ->where('site_id', $siteId)
        ->where('status', 'proposal_ready')
        ->orderByDesc('id')
        ->first();
    if ($run === null) {
        echo "NO proposal_ready run — pass --analyze to create one\n";
        exit(2);
    }
    echo "USING run_id={$run->id} groups={$run->group_count} unassigned={$run->unassigned_count}\n";
}

$preview = app(TopicGroupingApplyService::class)->preview((int) $run->id);
if (! $preview->ok() || $preview->plan === null) {
    echo "PREVIEW FAILED status={$preview->status} code={$preview->errorCode} msg={$preview->errorMessage}\n";
    exit(3);
}

$c = $preview->plan->counts;
echo "PREVIEW plan_hash=".substr($preview->plan->planHash, 0, 12)."\n";
echo 'PREVIEW counts='.json_encode($c)."\n";
echo 'PREVIEW warnings='.count($preview->plan->warnings)." sample=".json_encode(array_slice($preview->plan->warnings, 0, 8))."\n";

$risky = ((int) ($c['topics_dissolved'] ?? 0) > 20)
    || ((int) ($c['keywords_unassigned'] ?? 0) > 200)
    || ((int) ($c['topics_created'] ?? 0) > 80);

if ($risky) {
    echo "RISK GATE: plan looks destructive — NOT applying (dissolved/unassigned/created thresholds).\n";
    exit(4);
}

if (! $doApply) {
    echo "Dry-run only. Re-run with --apply to mutate after reviewing PREVIEW.\n";
    exit(0);
}

$applied = app(TopicGroupingApplyService::class)->apply((int) $run->id, $preview->plan->planHash);
echo "APPLY status={$applied->status} code={$applied->errorCode} msg={$applied->errorMessage}\n";
$after = $snap($siteId);
$afterPath = $snapshotDir.'/topic-apply-site'.$siteId.'-after-'.date('Ymd-His').'.json';
file_put_contents($afterPath, json_encode($after, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "AFTER topics={$after['topic_count']} memberships={$after['membership_count']} manual={$after['manual_count']} locked_t={$after['locked_topics']} locked_m={$after['locked_memberships']} focus≈{$after['focus_article_bindings_est']}\n";
echo "snapshot=$afterPath\n";
