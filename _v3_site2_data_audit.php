<?php

declare(strict_types=1);

/**
 * Site 2 data integrity audit — read-only. STOP before patch.
 * php _v3_site2_data_audit.php
 */

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)
    ->bootstrapLegacySharedConnection();

use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\Health\ArticleRequiredDataHealthAuditor;
use Omnichannel\Addons\Content\Support\ArticleRequiredDataRegistry;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordDictionaryQuery;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordUiInventoryQuery;
use Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncV3Client;
use Omnichannel\Addons\SiteSync\Services\Preflight\SiteSyncPreflightService;

const SITE_ID = 2;
$out = ['site_id' => SITE_ID, 'generated_at' => now()->toIso8601String()];

$db = DB::connection('omi_seo_ai');
$site = Site::query()->findOrFail(SITE_ID);

// ---------------------------------------------------------------------------
// 1) SEO DATA WARNING — ArticleRequiredDataHealthAuditor
// ---------------------------------------------------------------------------
$auditor = app(ArticleRequiredDataHealthAuditor::class);
$health = $auditor->audit(SITE_ID);
$preflight = app(SiteSyncPreflightService::class)->evaluateLocalOnly($site);

$sumPresent = 0;
$sumTotal = 0;
$sumMissing = 0;
foreach ($health['fields'] as $f) {
    $sumPresent += (int) $f['present'];
    $sumTotal += (int) $f['total'];
    $sumMissing += (int) $f['missing'];
}

$out['seo_warning'] = [
    'ui_formula' => 'SUM(field.present) / SUM(field.total) complete · SUM(field.missing) missing',
    'ui_expected' => sprintf('%s / %s complete · %s missing', number_format($sumPresent), number_format($sumTotal), number_format($sumMissing)),
    'article_denominator_per_field' => $health['total'],
    'field_count' => count($health['fields']),
    'fields' => $health['fields'],
    'worst_severity' => $health['worst_severity'],
    'max_missing' => $health['max_missing'],
    'by_content_type' => $health['by_content_type'],
    'note' => 'Denominator is NOT unique articles. Each of '.count($health['fields']).' required fields contributes articles.total (= '.$health['total'].'). '.$health['total'].'×'.count($health['fields']).'='.$sumTotal,
];

// Exact missing article IDs per field
$missingByField = [];
foreach (ArticleRequiredDataRegistry::all() as $def) {
    $key = $def['key'];
    $ids = [];
    if ($def['storage'] === 'column') {
        $col = (string) $def['column'];
        $ids = SeoArticle::query()
            ->where('site_id', SITE_ID)
            ->where(function ($q) use ($col): void {
                $q->whereNull($col)->orWhereRaw('TRIM('.$col.') = ?', ['']);
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    } elseif ($def['storage'] === 'meta') {
        $metaKey = (string) $def['meta_key'];
        $allowed = $key === 'content_type'
            ? ['post', 'page', 'product']
            : [];
        $q = SeoArticle::query()
            ->where('articles.site_id', SITE_ID)
            ->leftJoin('article_meta as arm_req', function ($join) use ($metaKey): void {
                $join->on('arm_req.article_id', '=', 'articles.id')
                    ->where('arm_req.meta_key', '=', $metaKey);
            })
            ->where(function ($q) use ($allowed): void {
                $q->whereNull('arm_req.meta_value')
                    ->orWhereRaw("TRIM(arm_req.meta_value) = ''");
                if ($allowed !== []) {
                    $ph = implode(',', array_fill(0, count($allowed), '?'));
                    $q->orWhereRaw('LOWER(TRIM(arm_req.meta_value)) NOT IN ('.$ph.')', $allowed);
                }
            })
            ->orderBy('articles.id')
            ->select('articles.id');
        $ids = $q->pluck('id')->map(fn ($id) => (int) $id)->all();
    } elseif ($def['storage'] === 'relation') {
        $ids = SeoArticle::query()
            ->where('articles.site_id', SITE_ID)
            ->leftJoin('wordpress_article_links as wal_req', 'wal_req.article_id', '=', 'articles.id')
            ->where(function ($q): void {
                $q->whereNull('wal_req.wp_post_id')->orWhere('wal_req.wp_post_id', '<=', 0);
            })
            ->orderBy('articles.id')
            ->pluck('articles.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
    $rows = [];
    if ($ids !== []) {
        $articles = SeoArticle::query()->whereIn('id', $ids)->get(['id', 'title', 'slug', 'status', 'body']);
        $links = $db->table('wordpress_article_links')->whereIn('article_id', $ids)->get()->keyBy('article_id');
        $metas = $db->table('article_meta')
            ->whereIn('article_id', $ids)
            ->whereIn('meta_key', ['content_type', 'wp_post_type', 'wp_permalink'])
            ->get()
            ->groupBy('article_id');
        foreach ($articles as $a) {
            $aid = (int) $a->id;
            $m = $metas->get($aid) ?? collect();
            $metaMap = [];
            foreach ($m as $mr) {
                $metaMap[$mr->meta_key] = $mr->meta_value;
            }
            $wal = $links->get($aid);
            $rows[] = [
                'article_id' => $aid,
                'wp_post_id' => $wal ? (int) $wal->wp_post_id : null,
                'title' => mb_substr((string) $a->title, 0, 80),
                'slug' => $a->slug,
                'status' => $a->status,
                'body_null' => $a->body === null || trim((string) $a->body) === '',
                'content_type' => $metaMap['content_type'] ?? null,
                'wp_post_type' => $metaMap['wp_post_type'] ?? null,
                'wp_permalink' => $metaMap['wp_permalink'] ?? null,
            ];
        }
    }
    $missingByField[$key] = [
        'label' => $def['label'],
        'count' => count($ids),
        'article_ids' => $ids,
        'rows' => $rows,
    ];
}
$out['seo_warning']['missing_by_field'] = $missingByField;

// Probe WP V3 for missing IDs that have wp_post_id
$client = app(WordPressSiteSyncV3Client::class);
$discover = $client->discover($site);
$bridge = (string) ($discover['discover']['profile']['bridge_version'] ?? $discover['discover']['bridge_version'] ?? '');
$out['bridge_version'] = $bridge;
$out['discover_ok'] = (bool) ($discover['success'] ?? false);

$wpProbe = [];
foreach ($missingByField as $fieldKey => $info) {
    foreach ($info['rows'] as $row) {
        $wpId = (int) ($row['wp_post_id'] ?? 0);
        if ($wpId <= 0) {
            $wpProbe[] = [
                'field' => $fieldKey,
                'article_id' => $row['article_id'],
                'wp_post_id' => null,
                'classification' => 'local_only_or_null_wp_link — not importer loss from WP payload',
            ];
            continue;
        }
        // Fetch single via records full with after_id = wpId-1 limit 1, or observe
        $obs = \Illuminate\Support\Facades\Http::timeout(30)
            ->acceptJson()
            ->withToken(trim((string) $site->getMeta('seo_read_token')))
            ->get(rtrim((preg_match('#^https?://#i', (string) $site->domain) ? (string) $site->domain : 'https://'.$site->domain), '/').'/wp-json/omi-seo-ai/v1/posts/'.$wpId.'/observe');
        $obsJson = $obs->json();
        $found = (bool) ($obsJson['found'] ?? false);
        $post = is_array($obsJson['post'] ?? null) ? $obsJson['post'] : [];

        // Also try V3 records around that ID
        $rec = $client->records($site, [
            'schema' => 3,
            'resource' => 'content',
            'mode' => 'full',
            'limit' => 3,
            'cursor' => ['after_id' => max(0, $wpId - 1)],
            'snapshot_at' => (string) ($discover['discover']['snapshot_at'] ?? ''),
            'snapshot_bounds' => $discover['discover']['snapshot_bounds'] ?? [],
        ]);
        $items = is_array($rec['records']['items'] ?? null) ? $rec['records']['items'] : [];
        $match = null;
        foreach ($items as $it) {
            if ((int) ($it['wp_id'] ?? 0) === $wpId) {
                $match = $it;
                break;
            }
        }

        $payloadHas = [
            'title' => isset($match['title']) ? trim((string) $match['title']) : null,
            'slug' => isset($match['slug']) ? trim((string) $match['slug']) : null,
            'permalink' => isset($match['permalink']) ? trim((string) $match['permalink']) : (isset($match['url']) ? trim((string) $match['url']) : null),
            'content_type' => $match['content_type'] ?? null,
            'wp_post_type' => $match['wp_post_type'] ?? null,
            'status' => $match['status'] ?? ($post['status'] ?? null),
        ];

        $localMissing = match ($fieldKey) {
            'title' => $row['title'] === null || trim((string) $row['title']) === '',
            'slug' => $row['slug'] === null || trim((string) $row['slug']) === '',
            'permalink' => $row['wp_permalink'] === null || trim((string) $row['wp_permalink']) === '',
            'content_type' => $row['content_type'] === null || trim((string) $row['content_type']) === '',
            'wp_post_type' => $row['wp_post_type'] === null || trim((string) $row['wp_post_type']) === '',
            'status' => $row['status'] === null || trim((string) $row['status']) === '',
            'source_id' => true,
            default => true,
        };

        $wpHasField = match ($fieldKey) {
            'title' => ($payloadHas['title'] ?? '') !== '',
            'slug' => ($payloadHas['slug'] ?? '') !== '',
            'permalink' => ($payloadHas['permalink'] ?? '') !== '',
            'content_type' => ($payloadHas['content_type'] ?? '') !== '',
            'wp_post_type' => ($payloadHas['wp_post_type'] ?? '') !== '',
            'status' => ($payloadHas['status'] ?? '') !== '',
            'source_id' => $found,
            default => null,
        };

        $wpProbe[] = [
            'field' => $fieldKey,
            'article_id' => $row['article_id'],
            'wp_post_id' => $wpId,
            'observe_found' => $found,
            'observe_status' => $post['status'] ?? null,
            'v3_item_found' => $match !== null,
            'wp_payload_field' => $payloadHas,
            'wp_has_field' => $wpHasField,
            'local_missing' => $localMissing,
            'classification' => match (true) {
                $match === null && ! $found => 'wp_post_gone_or_unreadable',
                $wpHasField === true && $localMissing => 'IMPORTER_LOSS — WP has value, local missing',
                $wpHasField === false && $localMissing => 'LEGITIMATE_SOURCE_ABSENCE — WP also empty/missing',
                default => 'needs_manual_review',
            },
        ];
    }
}
$out['seo_warning']['wp_probe'] = $wpProbe;

// ---------------------------------------------------------------------------
// 2) KEYWORD COUNTERS
// ---------------------------------------------------------------------------
$inv = app(KeywordUiInventoryQuery::class);
$dictQ = app(KeywordDictionaryQuery::class);

$dictionaryBase = $inv->baseQuery(SITE_ID, null);
$dictionaryTotal = (clone $dictionaryBase)->count();
$focusTotal = (clone $dictionaryBase)->whereHas('mainArticles')->count();
$activeTotal = $dictQ->applyActiveSeoKeywords(clone $dictionaryBase)->count();
$errorTotal = $dictQ->applyUnderperformingReview(clone $dictionaryBase)->count();

// Focus page stats use focus-filtered query as "total"
$focusPageBase = $dictQ->filtered(SITE_ID, null, ['focus' => true]);
$focusPageTotal = (clone $focusPageBase)->count();
$focusPageActive = $dictQ->applyActiveSeoKeywords(clone $focusPageBase)->count();
$focusPageErrors = $dictQ->applyUnderperformingReview(clone $focusPageBase)->count();

$out['keyword_counters'] = [
    'dictionary_page' => [
        'total' => $dictionaryTotal,
        'active' => $activeTotal,
        'errors_underperforming' => $errorTotal,
        'active_plus_errors' => $activeTotal + $errorTotal,
        'overlap_active_and_errors' => $dictQ->applyUnderperformingReview(
            $dictQ->applyActiveSeoKeywords(clone $dictionaryBase)
        )->count(),
        'query_paths' => [
            'total' => 'KeywordUiInventoryQuery::baseQuery(site=2) — forSite + has linkMaps.source_article_id + exclude TYPE_SUGGEST + min word count + exclude link-like',
            'active' => 'applyActiveSeoKeywords: (mainArticles OR linkMaps) AND review_status=active AND NOT seo_hidden',
            'errors' => 'applyUnderperformingReview: review_status IN (danger,warning) OR seo_hidden=1',
        ],
    ],
    'focus_page' => [
        'total_badge' => $focusPageTotal,
        'active' => $focusPageActive,
        'errors' => $focusPageErrors,
        'query_path' => 'ListFocusKeywords mode=focus → DictionaryQuery filter focus=true → whereHas(mainArticles) on inventory base',
        'same_as_dictionary_total' => $focusPageTotal === $dictionaryTotal,
    ],
    'raw_site_keywords' => [
        'all_for_site' => Keyword::query()->forSite(SITE_ID)->count(),
        'with_main_articles' => Keyword::query()->forSite(SITE_ID)->whereHas('mainArticles')->count(),
        'with_link_maps' => Keyword::query()->forSite(SITE_ID)->whereHas('linkMaps', fn ($q) => $q->whereNotNull('source_article_id'))->count(),
        'with_neither' => Keyword::query()->forSite(SITE_ID)
            ->whereDoesntHave('mainArticles')
            ->whereDoesntHave('linkMaps', fn ($q) => $q->whereNotNull('source_article_id'))
            ->count(),
    ],
];

// ---------------------------------------------------------------------------
// 3) ZERO-USAGE KEYWORDS (Dictionary visible)
// ---------------------------------------------------------------------------
$dictIds = $inv->keywordIds(SITE_ID, null);
$dictIdList = $dictIds;

$usageRows = [];
if ($dictIdList !== []) {
    $linkCounts = $db->table('seo_link_maps')
        ->select('keyword_id', DB::raw('COUNT(*) as c'))
        ->whereIn('keyword_id', $dictIdList)
        ->whereNotNull('source_article_id')
        ->groupBy('keyword_id')
        ->pluck('c', 'keyword_id');

    // mainArticles = article_keyword pivot typically
    $focusCounts = [];
    if (Schema::connection('omi_seo_ai')->hasTable('article_keyword')) {
        $focusCounts = $db->table('article_keyword')
            ->select('keyword_id', DB::raw('COUNT(*) as c'))
            ->whereIn('keyword_id', $dictIdList)
            ->groupBy('keyword_id')
            ->pluck('c', 'keyword_id')
            ->all();
    }

    $providerCounts = [];
    // provider relation — check keyword_meta source / seo_keywords table
    if (Schema::connection('omi_seo_ai')->hasTable('keyword_meta')) {
        // leave empty; fill from keywords.source later
    }

    $zeroUsage = 0;
    $withLink = 0;
    $withFocus = 0;
    $withBoth = 0;
    $withNeither = 0;
    $zeroSamples = [];

    $keywords = Keyword::query()->whereIn('id', $dictIdList)->get();
    foreach ($keywords as $kw) {
        $kid = (int) $kw->id;
        $lc = (int) ($linkCounts[$kid] ?? 0);
        $fc = (int) ($focusCounts[$kid] ?? 0);
        $hasLink = $lc >= 1;
        $hasFocus = $fc >= 1;
        if ($hasLink) {
            $withLink++;
        }
        if ($hasFocus) {
            $withFocus++;
        }
        if ($hasLink && $hasFocus) {
            $withBoth++;
        }
        if (! $hasLink && ! $hasFocus) {
            $withNeither++;
        }
        // "zero-usage" for audit: no focus article AND (link count treated as usage)
        // Dictionary requires linkMaps so hasLink should always be true — verify
        if ($lc === 0) {
            $zeroUsage++;
            if (count($zeroSamples) < 25) {
                $zeroSamples[] = [
                    'keyword_id' => $kid,
                    'phrase' => $kw->phrase,
                    'source' => $kw->source ?? null,
                    'type' => $kw->type ?? null,
                    'review_status' => $kw->review_status ?? null,
                    'is_seo_keyword' => null,
                    'article_relation_count' => $fc,
                    'link_map_count' => $lc,
                    'provider_relation_count' => null,
                ];
            }
        }
    }

    // Also: keywords with link maps but ZERO focus — user may call these "zero usage" if UI usage = articles
    $zeroFocusSamples = [];
    foreach ($keywords as $kw) {
        $kid = (int) $kw->id;
        $lc = (int) ($linkCounts[$kid] ?? 0);
        $fc = (int) ($focusCounts[$kid] ?? 0);
        if ($fc === 0 && count($zeroFocusSamples) < 25) {
            $cls = null;
            if (Schema::connection('omi_seo_ai')->hasTable('seo_keyword_classifications')) {
                $cls = $db->table('seo_keyword_classifications')->where('keyword_id', $kid)->first();
            }
            $isSeo = null;
            if ($cls) {
                $isSeo = $cls->is_seo_keyword ?? $cls->seo_eligible ?? null;
            }
            $zeroFocusSamples[] = [
                'keyword_id' => $kid,
                'phrase' => $kw->phrase,
                'source' => $kw->source ?? null,
                'type' => $kw->type ?? null,
                'review_status' => $kw->review_status ?? null,
                'classification' => $cls ? (array) $cls : null,
                'is_seo_keyword' => $isSeo,
                'article_relation_count' => $fc,
                'link_map_count' => $lc,
                'provider_relation_count' => null,
            ];
        }
    }

    $out['zero_usage'] = [
        'dictionary_visible_total' => count($dictIdList),
        'with_link_map_ge1' => $withLink,
        'with_focus_article_ge1' => $withFocus,
        'with_both' => $withBoth,
        'with_neither' => $withNeither,
        'link_map_count_eq_0_in_dictionary' => $zeroUsage,
        'focus_article_count_eq_0_in_dictionary' => count(array_filter(
            $dictIdList,
            static fn (int $id): bool => (int) ($focusCounts[$id] ?? 0) === 0
        )),
        'samples_link_map_zero' => $zeroSamples,
        'samples_focus_zero' => $zeroFocusSamples,
        'note' => 'Dictionary inventory REQUIRES whereHas(linkMaps). True link_map=0 rows should not appear unless stale/orphan maps.',
    ];
}

// ---------------------------------------------------------------------------
// 4) TRACE 10 KEYWORDS
// ---------------------------------------------------------------------------
$pick = [
    'valid_anchor' => [],
    'provider_focus' => [],
    'zero_count' => [],
    'error_underperforming' => [],
];

// valid anchors: link_map >=1, not focus-only
$anchorKws = Keyword::query()->forSite(SITE_ID)
    ->whereHas('linkMaps', fn ($q) => $q->whereNotNull('source_article_id'))
    ->whereDoesntHave('mainArticles')
    ->limit(3)->get();
if ($anchorKws->count() < 3) {
    $anchorKws = Keyword::query()->forSite(SITE_ID)
        ->whereHas('linkMaps', fn ($q) => $q->whereNotNull('source_article_id'))
        ->limit(3)->get();
}
$pick['valid_anchor'] = $anchorKws->pluck('id')->map(fn ($i) => (int) $i)->all();

$providerFocus = Keyword::query()->forSite(SITE_ID)
    ->whereHas('mainArticles')
    ->where(function ($q): void {
        $q->where('source', 'like', '%rank%')
            ->orWhere('source', 'like', '%provider%')
            ->orWhere('source', 'like', '%wordpress%')
            ->orWhere('source', 'like', '%site_sync%');
    })
    ->limit(3)->get();
if ($providerFocus->isEmpty()) {
    $providerFocus = Keyword::query()->forSite(SITE_ID)->whereHas('mainArticles')->limit(3)->get();
}
$pick['provider_focus'] = $providerFocus->pluck('id')->map(fn ($i) => (int) $i)->all();

$zeroCount = Keyword::query()->forSite(SITE_ID)
    ->whereDoesntHave('mainArticles')
    ->whereDoesntHave('linkMaps', fn ($q) => $q->whereNotNull('source_article_id'))
    ->limit(2)->get();
$pick['zero_count'] = $zeroCount->pluck('id')->map(fn ($i) => (int) $i)->all();

$errorKws = $dictQ->applyUnderperformingReview(clone $dictionaryBase)->limit(2)->get();
$pick['error_underperforming'] = $errorKws->pluck('id')->map(fn ($i) => (int) $i)->all();

$allTraceIds = array_values(array_unique(array_merge(
    $pick['valid_anchor'],
    $pick['provider_focus'],
    $pick['zero_count'],
    $pick['error_underperforming'],
)));

$traces = [];
foreach ($allTraceIds as $kid) {
    $kw = Keyword::query()->find($kid);
    if ($kw === null) {
        continue;
    }
    $bucket = match (true) {
        in_array($kid, $pick['valid_anchor'], true) => 'valid_anchor',
        in_array($kid, $pick['provider_focus'], true) => 'provider_focus',
        in_array($kid, $pick['zero_count'], true) => 'zero_count',
        in_array($kid, $pick['error_underperforming'], true) => 'error_underperforming',
        default => 'other',
    };
    $maps = $db->table('seo_link_maps')->where('keyword_id', $kid)->limit(5)->get();
    $arts = Schema::connection('omi_seo_ai')->hasTable('article_keyword')
        ? $db->table('article_keyword')->where('keyword_id', $kid)->limit(5)->get()
        : collect();
    $inDict = in_array($kid, $dictIdList, true);
    $traces[] = [
        'bucket' => $bucket,
        'keyword_id' => $kid,
        'phrase' => $kw->phrase,
        'source' => $kw->source ?? null,
        'type' => $kw->type ?? null,
        'review_status' => $kw->review_status ?? null,
        'in_dictionary_ui' => $inDict,
        'link_maps' => $maps->map(fn ($r) => [
            'id' => $r->id,
            'source_article_id' => $r->source_article_id,
            'anchor_text' => mb_substr((string) $r->anchor_text, 0, 80),
            'link_type' => $r->link_type ?? null,
        ])->all(),
        'article_keyword' => $arts->map(fn ($r) => (array) $r)->all(),
        'ui_counts' => [
            'link_map_count' => $db->table('seo_link_maps')->where('keyword_id', $kid)->count(),
            'focus_article_count' => Schema::connection('omi_seo_ai')->hasTable('article_keyword')
                ? $db->table('article_keyword')->where('keyword_id', $kid)->count()
                : null,
        ],
    ];
}
$out['keyword_traces'] = ['picks' => $pick, 'traces' => $traces];

// ---------------------------------------------------------------------------
// 6) seo_link_maps.keyword_id NOT NULL
// ---------------------------------------------------------------------------
$col = $db->selectOne("SHOW COLUMNS FROM seo_link_maps LIKE 'keyword_id'");
$out['seo_link_maps_schema'] = [
    'column' => (array) $col,
    'keyword_id_nullable' => strtoupper((string) ($col->Null ?? '')) === 'YES',
    'migration_says' => 'foreignId()->constrained() => NOT NULL',
    'null_keyword_id_rows' => (int) $db->table('seo_link_maps')->whereNull('keyword_id')->count(),
];

// Evidence: would eligible-anchor-less links disappear?
// Check importer path comments / count of articles with links in WP but local map count
$out['link_storage_evidence'] = [
    'total_link_maps_site2' => (int) $db->table('seo_link_maps as m')
        ->join('articles as a', 'a.id', '=', 'm.source_article_id')
        ->where('a.site_id', SITE_ID)
        ->whereNull('a.deleted_at')
        ->count(),
    'keywords_created_from_url_shaped' => (int) $db->table('keywords')
        ->where(function ($q): void {
            $q->where('phrase', 'like', '%://%')
                ->orWhere('phrase', 'like', 'www.%');
        })
        ->whereExists(function ($q): void {
            $q->select(DB::raw(1))
                ->from('seo_link_maps')
                ->join('articles', 'articles.id', '=', 'seo_link_maps.source_article_id')
                ->whereColumn('seo_link_maps.keyword_id', 'keywords.id')
                ->where('articles.site_id', SITE_ID);
        })
        ->count(),
];

$path = __DIR__.'/_v3_site2_data_audit_report.json';
file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "WROTE {$path}\n";
echo "SEO UI: {$out['seo_warning']['ui_expected']}\n";
echo "Dict total={$dictionaryTotal} focus_page={$focusPageTotal} active={$activeTotal} errors={$errorTotal}\n";
echo "bridge={$bridge}\n";
