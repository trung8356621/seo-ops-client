<?php

declare(strict_types=1);

/**
 * TASK 6 final acceptance (READ-ONLY by default).
 *
 * Live Apply requires explicit:
 *   TOPIC_GROUPING_LIVE_ACCEPTANCE=1
 *
 * Usage:
 *   php scripts/dev/topic-grouping-final-acceptance.php [siteId]
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicGroupingRun;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeywordDna;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicTagAssignment;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingApplyService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicLinkedArticleCounter;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicMcpExclusionService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicGroupingProviderMode;

$siteId = (int) ($argv[1] ?? 4);
$live = in_array(strtolower((string) env('TOPIC_GROUPING_LIVE_ACCEPTANCE', '')), ['1', 'true', 'yes'], true)
    || in_array(strtolower((string) (getenv('TOPIC_GROUPING_LIVE_ACCEPTANCE') ?: '')), ['1', 'true', 'yes'], true);

$snapDir = storage_path('app/tmp/topic-grouping-acceptance');
if (! is_dir($snapDir)) {
    mkdir($snapDir, 0775, true);
}

$snapshot = static function (int $siteId) use ($snapDir): array {
    $topics = SeoTopic::query()->where('site_id', $siteId)->orderBy('id')->get();
    $memberships = SeoTopicKeyword::query()->where('site_id', $siteId)->count();
    $manual = $topics->where('source', 'manual')->count();
    $locked = $topics->where('is_locked', true)->count();
    $mcp = TopicMcpExclusionService::columnReady()
        ? $topics->where('mcp_excluded', true)->count()
        : 0;
    $kwLocks = SeoTopicKeyword::query()->where('site_id', $siteId)->where('is_locked', true)->count();
    $tags = Schema::connection('omi_seo_ai')->hasTable('seo_topic_tag_assignments')
        ? SeoTopicTagAssignment::query()->whereIn('topic_id', $topics->pluck('id'))->count()
        : 0;
    $focus = array_sum(app(TopicLinkedArticleCounter::class)->countForTopics(
        $siteId,
        $topics->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
    ));
    $rows = [];
    foreach ($topics as $t) {
        $rows[] = [
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
        'captured_at' => now()->toIso8601String(),
        'topic_count' => $topics->count(),
        'membership_count' => $memberships,
        'manual_topics' => $manual,
        'topic_locks' => $locked,
        'membership_locks' => $kwLocks,
        'mcp_excluded' => $mcp,
        'tag_assignments' => $tags,
        'focus_article_bindings' => $focus,
        'topics' => $rows,
    ];
    $path = $snapDir.'/site_'.$siteId.'_before_'.date('Ymd_His').'.json';
    file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $payload['snapshot_path'] = $path;

    return $payload;
};

echo "provider=".TopicGroupingProviderMode::current().PHP_EOL;
echo 'live_acceptance_flag='.($live ? '1' : '0').PHP_EOL;

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

$before = $snapshot($siteId);
echo 'before_snapshot='.$before['snapshot_path'].PHP_EOL;
echo 'before_topics='.$before['topic_count'].' memberships='.$before['membership_count'].PHP_EOL;

if ($hardBlock) {
    echo "LIVE APPLY READY = NO (hard_block)".PHP_EOL;
    exit(4);
}

if (! $live) {
    echo "LIVE APPLY = NOT EXECUTED".PHP_EOL;
    echo 'LIVE APPLY READY = YES'.PHP_EOL;
    echo "Set TOPIC_GROUPING_LIVE_ACCEPTANCE=1 to perform controlled Apply.".PHP_EOL;
    exit(0);
}

echo "LIVE APPLY ENABLED — applying once with semantic independence (operator responsibility).".PHP_EOL;
$applied = $service->apply((int) $run->id, $plan->planHash);
echo 'apply_status='.$applied->status.PHP_EOL;
if (! $applied->ok()) {
    fwrite(STDERR, 'Apply failed: '.$applied->errorCode.' '.$applied->errorMessage.PHP_EOL);
    exit(5);
}

$afterTopics = SeoTopic::query()->where('site_id', $siteId)->count();
$afterMemberships = SeoTopicKeyword::query()->where('site_id', $siteId)->count();
$dupKw = DB::connection('omi_seo_ai')->table('seo_topic_keywords')
    ->select('keyword_id', DB::raw('COUNT(*) as c'))
    ->where('site_id', $siteId)
    ->groupBy('keyword_id')
    ->having('c', '>', 1)
    ->count();
$orphanMembership = SeoTopicKeyword::query()
    ->where('site_id', $siteId)
    ->whereNotIn('topic_id', SeoTopic::query()->where('site_id', $siteId)->select('id'))
    ->count();
$orphanDna = Schema::connection('omi_seo_ai')->hasTable('seo_topic_keyword_dna')
    ? SeoTopicKeywordDna::query()
        ->where('site_id', $siteId)
        ->whereNotIn('topic_id', SeoTopic::query()->where('site_id', $siteId)->select('id'))
        ->count()
    : 0;

echo 'after_topics='.$afterTopics.' memberships='.$afterMemberships.PHP_EOL;
echo 'integrity_dup_keyword_memberships='.$dupKw.PHP_EOL;
echo 'integrity_orphan_memberships='.$orphanMembership.PHP_EOL;
echo 'integrity_orphan_dna='.$orphanDna.PHP_EOL;
echo 'apply_metrics='.json_encode($applied->metrics).PHP_EOL;
echo 'LIVE APPLY DONE'.PHP_EOL;
