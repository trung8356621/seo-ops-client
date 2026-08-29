<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Canonical\CanonicalClusterPhraseResolver;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterEligibility;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterSiteScope;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\UpdateClusterCanonicalService;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$siteId = 5;
$canonical = 'Túi Vải Không Dệt';

if (class_exists(\Omnichannel\Addons\Seo\Services\SeoDatabaseConnectionService::class)) {
    app(\Omnichannel\Addons\Seo\Services\SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();
} elseif (method_exists(app(), 'make') && class_exists(\App\Services\SeoDatabaseConnectionService::class)) {
    // no-op fallback
}

$phraseResolver = app(CanonicalClusterPhraseResolver::class);
$eligibility = app(KeywordClusterEligibility::class);
$svc = app(UpdateClusterCanonicalService::class);
$matchMethod = new ReflectionMethod(UpdateClusterCanonicalService::class, 'matchesCanonical');
$matchMethod->setAccessible(true);

$canonTokens = $phraseResolver->significantTokens($canonical);
$canonNorm = $phraseResolver->normalizedKey($canonical);
$canonCore = $phraseResolver->preferredClusterCore($canonical) ?: $canonical;

$keywordIds = KeywordClusterSiteScope::keywordIds($siteId);
$metaKey = KeywordMetaKey::MainArticleId->value;

$rows = DB::connection('omi_seo_ai')->select(
    'SELECT c.keyword_id, k.phrase, c.cluster_key, c.normalized_text, c.seo_intent, c.is_seo_keyword,
            (SELECT GROUP_CONCAT(km.meta_value) FROM keyword_meta km
              WHERE km.keyword_id = c.keyword_id AND km.meta_key = ?) as focus_ids
     FROM seo_keyword_classifications c
     INNER JOIN keywords k ON k.id = c.keyword_id
     WHERE c.keyword_id IN ('.implode(',', array_map('intval', $keywordIds ?: [0])).')
       AND (c.cluster_key IS NULL OR c.cluster_key = \'\')
     ORDER BY k.phrase',
    [$metaKey],
);

echo "canonical={$canonical}\n";
echo 'canon_tokens='.json_encode($canonTokens, JSON_UNESCAPED_UNICODE)."\n";
echo "canon_core={$canonCore} canon_norm={$canonNorm}\n";
echo 'service_intent_canon='.($phraseResolver->hasServiceIntent($canonical) ? '1' : '0')."\n\n";

$missing = [];
foreach ($rows as $row) {
    $class = SeoKeywordClassification::query()->find((int) $row->keyword_id);
    if (! $class || ! $eligibility->isSeoEligible($class)) {
        continue;
    }
    $phrase = (string) $row->phrase;
    $kwTokens = $phraseResolver->significantTokens($phrase);
    $contiguous = $phraseResolver->containsContiguousTokenPhrase($kwTokens, $canonTokens);
    $intentOk = $phraseResolver->intentCompatible($phrase, $canonical);
    $containsWithIntent = $phraseResolver->containsCanonicalCore($phrase, $canonical);
    $prefEqual = $phraseResolver->normalizedKey($phraseResolver->preferredClusterCore($phrase) ?: $phrase)
        === $phraseResolver->normalizedKey($canonical);
    $boilerplate = $phraseResolver->isBoilerplateSuperset($phrase, $canonical);
    $matches = $matchMethod->invoke($svc, $phrase, $canonical);
    $focusCount = $row->focus_ids === null || $row->focus_ids === ''
        ? 0
        : count(array_filter(explode(',', (string) $row->focus_ids)));

    // Strong match candidates: contiguous core OR normalized contains folded core
    $foldedPhrase = $phraseResolver->normalizedKey($phrase);
    $strong = $contiguous || str_contains($foldedPhrase, $canonNorm);

    if (! $strong && $focusCount < 1) {
        continue;
    }
    if (! $strong) {
        continue;
    }

    $reason = [];
    if ($matches) {
        $reason[] = 'SHOULD_MATCH_BUT_UNASSIGNED';
    } else {
        if (! $prefEqual) {
            $reason[] = 'preferred_core_not_equal';
        }
        if (! $boilerplate) {
            $reason[] = 'not_boilerplate_superset';
        }
        if (! $containsWithIntent) {
            $reason[] = 'containsCanonicalCore_false';
            if (! $intentOk) {
                $reason[] = 'INTENT_GATE_service_vs_product';
            }
            if ($intentOk && ! $contiguous) {
                $reason[] = 'no_contiguous_tokens';
            }
        }
    }

    $missing[] = [
        'id' => (int) $row->keyword_id,
        'phrase' => $phrase,
        'norm' => (string) ($row->normalized_text ?? ''),
        'seo' => 1,
        'focus_count' => $focusCount,
        'focus_ids' => (string) ($row->focus_ids ?? ''),
        'seo_intent' => (string) ($row->seo_intent ?? ''),
        'kw_service' => $phraseResolver->hasServiceIntent($phrase) ? 1 : 0,
        'topic_service' => $phraseResolver->hasServiceIntent($canonical) ? 1 : 0,
        'preferred_core' => $phraseResolver->preferredClusterCore($phrase),
        'pref_equal' => $prefEqual ? 1 : 0,
        'boilerplate' => $boilerplate ? 1 : 0,
        'contiguous' => $contiguous ? 1 : 0,
        'intent_ok' => $intentOk ? 1 : 0,
        'containsCanonicalCore' => $containsWithIntent ? 1 : 0,
        'matchesCanonical' => $matches ? 1 : 0,
        'reason' => implode(',', $reason),
    ];
}

echo 'MISSING_STRONG_CORE_UNASSIGNED count='.count($missing)."\n\n";
foreach ($missing as $m) {
    echo sprintf(
        "id=%d | %s | focus=%d ids=%s | kwService=%d topicService=%d | pref=%s | prefEq=%d boil=%d contig=%d intentOk=%d contains=%d matches=%d | %s\n",
        $m['id'],
        $m['phrase'],
        $m['focus_count'],
        $m['focus_ids'],
        $m['kw_service'],
        $m['topic_service'],
        $m['preferred_core'],
        $m['pref_equal'],
        $m['boilerplate'],
        $m['contiguous'],
        $m['intent_ok'],
        $m['containsCanonicalCore'],
        $m['matchesCanonical'],
        $m['reason'],
    );
}

$orphanFocus = 0;
foreach ($rows as $row) {
    $class = SeoKeywordClassification::query()->find((int) $row->keyword_id);
    if (! $class || ! $eligibility->isSeoEligible($class)) {
        continue;
    }
    $focusCount = $row->focus_ids === null || $row->focus_ids === ''
        ? 0
        : count(array_filter(explode(',', (string) $row->focus_ids)));
    if ($focusCount > 0) {
        $orphanFocus++;
    }
}
echo "\nTOTAL_ORPHAN_FOCUS_SEO={$orphanFocus}\n";
