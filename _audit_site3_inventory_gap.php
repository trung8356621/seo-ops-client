<?php

declare(strict_types=1);

/**
 * Site 3 (congtybalo.com) WP vs Laravel Posts inventory gap — READ ONLY.
 *
 * php _audit_site3_inventory_gap.php
 */

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)
    ->bootstrapLegacySharedConnection();

use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\Content\Enums\ArticleReviewStatus;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\ListArticles;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\Seo\Services\FocusKeywordCoverageQuery;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncV3Schema;
use Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncV3Client;

const SITE_ID = 3;

$db = DB::connection('omi_seo_ai');
$site = Site::query()->findOrFail(SITE_ID);
$client = app(WordPressSiteSyncV3Client::class);
$focusQuery = new FocusKeywordCoverageQuery();

$report = [
    'site_id' => SITE_ID,
    'domain' => (string) $site->domain,
    'generated_at' => now()->toIso8601String(),
    'read_only' => true,
];

echo "Site {$site->id} {$site->domain}\n";

$extractFocus = static function (array $item): array {
    $seo = is_array($item['seo'] ?? null) ? $item['seo'] : [];
    $phrases = [];
    $scalar = trim((string) ($item['focus_keyword'] ?? $seo['focus_keyword'] ?? ''));
    if ($scalar !== '') {
        $phrases[] = $scalar;
    }
    foreach (is_array($seo['focus_keywords'] ?? null) ? $seo['focus_keywords'] : [] as $row) {
        if (is_string($row) && trim($row) !== '') {
            $phrases[] = trim($row);
            continue;
        }
        if (is_array($row)) {
            $phrase = trim((string) ($row['phrase'] ?? $row['keyword'] ?? $row['focus_keyword'] ?? ''));
            if ($phrase !== '') {
                $phrases[] = $phrase;
            }
        }
    }

    return array_values(array_unique($phrases));
};

// ---------------------------------------------------------------------------
// Discover + fetch all V3 content
// ---------------------------------------------------------------------------
$discoverRes = $client->discover($site);
if (! ($discoverRes['success'] ?? false)) {
    fwrite(STDERR, 'discover failed: '.((string) ($discoverRes['message'] ?? '')).PHP_EOL);
    exit(1);
}
$discover = is_array($discoverRes['discover'] ?? null) ? $discoverRes['discover'] : [];
$snapshotAt = (string) ($discover['snapshot_at'] ?? $discover['generated_at'] ?? '');
$bounds = is_array($discover['snapshot_bounds'] ?? null) ? $discover['snapshot_bounds'] : [];
$contentMaxId = (int) ($bounds['content_max_id'] ?? 0);
$termMaxId = (int) ($bounds['term_max_id'] ?? 0);
$report['discover'] = [
    'snapshot_at' => $snapshotAt,
    'snapshot_bounds' => $bounds,
    'by_content_type' => $discover['by_content_type'] ?? null,
    'bridge' => $discover['profile']['bridge_version'] ?? null,
];
echo "Discover ok content_max_id={$contentMaxId}\n";

$allV3 = [];
$cursor = null;
$pages = 0;
$fetchErrors = [];
while ($pages < 500) {
    $fetched = $client->records($site, [
        'schema' => SiteSyncV3Schema::VERSION,
        'resource' => SiteSyncV3Schema::RESOURCE_CONTENT,
        'mode' => 'full',
        'limit' => SiteSyncV3Schema::RECORDS_PER_JOB,
        'cursor' => $cursor,
        'snapshot_at' => $snapshotAt,
        'snapshot_bounds' => [
            'content_max_id' => $contentMaxId,
            'term_max_id' => $termMaxId,
        ],
    ]);
    if (! ($fetched['success'] ?? false)) {
        $fetchErrors[] = [
            'page' => $pages,
            'message' => (string) ($fetched['message'] ?? 'fail'),
        ];
        echo 'V3 page failed: '.((string) ($fetched['message'] ?? '')).PHP_EOL;
        break;
    }
    $payload = is_array($fetched['records'] ?? null) ? $fetched['records'] : [];
    $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
    foreach ($items as $item) {
        if (! is_array($item)) {
            continue;
        }
        $wpId = (int) ($item['wp_id'] ?? 0);
        if ($wpId > 0) {
            $allV3[$wpId] = $item;
        }
    }
    $pages++;
    if ($pages % 10 === 0) {
        echo "  V3 pages={$pages} records=".count($allV3).PHP_EOL;
    }
    $hasMore = (bool) ($payload['has_more'] ?? false);
    $next = $payload['cursor'] ?? $payload['next_cursor'] ?? null;
    $cursor = is_array($next) ? $next : null;
    if (! $hasMore || $cursor === null || $items === []) {
        break;
    }
}
$report['v3_fetch'] = [
    'pages' => $pages,
    'total_records' => count($allV3),
    'errors' => $fetchErrors,
];
echo "V3 pages={$pages} total=".count($allV3).PHP_EOL;

// ---------------------------------------------------------------------------
// 1) WP_POST_IDS
// ---------------------------------------------------------------------------
$v3Posts = [];
$byStatusPost = [];
$byWptPost = [];
$byStatusAll = [];
$byWptAll = [];
$byCtAll = [];
$termCount = 0;

foreach ($allV3 as $wpId => $item) {
    $isTerm = ! empty($item['wp_is_term']);
    $contentType = strtolower(trim((string) ($item['content_type'] ?? '')));
    $wpPostType = strtolower(trim((string) ($item['wp_post_type'] ?? '')));
    $status = strtolower(trim((string) ($item['status'] ?? '')));
    if ($isTerm) {
        $termCount++;
        continue;
    }
    $byCtAll[$contentType !== '' ? $contentType : '(empty)'] = ($byCtAll[$contentType !== '' ? $contentType : '(empty)'] ?? 0) + 1;
    $byWptAll[$wpPostType !== '' ? $wpPostType : '(empty)'] = ($byWptAll[$wpPostType !== '' ? $wpPostType : '(empty)'] ?? 0) + 1;
    $byStatusAll[$status !== '' ? $status : '(empty)'] = ($byStatusAll[$status !== '' ? $status : '(empty)'] ?? 0) + 1;
    if ($contentType !== 'post') {
        continue;
    }
    $v3Posts[(int) $wpId] = $item;
    $byStatusPost[$status !== '' ? $status : '(empty)'] = ($byStatusPost[$status !== '' ? $status : '(empty)'] ?? 0) + 1;
    $byWptPost[$wpPostType !== '' ? $wpPostType : '(empty)'] = ($byWptPost[$wpPostType !== '' ? $wpPostType : '(empty)'] ?? 0) + 1;
}

$wpPostIds = array_map('intval', array_keys($v3Posts));
sort($wpPostIds);
$report['1_wp_v3_posts'] = [
    'policy' => 'V3 /sync/v3/records full; non-term; content_type=post; statuses publish|draft|pending|private|future',
    'WP_POST_IDS_count' => count($wpPostIds),
    'WP_POST_IDS' => $wpPostIds,
    'by_status' => $byStatusPost,
    'by_wp_post_type' => $byWptPost,
    'by_content_type' => ['post' => count($wpPostIds)],
    'all_content_non_term' => [
        'by_status' => $byStatusAll,
        'by_wp_post_type' => $byWptAll,
        'by_content_type' => $byCtAll,
        'terms_skipped' => $termCount,
    ],
];
echo '1) WP_POST_IDS='.count($wpPostIds).PHP_EOL;

// ---------------------------------------------------------------------------
// 2) LARAVEL_WP_POST_IDS
// ---------------------------------------------------------------------------
$laravelRows = $db->table('articles as a')
    ->leftJoin('wordpress_article_links as wal', 'wal.article_id', '=', 'a.id')
    ->leftJoin('article_meta as ct', function ($join): void {
        $join->on('ct.article_id', '=', 'a.id')->where('ct.meta_key', '=', 'content_type');
    })
    ->leftJoin('article_meta as wpt', function ($join): void {
        $join->on('wpt.article_id', '=', 'a.id')->where('wpt.meta_key', '=', 'wp_post_type');
    })
    ->leftJoin('article_meta as wit', function ($join): void {
        $join->on('wit.article_id', '=', 'a.id')->where('wit.meta_key', '=', 'wp_is_term');
    })
    ->where('a.site_id', SITE_ID)
    ->whereNull('a.deleted_at')
    ->where(function ($query): void {
        $query->whereNull('wit.meta_value')
            ->orWhereRaw("LOWER(TRIM(wit.meta_value)) NOT IN ('1','true','yes')");
    })
    ->where(function ($query): void {
        $query->where('ct.meta_value', 'post')
            ->orWhere(function ($legacy): void {
                $legacy->where(function ($missing): void {
                    $missing->whereNull('ct.meta_value')->orWhereRaw("TRIM(ct.meta_value) = ''");
                })->where('wpt.meta_value', 'post');
            });
    })
    ->select([
        'a.id as article_id',
        'a.title',
        'a.status',
        'a.language',
        'a.review_status',
        'wal.wp_post_id',
        'ct.meta_value as content_type',
        'wpt.meta_value as wp_post_type',
    ])
    ->get();

$laravelByWp = [];
$duplicateWp = [];
foreach ($laravelRows as $row) {
    $wpId = (int) ($row->wp_post_id ?? 0);
    if ($wpId <= 0) {
        continue;
    }
    if (isset($laravelByWp[$wpId])) {
        $duplicateWp[$wpId] = $duplicateWp[$wpId] ?? [(int) $laravelByWp[$wpId]['article_id']];
        $duplicateWp[$wpId][] = (int) $row->article_id;
    }
    $laravelByWp[$wpId] = [
        'article_id' => (int) $row->article_id,
        'title' => (string) $row->title,
        'status' => (string) $row->status,
        'language' => (string) ($row->language ?? ''),
        'review_status' => (string) ($row->review_status ?? ''),
        'content_type' => (string) ($row->content_type ?? ''),
        'wp_post_type' => (string) ($row->wp_post_type ?? ''),
    ];
}
$laravelWpIds = array_map('intval', array_keys($laravelByWp));
sort($laravelWpIds);
$report['2_laravel_posts'] = [
    'policy' => 'site_id=3; non-term; content_type=post (legacy: missing content_type + wp_post_type=post); not soft-deleted; ALL languages; identity=wp_post_id',
    'LARAVEL_WP_POST_IDS_count' => count($laravelWpIds),
    'LARAVEL_WP_POST_IDS' => $laravelWpIds,
    'duplicate_wp_post_ids' => $duplicateWp,
    'rows_without_wp_link' => $laravelRows->filter(static fn ($row): bool => (int) ($row->wp_post_id ?? 0) <= 0)->count(),
];
echo '2) LARAVEL_WP_POST_IDS='.count($laravelWpIds).PHP_EOL;

// ---------------------------------------------------------------------------
// 3) Diff
// ---------------------------------------------------------------------------
$wpSet = array_fill_keys($wpPostIds, true);
$laravelSet = array_fill_keys($laravelWpIds, true);
$missing = array_values(array_filter($wpPostIds, static fn (int $id): bool => ! isset($laravelSet[$id])));
$extra = array_values(array_filter($laravelWpIds, static fn (int $id): bool => ! isset($wpSet[$id])));
sort($missing);
sort($extra);
$report['3_diff'] = [
    'missing_in_laravel_count' => count($missing),
    'extra_in_laravel_count' => count($extra),
    'missing_in_laravel' => $missing,
    'extra_in_laravel' => $extra,
];
echo '3) missing='.count($missing).' extra='.count($extra).PHP_EOL;

// All WP links for site (any type / soft-deleted)
$allLinks = $db->table('wordpress_article_links as wal')
    ->join('articles as a', 'a.id', '=', 'wal.article_id')
    ->where('a.site_id', SITE_ID)
    ->where('wal.wp_post_id', '>', 0)
    ->select([
        'a.id as article_id',
        'a.deleted_at',
        'a.status',
        'a.review_status',
        'a.title',
        'wal.wp_post_id',
    ])
    ->get();
$allByWp = [];
foreach ($allLinks as $row) {
    $allByWp[(int) $row->wp_post_id][] = $row;
}

$loadMetas = static function (array $articleIds) use ($db): array {
    if ($articleIds === []) {
        return [];
    }
    $rows = $db->table('article_meta')
        ->whereIn('article_id', $articleIds)
        ->whereIn('meta_key', ['content_type', 'wp_post_type', 'wp_is_term', 'skip_seo_audit'])
        ->get(['article_id', 'meta_key', 'meta_value']);
    $map = [];
    foreach ($rows as $row) {
        $map[(int) $row->article_id][(string) $row->meta_key] = (string) $row->meta_value;
    }

    return $map;
};

$postsUiEligible = static function (int $articleId) use ($db): array {
    $query = SeoArticle::query()->whereKey($articleId)->where('site_id', SITE_ID);
    ArticleResource::applyContentTabScope($query, ListArticles::TAB_POSTS);
    ArticleResource::applyExcludeSkipSeoAuditScope($query);
    ArticleResource::applyWpSyncQueueUnreviewedScope($query);
    ArticleResource::applyPostTypeFilterScope($query, 'post');
    $eligible = $query->exists();

    $article = SeoArticle::withTrashed()->whereKey($articleId)->first();
    $reasons = [];
    if ($article === null) {
        return ['eligible' => false, 'reasons' => ['not_found']];
    }
    if ($article->trashed()) {
        $reasons[] = 'soft_deleted';
    }
    $meta = $db->table('article_meta')
        ->where('article_id', $articleId)
        ->whereIn('meta_key', ['content_type', 'wp_post_type', 'wp_is_term', 'skip_seo_audit'])
        ->pluck('meta_value', 'meta_key');
    if (in_array(strtolower(trim((string) ($meta['wp_is_term'] ?? ''))), ['1', 'true', 'yes'], true)) {
        $reasons[] = 'is_term';
    }
    $contentType = strtolower(trim((string) ($meta['content_type'] ?? '')));
    $wpPostType = strtolower(trim((string) ($meta['wp_post_type'] ?? '')));
    if ($contentType !== 'post' && ! ($contentType === '' && $wpPostType === 'post')) {
        $reasons[] = 'wrong_content_type:'.($contentType !== '' ? $contentType : 'empty').'/wpt:'.($wpPostType !== '' ? $wpPostType : 'empty');
    }
    if (in_array(strtolower(trim((string) ($meta['skip_seo_audit'] ?? ''))), ['1', 'true', 'yes'], true)) {
        $reasons[] = 'skip_seo_audit';
    }
    $reviewStatus = strtolower(trim((string) ($article->review_status ?? '')));
    if (in_array($reviewStatus, [ArticleReviewStatus::Approved->value, ArticleReviewStatus::Archived->value], true)) {
        $reasons[] = 'review_status_'.$reviewStatus;
    }

    return [
        'eligible' => $eligible,
        'reasons' => $reasons,
        'review_status' => $reviewStatus,
        'status' => (string) $article->status,
    ];
};

// ---------------------------------------------------------------------------
// 4-7 Classify missing + focus split
// ---------------------------------------------------------------------------
$categories = [
    'A_v3_does_not_emit' => [],
    'B_v3_emits_importer_never_creates' => [],
    'C_exists_wrong_content_type' => [],
    'D_exists_posts_query_excludes' => [],
    'E_soft_deleted_or_stale' => [],
    'F_duplicate_or_wrong_mapping' => [],
    'G_other' => [],
];
$focusWith = [];
$focusWithout = [];
$statusOfMissing = [];
$missingDetails = [];

foreach ($missing as $wpId) {
    $v3Item = $v3Posts[$wpId] ?? null;
    $emitted = $v3Item !== null;
    $focus = $emitted ? $extractFocus($v3Item) : [];
    $hasFocus = $focus !== [];
    if ($hasFocus) {
        $focusWith[] = $wpId;
    } else {
        $focusWithout[] = $wpId;
    }
    $statusKey = $emitted ? strtolower(trim((string) ($v3Item['status'] ?? ''))) : '(unknown)';
    if ($statusKey === '') {
        $statusKey = '(empty)';
    }
    $statusOfMissing[$statusKey] = ($statusOfMissing[$statusKey] ?? 0) + 1;

    $hits = $allByWp[$wpId] ?? [];
    $activeHits = array_values(array_filter($hits, static fn ($row): bool => $row->deleted_at === null));
    $deletedHits = array_values(array_filter($hits, static fn ($row): bool => $row->deleted_at !== null));
    $metaMap = $loadMetas(array_map(static fn ($row): int => (int) $row->article_id, $hits));

    $wrongTypeHits = [];
    $uiExcludedHits = [];
    foreach ($activeHits as $hit) {
        $articleId = (int) $hit->article_id;
        $meta = $metaMap[$articleId] ?? [];
        $contentType = strtolower(trim((string) ($meta['content_type'] ?? '')));
        $wpPostType = strtolower(trim((string) ($meta['wp_post_type'] ?? '')));
        $isTerm = in_array(strtolower(trim((string) ($meta['wp_is_term'] ?? ''))), ['1', 'true', 'yes'], true);
        $isPost = (! $isTerm) && ($contentType === 'post' || ($contentType === '' && $wpPostType === 'post'));
        if (! $isPost) {
            $wrongTypeHits[] = [
                'article_id' => $articleId,
                'content_type' => $contentType,
                'wp_post_type' => $wpPostType,
                'wp_is_term' => $isTerm,
                'review_status' => (string) ($hit->review_status ?? ''),
            ];
            continue;
        }
        $eligibility = $postsUiEligible($articleId);
        if (! $eligibility['eligible']) {
            $uiExcludedHits[] = [
                'article_id' => $articleId,
                'reasons' => $eligibility['reasons'],
                'review_status' => $eligibility['review_status'] ?? null,
                'status' => $eligibility['status'] ?? null,
            ];
        }
    }

    if (! $emitted) {
        $category = 'A_v3_does_not_emit';
    } elseif ($deletedHits !== [] && $activeHits === []) {
        $category = 'E_soft_deleted_or_stale';
    } elseif ($wrongTypeHits !== [] && $uiExcludedHits === [] && count($activeHits) === count($wrongTypeHits)) {
        $category = 'C_exists_wrong_content_type';
    } elseif ($uiExcludedHits !== [] && $wrongTypeHits === []) {
        $category = 'D_exists_posts_query_excludes';
    } elseif (isset($duplicateWp[$wpId]) || count($activeHits) > 1) {
        $category = 'F_duplicate_or_wrong_mapping';
    } elseif ($activeHits === [] && $deletedHits === []) {
        $category = 'B_v3_emits_importer_never_creates';
    } elseif ($wrongTypeHits !== []) {
        $category = 'C_exists_wrong_content_type';
    } else {
        $category = 'G_other';
    }
    $categories[$category][] = $wpId;

    $missingDetails[] = [
        'wp_id' => $wpId,
        'title' => $emitted ? (string) ($v3Item['title'] ?? '') : null,
        'status' => $emitted ? (string) ($v3Item['status'] ?? '') : null,
        'wp_post_type' => $emitted ? (string) ($v3Item['wp_post_type'] ?? '') : null,
        'v3_content_type' => $emitted ? (string) ($v3Item['content_type'] ?? '') : null,
        'permalink' => $emitted ? (string) ($v3Item['permalink'] ?? '') : null,
        'modified_gmt' => $emitted ? (string) ($v3Item['modified_gmt'] ?? '') : null,
        'provider' => $emitted ? (string) ($v3Item['seo']['provider'] ?? '') : null,
        'raw_focus_keywords' => $focus,
        'has_provider_focus' => $hasFocus,
        'v3_emits_record' => $emitted,
        'laravel_hits' => array_map(static function ($row) use ($metaMap): array {
            $articleId = (int) $row->article_id;
            $meta = $metaMap[$articleId] ?? [];

            return [
                'article_id' => $articleId,
                'deleted_at' => $row->deleted_at,
                'status' => (string) $row->status,
                'review_status' => (string) ($row->review_status ?? ''),
                'title' => mb_substr((string) $row->title, 0, 80),
                'content_type' => $meta['content_type'] ?? null,
                'wp_post_type' => $meta['wp_post_type'] ?? null,
                'wp_is_term' => $meta['wp_is_term'] ?? null,
            ];
        }, $hits),
        'wrong_type_hits' => $wrongTypeHits,
        'posts_ui_excluded_hits' => $uiExcludedHits,
        'category' => $category,
    ];
}

$report['4_classification'] = [
    'counts' => array_map('count', $categories),
    'ids_by_category' => $categories,
];
$report['5_type_misclassification'] = array_values(array_filter(
    $missingDetails,
    static fn (array $detail): bool => $detail['wrong_type_hits'] !== []
));
$report['6_status_filtering'] = [
    'v3_statuses_of_missing' => $statusOfMissing,
    'posts_ui_excludes' => [
        'soft_deleted' => true,
        'terms' => true,
        'skip_seo_audit' => true,
        'review_status_approved' => true,
        'review_status_archived' => true,
        'draft' => false,
        'future' => false,
        'pending' => false,
        'private' => false,
        'trash' => 'not in V3 full inventory',
        'note' => 'Posts tab uses applyContentTabScope(non-term)+applyExcludeSkipSeoAuditScope+applyWpSyncQueueUnreviewedScope(not approved/archived)+post_type=post. Default language=vi is cleared when user selects all languages.',
    ],
];
$report['7_focus_split'] = [
    'with_focus_count' => count($focusWith),
    'without_focus_count' => count($focusWithout),
    'with_focus_ids' => $focusWith,
    'without_focus_ids' => $focusWithout,
    'expected' => ['with' => 14, 'without' => 32],
];
echo '4) classification='.json_encode(array_map('count', $categories)).PHP_EOL;
echo '7) focus with='.count($focusWith).' without='.count($focusWithout).PHP_EOL;

// ---------------------------------------------------------------------------
// 8) COMMON focus comparison
// ---------------------------------------------------------------------------
$common = array_values(array_filter($wpPostIds, static fn (int $id): bool => isset($laravelSet[$id])));
sort($common);
$commonArticleIds = [];
foreach ($common as $wpId) {
    $commonArticleIds[] = (int) $laravelByWp[$wpId]['article_id'];
}

$providerArticleIds = [];
if ($commonArticleIds !== []) {
    $providerRows = $db->table('keyword_meta as km')
        ->join('keywords as k', 'k.id', '=', 'km.keyword_id')
        ->where('km.meta_key', KeywordMetaKey::MainArticleId->value)
        ->whereIn('km.meta_value', array_map('strval', $commonArticleIds))
        ->where('k.source', SiteSyncSchema::SOURCE_PROVIDER)
        ->whereNotNull('k.phrase')
        ->whereRaw("TRIM(k.phrase) <> ''")
        ->get(['km.meta_value as article_id']);
    foreach ($providerRows as $row) {
        $providerArticleIds[(int) $row->article_id] = true;
    }
}

$effectiveArticleIds = [];
if ($commonArticleIds !== []) {
    $effectiveIds = $focusQuery->applyHasEffectiveFocusScope(
        SeoArticle::query()->whereIn('articles.id', $commonArticleIds)
    )->pluck('articles.id');
    foreach ($effectiveIds as $id) {
        $effectiveArticleIds[(int) $id] = true;
    }
}

$wpFocusCommon = [];
$laravelProviderCommon = [];
$laravelEffectiveCommon = [];
$mismatches = [];
foreach ($common as $wpId) {
    $phrases = $extractFocus($v3Posts[$wpId]);
    $wpHas = $phrases !== [];
    $articleId = (int) $laravelByWp[$wpId]['article_id'];
    $providerHas = isset($providerArticleIds[$articleId]);
    $effectiveHas = isset($effectiveArticleIds[$articleId]);
    if ($wpHas) {
        $wpFocusCommon[] = $wpId;
    }
    if ($providerHas) {
        $laravelProviderCommon[] = $wpId;
    }
    if ($effectiveHas) {
        $laravelEffectiveCommon[] = $wpId;
    }
    if ($wpHas !== $providerHas || $wpHas !== $effectiveHas) {
        $mismatches[] = [
            'wp_id' => $wpId,
            'article_id' => $articleId,
            'wp_focus' => $wpHas,
            'v3_focus' => $wpHas,
            'laravel_provider' => $providerHas,
            'laravel_effective' => $effectiveHas,
            'v3_phrases' => $phrases,
        ];
    }
}

$wpFocusCount = count($wpFocusCommon);
$laravelProviderCount = count($laravelProviderCommon);
$laravelEffectiveCount = count($laravelEffectiveCommon);
$verdict = match (true) {
    $wpFocusCount === $laravelEffectiveCount && $wpFocusCount === $laravelProviderCount
        => 'KEYWORD SYNC IS NOT THE PRIMARY BUG — common IDs agree; primary bug = missing article inventory',
    $wpFocusCount > $laravelProviderCount
        => 'Possible Laravel keyword importer/relation gap on common IDs — inspect mismatches',
    default => 'Mixed / effective-vs-provider nuance — inspect mismatches',
};

$report['8_common_focus'] = [
    'common_count' => count($common),
    'wp_focus_count' => $wpFocusCount,
    'v3_focus_count' => $wpFocusCount,
    'laravel_provider_focus_count' => $laravelProviderCount,
    'laravel_effective_focus_count' => $laravelEffectiveCount,
    'mismatch_count' => count($mismatches),
    'mismatching' => $mismatches,
    'verdict' => $verdict,
];
echo '8) common='.count($common)." wpFocus={$wpFocusCount} lvEff={$laravelEffectiveCount} mismatches=".count($mismatches).PHP_EOL;
echo "   {$verdict}\n";

// ---------------------------------------------------------------------------
// 10) Trace 10 missing IDs
// ---------------------------------------------------------------------------
$traces = [];
foreach (array_slice($missing, 0, 10) as $wpId) {
    $detail = null;
    foreach ($missingDetails as $row) {
        if ((int) $row['wp_id'] === $wpId) {
            $detail = $row;
            break;
        }
    }
    $probe = $client->records($site, [
        'schema' => SiteSyncV3Schema::VERSION,
        'resource' => SiteSyncV3Schema::RESOURCE_CONTENT,
        'mode' => 'full',
        'limit' => 5,
        'cursor' => ['after_id' => max(0, $wpId - 1)],
        'snapshot_at' => $snapshotAt,
        'snapshot_bounds' => [
            'content_max_id' => $contentMaxId,
            'term_max_id' => $termMaxId,
        ],
    ]);
    $probeItems = is_array($probe['records']['items'] ?? null) ? $probe['records']['items'] : [];
    $probeMatch = null;
    foreach ($probeItems as $item) {
        if ((int) ($item['wp_id'] ?? 0) === $wpId) {
            $probeMatch = [
                'wp_id' => $wpId,
                'content_type' => $item['content_type'] ?? null,
                'wp_post_type' => $item['wp_post_type'] ?? null,
                'status' => $item['status'] ?? null,
                'title' => mb_substr((string) ($item['title'] ?? ''), 0, 80),
                'focus' => $extractFocus($item),
            ];
            break;
        }
    }
    $traces[] = [
        'wp_id' => $wpId,
        'from_full_scan' => $detail === null ? null : [
            'title' => $detail['title'],
            'status' => $detail['status'],
            'wp_post_type' => $detail['wp_post_type'],
            'content_type' => $detail['v3_content_type'],
            'focus' => $detail['raw_focus_keywords'],
            'category' => $detail['category'],
            'laravel_hits' => $detail['laravel_hits'],
        ],
        'v3_probe_match' => $probeMatch,
        'v3_probe_ok' => (bool) ($probe['success'] ?? false),
        'v3_probe_message' => (string) ($probe['message'] ?? ''),
        'stage_lost' => $detail['category'] ?? 'unknown',
    ];
}
$report['10_traces'] = $traces;
$report['missing_details'] = $missingDetails;

// Posts UI count all languages
$postsUiQuery = SeoArticle::query()->where('articles.site_id', SITE_ID);
ArticleResource::applyContentTabScope($postsUiQuery, ListArticles::TAB_POSTS);
ArticleResource::applyExcludeSkipSeoAuditScope($postsUiQuery);
ArticleResource::applyWpSyncQueueUnreviewedScope($postsUiQuery);
ArticleResource::applyPostTypeFilterScope($postsUiQuery, 'post');
$postsUiCount = (int) $postsUiQuery->count();

$categoryCounts = array_map('count', $categories);
arsort($categoryCounts);
$primary = (string) array_key_first($categoryCounts);
$fix = match ($primary) {
    'B_v3_emits_importer_never_creates' => 'Re-import missing V3 content_type=post IDs for site 3 (force-full or targeted upsert). Do NOT patch keyword sync first.',
    'C_exists_wrong_content_type' => 'Repair content_type classification / re-backfill for affected wp_ids. Do NOT patch keyword sync first.',
    'D_exists_posts_query_excludes' => 'Posts UI excludes approved/archived/skip — decide whether inventory should include them. Do NOT patch keyword sync first.',
    'E_soft_deleted_or_stale' => 'Restore soft-deleted WP-backed articles or clear stale deletes, then re-import. Do NOT patch keyword sync first.',
    'A_v3_does_not_emit' => 'Fix WP V3 eligibility/export for these IDs. Do NOT patch keyword sync first.',
    default => 'Inspect G_other/F details; inventory gap precedes keyword sync.',
};

$report['11_acceptance'] = [
    'A_exact_missing_wp_ids' => $missing,
    'B_classification_counts' => array_map('count', $categories),
    'C_wrong_type_or_state' => [
        'wrong_type' => count($categories['C_exists_wrong_content_type']),
        'posts_excluded' => count($categories['D_exists_posts_query_excludes']),
        'soft_deleted' => count($categories['E_soft_deleted_or_stale']),
    ],
    'D_focus_split' => [
        'with' => count($focusWith),
        'without' => count($focusWithout),
    ],
    'E_common_keyword_verdict' => $verdict,
    'F_stage_lost_primary' => $primary,
    'G_minimal_fix_proposal' => $fix,
    'posts_ui_count_all_languages' => $postsUiCount,
];

$path = __DIR__.'/_audit_site3_inventory_gap_report.json';
file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "WROTE {$path}\n";
echo "posts_ui_count={$postsUiCount}\n";
echo "primary={$primary}\n";
echo "fix={$fix}\n";
