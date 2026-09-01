<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)
    ->bootstrapLegacySharedConnection();

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordClassificationVisibility;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordDictionaryQuery;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordUiInventoryQuery;
use Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncV3Client;
use App\Models\Site;

$db = DB::connection('omi_seo_ai');
$inv = app(KeywordUiInventoryQuery::class);
$dictQ = app(KeywordDictionaryQuery::class);
$dictIds = $inv->keywordIds(2, null);
$mainKey = KeywordMetaKey::MainArticleId->value;

$focusCounts = $db->table('keyword_meta')
    ->select('keyword_id', DB::raw('COUNT(*) as c'))
    ->where('meta_key', $mainKey)
    ->whereIn('keyword_id', $dictIds)
    ->whereNotNull('meta_value')
    ->where('meta_value', '!=', '')
    ->groupBy('keyword_id')
    ->pluck('c', 'keyword_id');

$linkCounts = $db->table('seo_link_maps')
    ->select('keyword_id', DB::raw('COUNT(*) as c'))
    ->whereIn('keyword_id', $dictIds)
    ->whereNotNull('source_article_id')
    ->groupBy('keyword_id')
    ->pluck('c', 'keyword_id');

$withLink = 0;
$withFocus = 0;
$both = 0;
$neither = 0;
$zeroFocus = 0;
$zeroLink = 0;
foreach ($dictIds as $id) {
    $lc = (int) ($linkCounts[$id] ?? 0);
    $fc = (int) ($focusCounts[$id] ?? 0);
    if ($lc >= 1) {
        $withLink++;
    }
    if ($fc >= 1) {
        $withFocus++;
    }
    if ($lc >= 1 && $fc >= 1) {
        $both++;
    }
    if ($lc === 0 && $fc === 0) {
        $neither++;
    }
    if ($fc === 0) {
        $zeroFocus++;
    }
    if ($lc === 0) {
        $zeroLink++;
    }
}

echo "dict=".count($dictIds)." withLink={$withLink} withFocus={$withFocus} both={$both} neither={$neither} zeroFocus={$zeroFocus} zeroLink={$zeroLink}\n";

$summary = KeywordClassificationVisibility::summarizeForKeywordIds($dictIds);
echo 'classification_summary='.json_encode($summary, JSON_UNESCAPED_UNICODE)."\n";

$kws = Keyword::query()->whereIn('id', $dictIds)->get()->keyBy('id');
echo "--- zero-focus samples (20) ---\n";
$n = 0;
$samples = [];
foreach ($dictIds as $id) {
    if ((int) ($focusCounts[$id] ?? 0) !== 0) {
        continue;
    }
    $kw = $kws->get($id);
    if ($kw === null) {
        continue;
    }
    $cls = $db->table('seo_keyword_classifications')->where('keyword_id', $id)->first();
    $row = [
        'keyword_id' => $id,
        'phrase' => $kw->phrase,
        'source' => $kw->source,
        'type' => $kw->type,
        'review_status' => $kw->review_status,
        'phrase_kind' => $cls->phrase_kind ?? null,
        'is_seo_keyword' => $cls->is_seo_keyword ?? null,
        'link_map_count' => (int) ($linkCounts[$id] ?? 0),
        'main_article_count' => 0,
        'provider_meta' => $db->table('keyword_meta')
            ->where('keyword_id', $id)
            ->whereIn('meta_key', ['source', 'provider', 'provider_source', 'locked_source'])
            ->pluck('meta_value', 'meta_key')
            ->all(),
    ];
    $samples[] = $row;
    echo json_encode($row, JSON_UNESCAPED_UNICODE)."\n";
    if (++$n >= 20) {
        break;
    }
}

echo "--- underperforming (13) ---\n";
$errs = $dictQ->applyUnderperformingReview($inv->baseQuery(2, null))->get(['id', 'phrase', 'review_status', 'source', 'type']);
foreach ($errs as $e) {
    $hidden = $db->table('keyword_meta')->where('keyword_id', $e->id)->where('meta_key', 'seo_hidden')->where('meta_value', '1')->exists();
    $fc = (int) ($focusCounts[(int) $e->id] ?? 0);
    $lc = (int) ($linkCounts[(int) $e->id] ?? 0);
    echo json_encode([
        'keyword_id' => (int) $e->id,
        'phrase' => $e->phrase,
        'review_status' => $e->review_status,
        'source' => $e->source,
        'seo_hidden' => $hidden,
        'link_map_count' => $lc,
        'main_article_count' => $fc,
    ], JSON_UNESCAPED_UNICODE)."\n";
}

// Trace 10
echo "--- traces ---\n";
$anchor = Keyword::query()->forSite(2)
    ->whereIn('id', $dictIds)
    ->whereDoesntHave('mainArticles')
    ->limit(3)->get();
$provider = Keyword::query()->forSite(2)
    ->whereIn('id', $dictIds)
    ->whereHas('mainArticles')
    ->limit(3)->get();
$zero = Keyword::query()->forSite(2)
    ->whereDoesntHave('mainArticles')
    ->whereDoesntHave('linkMaps', fn ($q) => $q->whereNotNull('source_article_id'))
    ->limit(2)->get();
$error = $errs->take(2);

$site = Site::find(2);
$client = app(WordPressSiteSyncV3Client::class);

foreach ([
    'valid_anchor' => $anchor,
    'provider_focus' => $provider,
    'zero_count' => $zero,
    'error' => $error,
] as $bucket => $set) {
    foreach ($set as $kw) {
        $kid = (int) $kw->id;
        $maps = $db->table('seo_link_maps')->where('keyword_id', $kid)->limit(3)->get();
        $mains = $db->table('keyword_meta')->where('keyword_id', $kid)->where('meta_key', $mainKey)->get();
        // Find a source article with WP id for payload check
        $srcArticleId = (int) ($maps->first()->source_article_id ?? 0);
        $wpId = $srcArticleId > 0
            ? (int) $db->table('wordpress_article_links')->where('article_id', $srcArticleId)->value('wp_post_id')
            : 0;
        $payloadLinks = null;
        $payloadFocus = null;
        if ($wpId > 0) {
            $discover = $client->discover($site);
            $rec = $client->records($site, [
                'schema' => 3,
                'resource' => 'content',
                'mode' => 'full',
                'limit' => 2,
                'cursor' => ['after_id' => max(0, $wpId - 1)],
                'snapshot_at' => (string) ($discover['discover']['snapshot_at'] ?? ''),
                'snapshot_bounds' => $discover['discover']['snapshot_bounds'] ?? [],
            ]);
            foreach (($rec['records']['items'] ?? []) as $it) {
                if ((int) ($it['wp_id'] ?? 0) !== $wpId) {
                    continue;
                }
                $payloadFocus = $it['seo']['focus_keywords'] ?? $it['seo']['focus_keyword'] ?? null;
                $payloadLinks = array_slice(is_array($it['links'] ?? null) ? $it['links'] : [], 0, 5);
                break;
            }
        }
        echo json_encode([
            'bucket' => $bucket,
            'keyword_id' => $kid,
            'phrase' => $kw->phrase,
            'source' => $kw->source,
            'type' => $kw->type,
            'review_status' => $kw->review_status,
            'in_dictionary' => in_array($kid, $dictIds, true),
            'link_maps' => $maps->map(fn ($m) => [
                'id' => $m->id,
                'source_article_id' => $m->source_article_id,
                'anchor' => mb_substr((string) $m->anchor_text, 0, 60),
            ])->all(),
            'main_article_meta' => $mains->map(fn ($m) => $m->meta_value)->all(),
            'wp_source_post' => $wpId,
            'wp_payload_focus' => $payloadFocus,
            'wp_payload_link_sample' => $payloadLinks,
        ], JSON_UNESCAPED_UNICODE)."\n";
    }
}

$col = $db->selectOne("SHOW COLUMNS FROM seo_link_maps LIKE 'keyword_id'");
echo 'seo_link_maps.keyword_id Null='.$col->Null.' Type='.$col->Type."\n";

// Importer: does reconcileAnalysisLinks skip when no keyword?
$importer = file_get_contents(base_path('../omnichannel-addons/site-sync/src/Services/V3/SiteSyncV3BulkImporter.php'));
echo 'importer_has_keyword_gate='.(str_contains($importer, 'SiteSyncKeywordCandidateEvaluator') ? 'yes' : 'no')."\n";
echo 'importer_skip_empty_anchor_patterns='.substr_count($importer, 'looksLikeUrlOrDomain')."\n";

file_put_contents(__DIR__.'/_v3_site2_keyword_audit.json', json_encode([
    'dict' => count($dictIds),
    'withLink' => $withLink,
    'withFocus' => $withFocus,
    'both' => $both,
    'neither' => $neither,
    'zeroFocus' => $zeroFocus,
    'zeroLink' => $zeroLink,
    'classification' => $summary,
    'zero_focus_samples' => $samples,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
