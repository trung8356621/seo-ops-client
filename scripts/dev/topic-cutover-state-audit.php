<?php

declare(strict_types=1);

/**
 * READ-ONLY site Topic cutover business-state audit (TASK 5.2).
 * php scripts/dev/topic-cutover-state-audit.php [siteId]
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicTagAssignment;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingApplyService;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicGroupingRun;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicLinkedArticleCounter;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicTagAssignmentSource;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicMcpExclusionService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicUserTagService;

$siteId = (int) ($argv[1] ?? 4);

$run = SeoTopicGroupingRun::query()
    ->where('site_id', $siteId)
    ->where('status', 'proposal_ready')
    ->orderByDesc('id')
    ->first();
if ($run === null) {
    fwrite(STDERR, "no proposal_ready\n");
    exit(2);
}

$preview = app(TopicGroupingApplyService::class)->preview((int) $run->id);
if (! $preview->ok() || $preview->plan === null) {
    fwrite(STDERR, 'preview failed: '.$preview->status.' '.$preview->errorMessage."\n");
    exit(3);
}
$plan = $preview->plan;
$dissolveIds = [];
foreach ($plan->topicActions as $a) {
    if ($a['action'] === 'dissolve' && $a['topic_id']) {
        $dissolveIds[] = (int) $a['topic_id'];
    }
}
$reuseIds = [];
foreach ($plan->topicActions as $a) {
    if ($a['action'] === 'reuse' && $a['topic_id']) {
        $reuseIds[] = (int) $a['topic_id'];
    }
}

$hasSource = TopicUserTagService::provenanceReady();
$hasMcp = TopicMcpExclusionService::columnReady();
$hasAssign = Schema::connection('omi_seo_ai')->hasTable('seo_topic_tag_assignments');

$topics = SeoTopic::query()->where('site_id', $siteId)->get();
$topicById = [];
foreach ($topics as $t) {
    $topicById[(int) $t->id] = $t;
}

$assignments = [];
if ($hasAssign) {
    $q = SeoTopicTagAssignment::query()->whereIn('topic_id', $topics->pluck('id')->all());
    foreach ($q->get() as $row) {
        $assignments[(int) $row->topic_id][] = $row;
    }
}

$focusCounter = app(TopicLinkedArticleCounter::class);
$focusCounts = $focusCounter->countForTopics($siteId, array_keys($topicById));

$summarize = static function (array $ids) use ($topicById, $assignments, $focusCounts, $hasSource, $hasMcp): array {
    $manualTags = 0;
    $aiTags = 0;
    $topicsWithManual = 0;
    $topicsWithAi = 0;
    $mcp = 0;
    $focusKw = 0;
    $manualTopic = 0;
    $locked = 0;
    $derivedOnly = 0;
    foreach ($ids as $id) {
        $t = $topicById[$id] ?? null;
        if ($t === null) {
            continue;
        }
        if ((string) $t->source === 'manual') {
            $manualTopic++;
        }
        if ($t->is_locked) {
            $locked++;
        }
        if ($hasMcp && (bool) ($t->mcp_excluded ?? false)) {
            $mcp++;
        }
        if ((int) ($focusCounts[$id] ?? 0) > 0) {
            $focusKw++;
        }
        $rows = $assignments[$id] ?? [];
        $m = 0;
        $a = 0;
        foreach ($rows as $row) {
            if ($hasSource && TopicTagAssignmentSource::isAi((string) ($row->source ?? ''))) {
                $a++;
                $aiTags++;
            } else {
                $m++;
                $manualTags++;
            }
        }
        if ($m > 0) {
            $topicsWithManual++;
        }
        if ($a > 0) {
            $topicsWithAi++;
        }
        $hasBusiness = $m > 0
            || ($hasMcp && (bool) ($t->mcp_excluded ?? false))
            || (string) $t->source === 'manual'
            || (bool) $t->is_locked;
        if (! $hasBusiness) {
            $derivedOnly++;
        }
    }

    return compact(
        'manualTags',
        'aiTags',
        'topicsWithManual',
        'topicsWithAi',
        'mcp',
        'focusKw',
        'manualTopic',
        'locked',
        'derivedOnly',
    ) + ['count' => count($ids)];
};

$allIds = array_keys($topicById);
$bs = $plan->businessState;
echo "run_id={$run->id} site={$siteId}\n";
echo 'plan reused='.$plan->counts['topics_reused']
    .' created='.$plan->counts['topics_created']
    .' dissolved='.$plan->counts['topics_dissolved']."\n";
echo 'tables assign='.($hasAssign ? '1' : '0').' provenance='.($hasSource ? '1' : '0').' mcp='.($hasMcp ? '1' : '0')."\n";
echo 'BUSINESS_STATE='.json_encode([
    'hard_block' => (bool) ($bs['hard_block'] ?? false),
    'summary' => $bs['summary'] ?? [],
    'review' => $bs['metadata_review_required'] ?? [],
    'meta_migrations' => count($bs['metadata_migrations'] ?? []),
    'policy_migrations' => count($bs['policy_migrations'] ?? []),
    'focus_changing' => (int) ($plan->identityMigration['topics_with_focus_keywords_changing_identity'] ?? 0),
])."\n";
echo 'WARNINGS='.json_encode($plan->warnings)."\n";
echo 'ALL='.json_encode($summarize($allIds))."\n";
echo 'DISSOLVE='.json_encode($summarize($dissolveIds))."\n";
echo 'REUSE='.json_encode($summarize($reuseIds))."\n";
echo 'LIVE_APPLY_READY='.(($bs['hard_block'] ?? false) ? 'NO' : 'YES')."\n";

if ($dissolveIds !== []) {
    echo "DISSOLVE_DETAIL:\n";
    foreach ($dissolveIds as $id) {
        $t = $topicById[$id];
        $rows = $assignments[$id] ?? [];
        $m = 0;
        $a = 0;
        foreach ($rows as $row) {
            if ($hasSource && TopicTagAssignmentSource::isAi((string) ($row->source ?? ''))) {
                $a++;
            } else {
                $m++;
            }
        }
        echo "  #{$id} {$t->name} source={$t->source} locked=".((int) $t->is_locked)
            .' mcp='.(int) ($t->mcp_excluded ?? false)
            ." manual_tags={$m} ai_tags={$a} focus=".(int) ($focusCounts[$id] ?? 0)."\n";
    }
}
