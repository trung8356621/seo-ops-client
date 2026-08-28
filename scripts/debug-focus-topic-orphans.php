<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Canonical\CanonicalClusterPhraseResolver;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterEligibility;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterSiteScope;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$domainHint = $argv[1] ?? 'xuongmaytuikhongdet';

$site = DB::table('sites')->where('domain', 'like', '%'.$domainHint.'%')->first(['id', 'domain']);
if ($site === null) {
    fwrite(STDERR, "site not found for {$domainHint}\n");
    exit(1);
}

$siteId = (int) $site->id;
echo "SITE id={$siteId} domain={$site->domain}\n\n";

if (class_exists(\Omnichannel\Addons\Seo\Services\SeoDatabaseConnectionService::class)) {
    app(\Omnichannel\Addons\Seo\Services\SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();
}

$keywordIds = KeywordClusterSiteScope::keywordIds($siteId);
$eligibility = app(KeywordClusterEligibility::class);
$phraseResolver = app(CanonicalClusterPhraseResolver::class);

$metaKey = KeywordMetaKey::MainArticleId->value;

$orphanRows = DB::connection('omi_seo_ai')->select(
    'SELECT c.keyword_id, k.phrase, c.cluster_key, c.normalized_text,
            (SELECT GROUP_CONCAT(km.meta_value) FROM keyword_meta km
              WHERE km.keyword_id = c.keyword_id AND km.meta_key = ?) as focus_ids
     FROM seo_keyword_classifications c
     INNER JOIN keywords k ON k.id = c.keyword_id
     WHERE c.keyword_id IN ('.implode(',', array_map('intval', $keywordIds ?: [0])).')
       AND EXISTS (
         SELECT 1 FROM keyword_meta km2
         WHERE km2.keyword_id = c.keyword_id AND km2.meta_key = ? AND km2.meta_value IS NOT NULL AND km2.meta_value <> \'\'
       )
       AND (c.cluster_key IS NULL OR c.cluster_key = \'\')
     ORDER BY k.phrase',
    [$metaKey, $metaKey],
);

echo "=== A. SEO keywords with Focus Article AND cluster_key NULL ===\n";
echo 'count='.count($orphanRows)."\n";

$topicPhrase = 'Túi Vải Không Dệt';
$topicMeta = DB::connection('omi_seo_ai')->table('seo_topic_cluster_meta')
    ->where('site_id', $siteId)
    ->where(function ($q) use ($topicPhrase): void {
        $q->where('canonical_phrase', 'like', '%'.$topicPhrase.'%')
            ->orWhere('normalized_canonical', 'like', '%tui vai khong det%');
    })
    ->get();

echo "\n=== Topic meta matching Túi Vải Không Dệt ===\n";
foreach ($topicMeta as $m) {
    echo "key={$m->cluster_key} canon={$m->canonical_phrase} source=".($m->canonical_source ?? '')."\n";
}

$topicKey = (string) ($topicMeta[0]->cluster_key ?? '');
$topicCanon = (string) ($topicMeta[0]->canonical_phrase ?? $topicPhrase);

echo "\n=== B. Keyword IDs in that Topic ===\n";
$memberIds = [];
if ($topicKey !== '') {
    $memberIds = DB::connection('omi_seo_ai')->table('seo_keyword_classifications')
        ->where('cluster_key', $topicKey)
        ->whereIn('keyword_id', $keywordIds)
        ->pluck('keyword_id')
        ->map(static fn ($id): int => (int) $id)
        ->all();
    echo 'count='.count($memberIds)." key={$topicKey}\n";
    $sample = DB::connection('omi_seo_ai')->table('keywords')->whereIn('id', array_slice($memberIds, 0, 10))->pluck('phrase');
    foreach ($sample as $p) {
        echo "  - {$p}\n";
    }
}

$memberFocusCount = 0;
$memberMainDistinct = [];
if ($memberIds !== [] && Schema::connection('omi_seo_ai')->hasTable('keyword_meta')) {
    $mains = DB::connection('omi_seo_ai')->table('keyword_meta')
        ->whereIn('keyword_id', $memberIds)
        ->where('meta_key', $metaKey)
        ->pluck('meta_value');
    foreach ($mains as $v) {
        $aid = (int) $v;
        if ($aid > 0) {
            $memberMainDistinct[$aid] = true;
            $memberFocusCount++;
        }
    }
}
$linkTargets = 0;
if ($memberIds !== [] && Schema::connection('omi_seo_ai')->hasTable('seo_link_maps')) {
    $linkTargets = (int) DB::connection('omi_seo_ai')->table('seo_link_maps')
        ->whereIn('keyword_id', $memberIds)
        ->whereNotNull('target_article_id')
        ->distinct()
        ->count('target_article_id');
}

echo "\n=== D. Why Topic may show 0 focus articles ===\n";
echo "members_with_main_article_meta_rows={$memberFocusCount}\n";
echo 'distinct_main_article_ids='.count($memberMainDistinct)."\n";
echo "distinct_link_map_target_article_ids={$linkTargets}\n";
echo "(UI article_count = DISTINCT main_article_id ∪ link_map target_article_id)\n";

echo "\n=== C. Orphan Focus Article keywords detail ===\n";
foreach ($orphanRows as $row) {
    $phrase = (string) $row->phrase;
    $core = $phraseResolver->preferredClusterCore($phrase);
    $contains = $topicCanon !== '' ? $phraseResolver->containsCanonicalCore($phrase, $topicCanon) : false;
    $intentOk = $topicCanon !== '' ? $phraseResolver->intentCompatible($phrase, $topicCanon) : false;
    $serviceKw = $phraseResolver->hasServiceIntent($phrase);
    $serviceTopic = $topicCanon !== '' ? $phraseResolver->hasServiceIntent($topicCanon) : false;

    $class = DB::connection('omi_seo_ai')->table('seo_keyword_classifications')->where('keyword_id', $row->keyword_id)->first();
    $seoEligible = $class ? $eligibility->isSeoEligible(
        \Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification::query()->find($row->keyword_id)
    ) : false;

    $reason = [];
    if (! $seoEligible) {
        $reason[] = 'not_seo_eligible';
    }
    if ($serviceKw !== $serviceTopic) {
        $reason[] = 'intent_mismatch_service_vs_product';
    }
    if (! $contains) {
        $reason[] = 'no_contiguous_core_match';
    }
    if ($reason === []) {
        $reason[] = 'would_match_but_unassigned_likely_pruned_singleton_or_never_attached';
    }

    echo sprintf(
        "id=%d | %s | norm=%s | focus=%s | cluster=%s | core=%s | intentOk=%s contains=%s | reason=%s\n",
        (int) $row->keyword_id,
        $phrase,
        (string) ($row->normalized_text ?? ''),
        (string) ($row->focus_ids ?? ''),
        (string) ($row->cluster_key ?? 'NULL'),
        $core,
        $intentOk ? '1' : '0',
        $contains ? '1' : '0',
        implode(',', $reason),
    );
}
